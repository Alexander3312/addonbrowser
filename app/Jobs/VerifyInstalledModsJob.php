<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\ModFile;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\AddonGameMapService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\CurseForgeService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\InstalledModsService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModReferenceService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModrinthService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonStats;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\CurseForgeFingerprint;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\VerificationCache;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\WingsFileReader;
use Pterodactyl\Models\Server;

/**
 * Hash-based verification of a batch of a server's files (steps 3 to 5 of
 * the cascade, see InstalledModsService).
 *
 * Dispatched by InstalledModsService::detect() (GET /installed) after the
 * batch was claimed in the set of files being verified
 * (VerificationCache::claimBatch()): at most 30 unconcluded files, without a
 * cache entry, of 50 MB at most, from smallest to largest. The payload only
 * holds the server's uuid, the game, and for each file its name, size and
 * cache field (all from Wings' listing, never from the client), plus the
 * state carried from one run to the next.
 *
 * **Flow**, one file read at a time (memory bounded to one file):
 * - a) bounded streamed read (Support\WingsFileReader) and **SHA1 only**;
 * - b) *step 3*: SHA1s looked up in the database
 *   (ModReferenceService::findFilesBySha1()) → `known`;
 * - c) *step 4*: the others in **one** Modrinth `version_files` call per run;
 *   official file with the same SHA1 and size, a detection extension →
 *   recorded (`source = hash_lookup`, official name and size) → `known`.
 *   Project missing from the database: type `mod` required and name obtained
 *   through **one** `GET /v2/projects?ids=` call (as after a download); a
 *   project of another type → `unknown`. Write refused (conflict) →
 *   `unknown`. Modrinth unreachable → nothing cached, file removed from the
 *   batch (offered again later);
 * - d) *step 5*, for the remaining ones only: **second read** (SHA1 computed
 *   again and compared to the first one: file changed in the meantime →
 *   abandoned) and CurseForge fingerprint (Support\CurseForgeFingerprint),
 *   then **one** `POST /v1/fingerprints/{gameId}` call and one
 *   `POST /v1/mods` (name, game and class of the mods found).
 *   ModReferenceService's rules, keeping only the game's mods (`gameId`) of a
 *   mods class of the game:
 *   - official file with **the same SHA1 and size** (a single mod) →
 *     recorded (`hash_lookup`) → `known`;
 *   - otherwise, **consistent name** (local normalized key = the official
 *     name's key, a single mod) → `cf_match` (CurseForge id, this server
 *     only), **nothing** in the referential: a fingerprint is never proof for
 *     writing;
 *   - otherwise → `unknown`.
 *   CurseForge unreachable → nothing cached, file removed from the batch;
 * - e) result written to the server's cache (VerificationCache::write()),
 *   then removed from the batch (Redis SREM). Abandoned file (incomplete
 *   read, size different from the listing, over 50 MB in the meantime,
 *   refused name, Wings error, API unreachable, end of the batch's life):
 *   removed from the batch **without** a result and marked as abandoned
 *   (VerificationCache::markAbandoned()): it isn't offered to a batch again
 *   for 30 min (otherwise every GET /installed would restart the same
 *   verification doomed to fail).
 *
 * **Node unreachable**: a log line, the whole rest of the batch is removed
 * from the set (SREM) and marked as abandoned, stop without a retry.
 *
 * **Addon version** read once per run, every Redis key built with it;
 * carried in the payload since the dispatch: addon updated in the meantime →
 * batch released under the previous version, stop.
 *
 * **Duration of a run** (worker killed at 60 s): each read is bounded by
 * what's left before the deadline (minus the estimated fingerprint
 * computation), READ_SECONDS at most, MIN_READ_SECONDS at least for the first
 * action, and lasts at most 5 s (connection) + that duration + 5 s (stalled
 * chunk). Each API call lasts at most 15 s (10 s + 5 s connection). No
 * operation starts after the deadline (except the first one of a run): only
 * one can go past it. Worst case ≈ TIME_BUDGET (20 s) + 15 s (a call started
 * right before the deadline) ≈ 35 s, plus the server check and the writes
 * (under a second); first action: 5 + 20 + 5 s of read + ~5 s of
 * fingerprint (50 MB, 3.1 s measured) ≈ 35 s. Read cut by a run's deadline
 * (READ_TIMEOUT outside the first action): the file is picked up again on
 * the next run, not abandoned.
 *
 * **Constraints**: `low` queue; short runs (TIME_BUDGET seconds targeted,
 * worker killed at 60 s): the read and fingerprint time is **estimated
 * before reading** (READ_BYTES_PER_SECOND, FINGERPRINT_BYTES_PER_SECOND),
 * each API call is only started before the deadline, and the rest is
 * dispatched again with the state (SHA1s already computed, files waiting for
 * a fingerprint), MAX_RUNS runs and LIFETIME seconds at most for one batch.
 * **Global budget of bytes read** for the whole panel: BYTES_PER_MINUTE
 * (RateLimiter, key BYTE_BUDGET_KEY), reserved before each read; used up
 * before any action of a run → `release(RELEASE_DELAY)` (the payload is then
 * the current state), used up during a run → the rest is dispatched again.
 * `WithoutOverlapping(<uuid>)` (one batch at a time per server,
 * `dontRelease()`, lock expired after LOCK_SECONDS if the worker is killed).
 * No `$tries`: retryUntil() (LIFETIME + RETRY_MARGIN after the batch
 * started), the worker running with `--tries=3` and every `release` counting
 * as an attempt. The real limit is LIFETIME, checked here: a job picked up
 * after the end of life releases its batch and stops (never a worker
 * failure); never a `release` whose next run would fall after the end of
 * life. Every exception is caught and logged, and releases the batch: **no
 * retry on error**.
 *
 * **Statistics** (Support\AddonStats): one metric per file leaving the batch,
 * counted when it's really removed from it (effective SREM,
 * VerificationCache::releasePendingTracked()): never twice, even after a
 * `release` or a follow-up run. `verify_sha1_db`, `verify_modrinth`,
 * `verify_cf_fingerprint` (`known` file, depending on the step that
 * concluded), `verify_cf_match`, `verify_unknown` (including a refused
 * referential write, which gives `unknown`), `verify_abandoned` (incomplete
 * read, node or API unreachable, end of the batch's life, unexpected error),
 * `verify_too_large` (over 50 MB at read time). A `release` for a used-up
 * budget counts nothing; nor does a file already cached. Batch given up
 * because the addon was updated, or because the server or detection no
 * longer exists: nothing is counted (no verification done).
 *
 * Never a write to the server; never a referential write on the sole basis
 * of a fingerprint.
 */
class VerifyInstalledModsJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'low';

    /** Target duration of a run, in seconds (~20 s, worker killed at 60 s). */
    public const TIME_BUDGET = 20;

    /**
     * Lifetime of a batch (all its runs), in seconds: the real limit, checked
     * by the job itself (clean stop, batch released).
     */
    public const LIFETIME = 600;

    /**
     * Margin added to LIFETIME for retryUntil(): the worker fails a job
     * BEFORE handle() as soon as retryUntil() is past (row in failed_jobs). A
     * job picked up late (busy queue, release or follow-up right before the
     * end of life) must be able to run in order to stop cleanly.
     */
    public const RETRY_MARGIN = 3600;

    /** Most runs for one batch (guard against a loop). */
    public const MAX_RUNS = 40;

    /** Global budget of bytes read per minute, for the whole panel. */
    public const BYTES_PER_MINUTE = 300 * 1024 * 1024;

    /**
     * RateLimiter key of the byte budget (the only place it's defined; under
     * the verification cache's key prefix).
     */
    public const BYTE_BUDGET_KEY = VerificationCache::KEY_PREFIX . 'hash-bytes';

    /** Prefix of the WithoutOverlapping lock (the only place it's defined). */
    public const LOCK_PREFIX = VerificationCache::KEY_PREFIX . 'verify-lock:';

    /** Lock expiry if the worker is killed, in seconds. */
    public const LOCK_SECONDS = 120;

    /** Delay before resuming when the byte budget is used up. */
    public const RELEASE_DELAY = 30;

    /**
     * Longest read of one file, in seconds (connection cut past it); reduced
     * to what's left before the deadline (minus the fingerprint computation),
     * never under MIN_READ_SECONDS for the first action of a run.
     */
    public const READ_SECONDS = 20;

    public const MIN_READ_SECONDS = 10;

    /** Estimated (cautious) read throughput, to know whether a read fits before the deadline. */
    public const READ_BYTES_PER_SECOND = 10 * 1024 * 1024;

    /**
     * Estimated (cautious) throughput of the fingerprint computation in PHP:
     * ~1.3 s for 20 MB measured on a real panel (~15 MB/s), rounded to
     * 10 MB/s for margin.
     */
    public const FINGERPRINT_BYTES_PER_SECOND = 10 * 1024 * 1024;

    /** Modrinth project type required to record (same guard as after a download). */
    private const MODRINTH_MOD_TYPE = 'mod';

    private const SHA1 = '/^[0-9a-f]{40}$/';

    /** Start of the batch (timestamp), carried from one run to the next. */
    public readonly int $startedAt;

    /** Context of a run (server, game, services, deadline); empty in the payload. */
    private array $ctx = [];

    /** @var array<string, array> results to write, by field; empty in the payload */
    private array $results = [];

    /** @var string[] fields removed from the batch without a result; empty in the payload */
    private array $abandoned = [];

    /** @var string[] fields removed from the batch because already cached; empty in the payload */
    private array $skipped = [];

    /**
     * @var array<string, string> statistics metric of each concluded or
     *      abandoned field, counted when it leaves the batch; empty in the payload
     */
    private array $metrics = [];

    /**
     * @param array<int, array{name: string, size: int, field: string}> $files files still to conclude
     * @param array{sha1?: array<string, string>, fp?: array<string, string>, fpdone?: array<string, array{sha1: string, fingerprint: int}>} $state
     *        by field: `sha1` = SHA1 computed, steps 3/4 to do; `fp` = step 4
     *        without a result, second read to do; `fpdone` = fingerprint
     *        computed, CurseForge call to do
     */
    public function __construct(
        public readonly string $serverUuid,
        public readonly string $game,
        public readonly array $files,
        public readonly array $state = [],
        public readonly int $run = 0,
        ?int $startedAt = null,
        public readonly ?string $version = null,
    ) {
        $this->startedAt = $startedAt ?? time();
        $this->onQueue(self::QUEUE);
    }

    /** One batch at a time per server; a duplicate job is dropped, never put back in the queue. */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->serverUuid))
                ->withPrefix(self::LOCK_PREFIX)
                ->dontRelease()
                ->expireAfter(self::LOCK_SECONDS),
        ];
    }

    /**
     * End of the attempts for the worker: LIFETIME + RETRY_MARGIN after the
     * batch started. The real limit is LIFETIME, checked in verify(): a job
     * picked up after the end of life releases its batch and stops cleanly
     * (never a worker failure, nothing in failed_jobs).
     */
    public function retryUntil(): \DateTimeInterface
    {
        return (new \DateTimeImmutable())->setTimestamp($this->startedAt + self::LIFETIME + self::RETRY_MARGIN);
    }

    public function handle(): void
    {
        try {
            $this->verify();
        } catch (\Throwable $ex) {
            // isset() / ??: never an error here, even on an incompletely
            // unserialized payload (uninitialized typed property).
            $uuid = $this->serverUuid ?? null;
            self::log('warning', 'Hash verification given up (unexpected error).', ['server' => $uuid, 'error' => $ex->getMessage()]);
            if (is_string($uuid) && is_array($this->files ?? null)) {
                $fields = array_values(array_filter(array_map(
                    fn ($file) => is_array($file) && is_string($file['field'] ?? null) ? $file['field'] : null,
                    $this->files
                )));
                $version = $this->ctx['version'] ?? ($this->version ?? null);
                // Only the files still in the batch are counted (abandoned):
                // those already removed by this run have been counted.
                $removed = VerificationCache::releasePendingTracked($uuid, $fields, $version);
                VerificationCache::markAbandoned($uuid, $fields, $version);
                $game = $this->ctx['game'] ?? (is_string($this->game ?? null) ? $this->game : null);
                if ($removed !== null && $removed !== [] && is_string($game)) {
                    AddonStats::increment($game, AddonStats::VERIFY_ABANDONED, count($removed));
                }
            }
        }
    }

    private function verify(): void
    {
        $start = microtime(true);
        $deadline = $start + static::TIME_BUDGET;
        $uuid = $this->serverUuid;

        $files = [];
        foreach ($this->files as $file) {
            if (self::isValidItem($file)) {
                $files[$file['field']] = $file;
            }
        }
        $state = $this->sanitizedState(array_keys($files));

        // Addon version read ONCE per run; every key is built with it. Addon
        // updated since the dispatch → clean stop (batch released under the
        // previous version, the new keys never touched).
        $version = VerificationCache::addonVersion();
        if ($version === null) {
            self::log('info', 'Hash verification: addon version unreadable, nothing verified.', ['server' => $uuid]);
            if ($this->version !== null) {
                VerificationCache::releasePending($uuid, array_keys($files), $this->version);
            }

            return;
        }
        if ($this->version !== null && $this->version !== $version) {
            self::log('info', 'Hash verification: addon updated since the batch started, batch given up.', ['server' => $uuid, 'from' => $this->version, 'to' => $version]);
            VerificationCache::releasePending($uuid, array_keys($files), $this->version);

            return;
        }
        $this->ctx = ['version' => $version];

        if (time() > $this->startedAt + self::LIFETIME) {
            self::log('warning', 'Hash verification: batch too old, given up.', ['server' => $uuid]);
            $this->endBatch(array_keys($files));

            return;
        }

        // Server, game and detection checked again: nothing comes from the payload as is.
        $server = Server::query()->where('uuid', $uuid)->with('egg.nest')->first();
        $gameMap = app(AddonGameMapService::class);
        $nestName = $server?->egg?->nest?->name;
        $game = is_string($nestName) ? $gameMap->resolveGameFromNestName($nestName) : null;
        if ($server === null || $game === null || $game !== strtolower($this->game) || ! app(InstalledModsService::class)->isEnabled($game)) {
            self::log('info', 'Hash verification: server gone or game without detection, batch given up.', ['server' => $uuid, 'game' => $this->game]);
            VerificationCache::releasePending($uuid, array_keys($files), $version);

            return;
        }
        VerificationCache::refreshPending($uuid, $version);

        $this->ctx = [
            'version' => $version,
            'server' => $server,
            'game' => $game,
            'folder' => $gameMap->targetFolder($game),
            'extensions' => $gameMap->installedDetectionExtensions($game),
            'platforms' => $gameMap->availablePlatforms($game),
            'reader' => app(WingsFileReader::class),
            'references' => app(ModReferenceService::class),
            'deadline' => $deadline,
        ];
        $this->results = [];
        $this->abandoned = [];
        $this->skipped = [];
        $this->metrics = [];
        // Has a read or an API call already happened in this run?
        $didWork = false;
        $counts = ['read' => 0, 'fingerprint' => 0, 'modrinth' => 0, 'curseforge' => 0];

        // a) Reads and SHA1s of the new files.
        foreach ($files as $field => $file) {
            if (isset($state['sha1'][$field]) || isset($state['fp'][$field]) || isset($state['fpdone'][$field])) {
                continue;
            }
            $has = VerificationCache::has($uuid, $field, $version);
            if ($has === null) {
                self::log('warning', 'Hash verification: cache unavailable, run stopped.', ['server' => $uuid]);
                break;
            }
            if ($has) {
                // Already verified (by another batch): nothing to redo.
                $this->skipped[] = $field;
                unset($files[$field]);
                continue;
            }
            if (! $this->mayRead($file['size'], false, $didWork)) {
                break;
            }
            if (! $this->reserveBytes($file['size'])) {
                if (! $didWork) {
                    $this->budgetExhausted(array_keys($files));

                    return;
                }
                break;
            }
            $first = ! $didWork;
            $didWork = true;
            $counts['read']++;

            $sha1 = hash_init('sha1');
            $status = $this->readFile($file, fn (string $chunk) => hash_update($sha1, $chunk), false, $first);
            if ($status === WingsFileReader::READ_UNREACHABLE) {
                $this->nodeUnreachable(array_keys($files));

                return;
            }
            if ($status === WingsFileReader::READ_OK) {
                $state['sha1'][$field] = hash_final($sha1);
            } elseif ($status === WingsFileReader::READ_TIMEOUT && ! $first) {
                // Cut by THIS run's deadline: picked up on the next one.
                break;
            } else {
                $this->abandon($files, $field, $status);
            }
        }

        // b) Step 3: SHA1s known in the database.
        if ($state['sha1'] !== []) {
            $known = $this->ctx['references']->findFilesBySha1($game, array_values($state['sha1']));
            foreach ($state['sha1'] as $field => $sha1) {
                if ($known->get($sha1)?->mod !== null) {
                    $this->conclude($files, $field, ['status' => VerificationCache::STATUS_KNOWN, 'sha1' => $sha1], AddonStats::VERIFY_SHA1_DB);
                    unset($state['sha1'][$field]);
                }
            }
        }

        // c) Step 4: one Modrinth call for the others.
        if ($state['sha1'] !== [] && ! in_array('modrinth', $this->ctx['platforms'], true)) {
            $state['fp'] += $state['sha1'];
            $state['sha1'] = [];
        }
        if ($state['sha1'] !== [] && $this->mayCall($didWork)) {
            $didWork = true;
            $counts['modrinth']++;
            $this->modrinthStep($files, $state);
        }
        $this->flush();

        // d) Step 5: second read and fingerprint, then one CurseForge call.
        foreach ($state['fp'] as $field => $sha1) {
            $file = $files[$field];
            if (! $this->mayRead($file['size'], true, $didWork)) {
                break;
            }
            if (! $this->reserveBytes($file['size'])) {
                if (! $didWork) {
                    $this->budgetExhausted(array_keys($files));

                    return;
                }
                break;
            }
            $first = ! $didWork;
            $didWork = true;
            $counts['fingerprint']++;

            $again = hash_init('sha1');
            $fingerprint = new CurseForgeFingerprint();
            $status = $this->readFile($file, function (string $chunk) use ($again, $fingerprint) {
                hash_update($again, $chunk);
                $fingerprint->add($chunk);
            }, true, $first);
            if ($status === WingsFileReader::READ_UNREACHABLE) {
                $this->flush();
                $this->nodeUnreachable(array_keys($files));

                return;
            }
            if ($status === WingsFileReader::READ_TIMEOUT && ! $first) {
                // Cut by THIS run's deadline: picked up on the next one.
                break;
            }
            unset($state['fp'][$field]);
            $value = $status === WingsFileReader::READ_OK ? $fingerprint->value() : null;
            if ($value === null || hash_final($again) !== $sha1) {
                // Incomplete read, or file changed since the first read.
                $this->abandon($files, $field, $status === WingsFileReader::READ_OK ? 'changed' : $status);
                continue;
            }
            $state['fpdone'][$field] = ['sha1' => $sha1, 'fingerprint' => $value];
        }

        if ($state['fpdone'] !== [] && $this->mayCall($didWork)) {
            $didWork = true;
            $counts['curseforge']++;
            $this->curseForgeStep($files, $state);
        }
        $this->flush();

        self::log('info', 'Hash verification: run finished.', [
            'server' => $uuid,
            'run' => $this->run,
            'counts' => $counts,
            'remaining' => count($files),
            'seconds' => round(microtime(true) - $start, 2),
        ]);

        if ($files === []) {
            return;
        }

        if ($this->run + 1 >= self::MAX_RUNS || time() >= $this->startedAt + self::LIFETIME) {
            self::log('warning', 'Hash verification: batch stopped (too many runs or too long).', ['server' => $uuid, 'remaining' => count($files)]);
            $this->endBatch(array_keys($files));

            return;
        }

        try {
            Bus::dispatch(new static($uuid, $game, array_values($files), $state, $this->run + 1, $this->startedAt, $version));
        } catch (\Throwable $ex) {
            self::log('warning', 'Hash verification: follow-up not dispatched, batch released.', ['server' => $uuid, 'error' => $ex->getMessage()]);
            $this->endBatch(array_keys($files));
        }
    }

    /**
     * Byte budget used up before any action of the run: results already
     * obtained are written, then `release(RELEASE_DELAY)` (the payload is then
     * the current state). Never past the batch's end of life: if the next run
     * would fall after LIFETIME, the batch is released and the job stops.
     */
    private function budgetExhausted(array $remainingFields): void
    {
        $this->flush();
        if (time() + self::RELEASE_DELAY >= $this->startedAt + self::LIFETIME) {
            self::log('warning', 'Hash verification: byte budget used up at the end of the batch\'s life, batch stopped.', ['server' => $this->serverUuid]);
            $this->endBatch($remainingFields);

            return;
        }
        self::log('info', 'Hash verification: byte budget used up, resuming later.', ['server' => $this->serverUuid]);
        $this->release(self::RELEASE_DELAY);
    }

    /**
     * End of the batch without a result for these files (end of life,
     * MAX_RUNS, follow-up not dispatched…): removed from the batch and marked
     * as abandoned (not offered again for 30 min, otherwise the next batch
     * would hit the same ones).
     *
     * @param string[] $fields
     */
    private function endBatch(array $fields): void
    {
        $removed = VerificationCache::releasePendingTracked($this->serverUuid, $fields, $this->ctx['version'] ?? null);
        VerificationCache::markAbandoned($this->serverUuid, $fields, $this->ctx['version'] ?? null);
        // Statistics: `verify_abandoned` for each file really removed from the batch.
        $this->countReleased($removed, []);
    }

    /**
     * Step 4 (Modrinth) for the files of `$state['sha1']`: one `version_files`
     * call, at most one `projects` call.
     */
    private function modrinthStep(array &$files, array &$state): void
    {
        $game = $this->ctx['game'];
        $modrinth = app(ModrinthService::class);
        $ok = false;
        $versions = $modrinth->getVersionsByHashes(array_values($state['sha1']), $ok);
        if (! $ok) {
            // Modrinth unreachable: nothing cached, files offered again later.
            foreach (array_keys($state['sha1']) as $field) {
                $this->abandon($files, $field, 'modrinth_unreachable');
            }
            $state['sha1'] = [];

            return;
        }

        $candidates = [];
        $missing = [];
        foreach ($state['sha1'] as $field => $sha1) {
            $version = $versions[$sha1] ?? null;
            $official = $version !== null ? ModrinthService::fileBySha1($version, $sha1) : null;
            if ($official === null || ($official['size'] ?? null) !== $files[$field]['size']
                || ! is_string($official['filename'] ?? null)
                || ! app(AddonGameMapService::class)->hasInstalledDetectionExtension($game, $official['filename'])) {
                // Unknown to Modrinth: step 5.
                $state['fp'][$field] = $sha1;
                continue;
            }
            $candidates[$field] = [$version, $official];
            if ($this->ctx['references']->findModByModrinthId($version['project_id']) === null) {
                $missing[$version['project_id']] = true;
            }
        }
        $state['sha1'] = [];

        $projects = [];
        $projectsAsked = false;
        if ($missing !== [] && microtime(true) <= $this->ctx['deadline']) {
            $projects = $modrinth->getProjectsByIds(array_keys($missing));
            $projectsAsked = true;
        }

        foreach ($candidates as $field => [$version, $official]) {
            $sha1 = strtolower($official['hashes']['sha1']);
            $projectId = $version['project_id'];
            $name = null;
            if (isset($missing[$projectId])) {
                if (! $projectsAsked) {
                    // No time left for the call: picked up on the next run.
                    $state['sha1'][$field] = $sha1;
                    continue;
                }
                $type = $projects[$projectId]['project_type'] ?? null;
                if (! is_string($type)) {
                    $this->abandon($files, $field, 'modrinth_unreachable');
                    continue;
                }
                if ($type !== self::MODRINTH_MOD_TYPE) {
                    $this->conclude($files, $field, ['status' => VerificationCache::STATUS_UNKNOWN, 'sha1' => $sha1]);
                    continue;
                }
                $name = is_string($projects[$projectId]['title'] ?? null) ? $projects[$projectId]['title'] : null;
            }

            $result = $this->ctx['references']->recordFile($game, [
                'name' => $name,
                'modrinth_project_id' => $projectId,
            ], [
                'filename' => $official['filename'],
                'size' => $official['size'],
                'sha1' => $sha1,
                'modrinth_version_id' => $version['id'],
            ], ModFile::SOURCE_HASH_LOOKUP);

            $this->conclude($files, $field, [
                'status' => $result->isRecorded() ? VerificationCache::STATUS_KNOWN : VerificationCache::STATUS_UNKNOWN,
                'sha1' => $sha1,
            ], AddonStats::VERIFY_MODRINTH);
        }
    }

    /**
     * Step 5 (CurseForge) for the files of `$state['fpdone']`: one
     * `fingerprints` call, at most one `mods` call.
     */
    private function curseForgeStep(array &$files, array &$state): void
    {
        $game = $this->ctx['game'];
        $done = $state['fpdone'];

        $curseForge = app(CurseForgeService::class);
        $gameId = in_array('curseforge', $this->ctx['platforms'], true) ? $curseForge->getGameId($game) : null;
        if ($gameId === null) {
            // Game without CurseForge: no other step possible.
            foreach ($done as $field => $item) {
                $this->conclude($files, $field, ['status' => VerificationCache::STATUS_UNKNOWN, 'sha1' => $item['sha1'], 'fingerprint' => $item['fingerprint']]);
            }
            $state['fpdone'] = [];

            return;
        }

        $ok = false;
        $matches = $curseForge->getFingerprintMatches($gameId, array_values(array_column($done, 'fingerprint')), $ok);
        if (! $ok) {
            foreach (array_keys($done) as $field) {
                $this->abandon($files, $field, 'curseforge_unreachable');
            }
            $state['fpdone'] = [];

            return;
        }

        $modIds = [];
        foreach ($done as $item) {
            foreach ($matches[$item['fingerprint']] ?? [] as $match) {
                $modIds[$match['mod_id']] = true;
            }
        }
        $mods = [];
        if ($modIds !== []) {
            if (microtime(true) > $this->ctx['deadline']) {
                // No time left for the call: picked up on the next run.
                return;
            }
            $mods = $curseForge->getModsByIds(array_keys($modIds));
        }
        $state['fpdone'] = [];
        $classIds = $curseForge->modClassIds($game);
        $localKey = fn (string $name) => $this->ctx['references']->normalizeFor($game, $name)['key'] ?? null;

        foreach ($done as $field => $item) {
            $file = $files[$field];
            $entry = ['sha1' => $item['sha1'], 'fingerprint' => $item['fingerprint']];
            $candidates = $matches[$item['fingerprint']] ?? [];

            // Mod missing from the `mods` response: game and class can't be checked.
            if (array_filter($candidates, fn ($m) => ! isset($mods[$m['mod_id']])) !== []) {
                $this->abandon($files, $field, 'curseforge_unreachable');
                continue;
            }
            $valid = array_values(array_filter($candidates, fn ($m) => ($m['file']['gameId'] ?? null) === $gameId
                && ($mods[$m['mod_id']]['gameId'] ?? null) === $gameId
                && in_array($mods[$m['mod_id']]['classId'] ?? null, $classIds, true)));

            // Same SHA1 and size: enough proof to record.
            $exact = array_values(array_filter($valid, fn ($m) => CurseForgeService::sha1Of($m['file']) === $item['sha1']
                && ($m['file']['fileLength'] ?? null) === $file['size']
                && is_string($m['file']['fileName'] ?? null)
                && app(AddonGameMapService::class)->hasInstalledDetectionExtension($game, $m['file']['fileName'])));
            if (count(array_unique(array_column($exact, 'mod_id'))) === 1) {
                $match = $exact[0];
                $result = $this->ctx['references']->recordFile($game, [
                    'name' => is_string($mods[$match['mod_id']]['name'] ?? null) ? $mods[$match['mod_id']]['name'] : null,
                    'curseforge_project_id' => $match['mod_id'],
                ], [
                    'filename' => $match['file']['fileName'],
                    'size' => $file['size'],
                    'sha1' => $item['sha1'],
                    'cf_fingerprint' => $item['fingerprint'],
                    'curseforge_file_id' => $match['file']['id'],
                ], ModFile::SOURCE_HASH_LOOKUP);
                $this->conclude($files, $field, $entry + ['status' => $result->isRecorded() ? VerificationCache::STATUS_KNOWN : VerificationCache::STATUS_UNKNOWN], AddonStats::VERIFY_CF_FINGERPRINT);
                continue;
            }
            if ($exact !== []) {
                // Same file published by several projects: never a random pick.
                $this->conclude($files, $field, $entry + ['status' => VerificationCache::STATUS_UNKNOWN]);
                continue;
            }

            // Fingerprint alone: recognized on this server only if the name is consistent.
            $key = $localKey($file['name']);
            $coherent = $key === null ? [] : array_values(array_filter($valid, fn ($m) => is_string($m['file']['fileName'] ?? null) && $localKey($m['file']['fileName']) === $key));
            $coherentMods = array_values(array_unique(array_column($coherent, 'mod_id')));
            if (count($coherentMods) === 1) {
                $this->conclude($files, $field, $entry + ['status' => VerificationCache::STATUS_CF_MATCH, 'curseforge_project_id' => $coherentMods[0]]);
                continue;
            }

            $this->conclude($files, $field, $entry + ['status' => VerificationCache::STATUS_UNKNOWN]);
        }
    }

    /**
     * Read of a batch file (checked path), chunk by chunk. Duration bounded by
     * what's left before the deadline, minus the estimated fingerprint
     * computation ($withFingerprint), READ_SECONDS at most; the first action
     * of a run gets MIN_READ_SECONDS at least.
     */
    private function readFile(array $file, callable $onChunk, bool $withFingerprint, bool $first): string
    {
        $path = WingsFileReader::path($this->ctx['folder'], $file['name'], $this->ctx['extensions']);
        if ($path === null) {
            return WingsFileReader::READ_REFUSED;
        }

        return $this->ctx['reader']->read($this->ctx['server'], $path, $file['size'], $onChunk, $this->readSeconds($file['size'], $withFingerprint, $first));
    }

    /** Duration granted to a read (see readFile()). */
    private function readSeconds(int $size, bool $withFingerprint, bool $first): float
    {
        $left = $this->ctx['deadline'] - microtime(true) - ($withFingerprint ? $size / static::FINGERPRINT_BYTES_PER_SECOND : 0);

        return (float) min(static::READ_SECONDS, max($first ? static::MIN_READ_SECONDS : 1, $left));
    }

    /**
     * Does a read (and the fingerprint) fit before the deadline, per a
     * cautious estimate made before reading? The first action of a run is
     * always allowed: otherwise a large file would never be read, and every
     * run makes at least one step (no loop of follow-ups without progress).
     */
    private function mayRead(int $size, bool $withFingerprint, bool $didWork): bool
    {
        if (! $didWork) {
            return true;
        }
        $estimate = $size / static::READ_BYTES_PER_SECOND + ($withFingerprint ? $size / static::FINGERPRINT_BYTES_PER_SECOND : 0);

        return microtime(true) + $estimate <= $this->ctx['deadline'];
    }

    /** Can an API call be started (before the deadline, or first action of the run)? */
    private function mayCall(bool $didWork): bool
    {
        return ! $didWork || microtime(true) <= $this->ctx['deadline'];
    }

    /**
     * Reserves bytes in the global budget (RateLimiter, 60 s window). False
     * if the budget isn't enough, or if the cache is unavailable (cautious:
     * no read).
     */
    private function reserveBytes(int $bytes): bool
    {
        try {
            if (RateLimiter::attempts(self::BYTE_BUDGET_KEY) + $bytes > self::BYTES_PER_MINUTE) {
                return false;
            }
            RateLimiter::increment(self::BYTE_BUDGET_KEY, 60, max(1, $bytes));

            return true;
        } catch (\Throwable $ex) {
            self::log('warning', 'Hash verification: byte budget unreadable, read postponed.', ['error' => $ex->getMessage()]);

            return false;
        }
    }

    /**
     * Concluded file: result to write, then removed from the batch.
     * Statistics metric: `cf_match` → `verify_cf_match`, `unknown` →
     * `verify_unknown`, `known` → `$knownMetric` (the step that concluded:
     * `verify_sha1_db`, `verify_modrinth` or `verify_cf_fingerprint`).
     */
    private function conclude(array &$files, string $field, array $entry, string $knownMetric = AddonStats::VERIFY_UNKNOWN): void
    {
        $this->results[$field] = $entry;
        $this->metrics[$field] = match ($entry['status'] ?? null) {
            VerificationCache::STATUS_KNOWN => $knownMetric,
            VerificationCache::STATUS_CF_MATCH => AddonStats::VERIFY_CF_MATCH,
            default => AddonStats::VERIFY_UNKNOWN,
        };
        unset($files[$field]);
    }

    /**
     * Abandoned file: removed from the batch without a result (offered again
     * later). Statistics: `verify_too_large` if it's over 50 MB at read time,
     * otherwise `verify_abandoned`.
     */
    private function abandon(array &$files, string $field, string $reason): void
    {
        self::log('debug', 'Hash verification: file abandoned.', ['server' => $this->serverUuid, 'file' => $files[$field]['name'] ?? null, 'reason' => $reason]);
        $this->abandoned[] = $field;
        $this->metrics[$field] = $reason === WingsFileReader::READ_TOO_LARGE ? AddonStats::VERIFY_TOO_LARGE : AddonStats::VERIFY_ABANDONED;
        unset($files[$field]);
    }

    /**
     * Writes the pending results to the cache, then removes those files from
     * the batch (Redis SREM), and counts one metric per concluded or abandoned
     * file **really** removed from the batch at that moment: a file is
     * therefore only counted once, even if a run is replayed (`release` for a
     * used-up budget: the replayed payload is the original one) or a
     * follow-up removes it again. Files already cached (`skipped`) don't
     * count: nothing was verified.
     */
    private function flush(): void
    {
        $version = $this->ctx['version'] ?? null;
        foreach ($this->results as $field => $entry) {
            VerificationCache::write($this->serverUuid, $field, $entry, $version);
        }
        $removed = VerificationCache::releasePendingTracked($this->serverUuid, array_merge(array_keys($this->results), $this->abandoned, $this->skipped), $version);
        // Abandoned without a result: not offered to a batch again for 30 min.
        VerificationCache::markAbandoned($this->serverUuid, $this->abandoned, $version);
        $counted = array_flip(array_merge(array_keys($this->results), $this->abandoned));
        $this->countReleased($removed === null ? null : array_values(array_filter($removed, fn (string $field) => isset($counted[$field]))), $this->metrics);
        $this->results = [];
        $this->abandoned = [];
        $this->skipped = [];
        $this->metrics = [];
    }

    /**
     * Statistics of the files removed from the batch: one metric per field
     * (`$metrics`, `verify_abandoned` by default), grouped into one call.
     * `$removed` null (Redis unavailable): nothing is counted (never twice).
     * Never throws (AddonStats).
     *
     * @param string[]|null $removed
     * @param array<string, string> $metrics
     */
    private function countReleased(?array $removed, array $metrics): void
    {
        if ($removed === null || $removed === []) {
            return;
        }
        $counts = [];
        foreach ($removed as $field) {
            $metric = $metrics[$field] ?? AddonStats::VERIFY_ABANDONED;
            $counts[$metric] = ($counts[$metric] ?? 0) + 1;
        }
        AddonStats::incrementMany($this->ctx['game'] ?? strtolower($this->game), $counts);
    }

    /**
     * Node unreachable: results already obtained are written, the whole rest
     * is removed from the batch and marked as abandoned (cautious choice: no
     * new batch on this server for 30 min), stop without a retry.
     */
    private function nodeUnreachable(array $remainingFields): void
    {
        self::log('warning', 'Hash verification: node unreachable, batch stopped.', ['server' => $this->serverUuid]);
        $this->abandoned = array_merge($this->abandoned, $remainingFields);
        $this->flush();
    }

    /**
     * Carried state, checked (the payload went through Redis) and limited to
     * the batch's files.
     *
     * @param string[] $fields
     */
    private function sanitizedState(array $fields): array
    {
        $present = array_flip($fields);
        $state = ['sha1' => [], 'fp' => [], 'fpdone' => []];
        foreach (['sha1', 'fp'] as $kind) {
            foreach (is_array($this->state[$kind] ?? null) ? $this->state[$kind] : [] as $field => $sha1) {
                if (isset($present[$field]) && is_string($sha1) && preg_match(self::SHA1, $sha1) === 1) {
                    $state[$kind][(string) $field] = $sha1;
                }
            }
        }
        foreach (is_array($this->state['fpdone'] ?? null) ? $this->state['fpdone'] : [] as $field => $item) {
            if (isset($present[$field]) && is_array($item) && is_string($item['sha1'] ?? null) && preg_match(self::SHA1, $item['sha1']) === 1
                && is_int($item['fingerprint'] ?? null) && $item['fingerprint'] >= 0 && $item['fingerprint'] <= 4294967295) {
                $state['fpdone'][(string) $field] = ['sha1' => $item['sha1'], 'fingerprint' => $item['fingerprint']];
            }
        }
        // A file is only in one step at a time (the most advanced one).
        $state['fp'] = array_diff_key($state['fp'], $state['fpdone']);
        $state['sha1'] = array_diff_key($state['sha1'], $state['fp'], $state['fpdone']);

        return $state;
    }

    /**
     * Well-formed payload item (the payload went through Redis): name, size
     * of 50 MB at most, and a cache field matching that name and size.
     */
    private static function isValidItem(mixed $file): bool
    {
        return is_array($file)
            && is_string($file['name'] ?? null) && $file['name'] !== ''
            && is_int($file['size'] ?? null) && $file['size'] >= 0 && $file['size'] <= WingsFileReader::MAX_BYTES
            && is_string($file['field'] ?? null)
            && str_starts_with($file['field'], $file['name'] . '|' . $file['size'] . '|');
    }

    /** Addon log that never throws. */
    private static function log(string $level, string $message, array $context = []): void
    {
        try {
            AddonBrowserLogger::{$level}($message, $context);
        } catch (\Throwable $ex) {
            // Nothing more to do.
        }
    }
}
