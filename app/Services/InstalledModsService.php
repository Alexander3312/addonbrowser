<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use GuzzleHttp\Exception\RequestException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Illuminate\Support\Facades\Bus;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Jobs\VerifyInstalledModsJob;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\Mod;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\ModFile;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\CollisionJournal;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\VerificationCache;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\WingsFileReader;

/**
 * Detection of the mods installed on a server (GET /installed), steps 1 and 2
 * of the cascade, and starting the hash-based verification (steps 3 to 5,
 * Jobs\VerifyInstalledModsJob):
 * 1. **normalized name**: one query on `addonbrowser_mod_files` for the whole
 *    folder; a key matching the files of **a single** mod → installed
 *    (`matched_by: name`). Several mods → no conclusion (never a random
 *    pick), `collision` line in the journal;
 * 2. **already verified**: read of the server's cache
 *    (Support\VerificationCache), `known` (identity looked up again in the
 *    referential by SHA1) and `cf_match` → installed (`matched_by: cache`),
 *    `unknown` → not installed;
 * 6. **verification batch**: if a batch is already running for this server
 *    (non-empty Redis set of files being verified), nothing is started and
 *    `pending_verification` = its size. Otherwise, the files not concluded,
 *    without a cache entry, with a valid field and 50 MB at most, neither
 *    recently abandoned by a batch (30 min) nor with a name the reader would
 *    refuse, from smallest to largest, 30 at most: atomic claim
 *    (VerificationCache::claimBatch()) then dispatch of **one** job (`low`
 *    queue), inside a `try` (failure → claim released, response unchanged).
 *    The response doesn't wait for the job.
 *
 * The badge always comes from the folder's actual listing, recomputed on every
 * call: a deleted file disappears with nothing to sync.
 *
 * `unverified_overflow`: files not concluded, without a cache entry, left out
 * of the batch (picked up on later loads), plus those that can't be verified
 * for lack of a field (unreadable size or date), with a refused name,
 * recently abandoned, or past MAX_FILES. A file over 50 MB is never verified
 * nor counted.
 *
 * Step 2, `unknown` entry: its SHA1 (already computed) is added to the query
 * for the `known` ones; if it has reached the referential in the meantime, the
 * file is recognized (`matched_by: cache`). That's step 3 redone on data
 * already computed: no extra query, no re-read.
 *
 * Steps 1 and 2 themselves live in classify(), with no side effect at all,
 * shared with the admin screen (Services\AdminOverviewService, read-only
 * current-state computation); the listing filter lives in filterEntries().
 * detect() keeps its side effects: collision journal, cache cleanup (HDEL,
 * EXPIRE), verification batch. No statistic is counted here (it would count
 * reloads).
 *
 * The addon version is read once per request; every Redis key is built with
 * it, and it's carried in the job's payload.
 *
 * During the request: one listing (two if the folder looks missing), one SQL
 * query per KEY_CHUNK keys (a single one in practice), one more for the
 * cache's `known` entries, a few Redis commands. No file read, no database
 * write, at most one dispatch. The only Redis writes: cleanup of the server's
 * cache (HDEL, EXPIRE), the batch claim, and the deduplication key of the
 * `collision` lines (one per day per server and per key).
 */
class InstalledModsService
{
    /**
     * Most files (in name order) that go through step 2: the bound of a
     * server's cache (2,000 fields, VerificationCache::MAX_FIELDS). Step 1
     * applies to every file; past this bound, a file it doesn't conclude
     * stays unconcluded and counts in `unverified_overflow`.
     */
    public const MAX_FILES = 2000;

    /** Normalized keys per SQL query (size of the IN). */
    public const KEY_CHUNK = 500;

    public const MATCHED_BY_NAME = 'name';
    public const MATCHED_BY_CACHE = 'cache';

    // File classes returned by classify().
    /** Recognized by name (step 1). */
    public const CLASS_NAME = 'name';
    /** Recognized by the server's cache (step 2). */
    public const CLASS_CACHE = 'cache';
    /** Usable cache entry, without a mod: verified, unknown. */
    public const CLASS_UNKNOWN = 'unknown';
    /** No usable cache entry: never verified. */
    public const CLASS_UNVERIFIED = 'unverified';

    public function __construct(
        private AddonGameMapService $gameMapService,
        private ModReferenceService $references,
        private DaemonFileRepository $fileRepository
    ) {
    }

    /**
     * Is detection enabled for this game? `installed_detection` in
     * gameConfig.json, with extensions and a known normalizer (without a
     * normalizer, no key can be computed: never a fallback).
     */
    public function isEnabled(string $game): bool
    {
        return $this->references->acceptsGame($game)
            && $this->gameMapService->installedDetectionExtensions($game) !== [];
    }

    /**
     * GET /installed response for a game with detection enabled
     * (isEnabled()).
     *
     * @throws DaemonConnectionException node unreachable (or a Wings error
     *                                   that isn't a missing folder)
     * @throws \TypeError Wings response that isn't JSON (getDirectory() is
     *                    typed `array`); the caller catches everything
     */
    public function detect(Server $server, string $game): array
    {
        $game = strtolower($game);
        $files = $this->listFiles($server, $game);
        if ($files === null) {
            return ['enabled' => true, 'compatible' => false];
        }

        $overflow = 0;

        // Step 2: the server's verification cache. Always read, even if
        // everything is concluded by step 1: the cleanup (HDEL, EXPIRE)
        // happens on every call. Fields of ALL the files of the filtered
        // listing: the HDEL never deletes the field of a present file, even
        // past MAX_FILES.
        $presentFields = array_values(array_filter(array_map(
            fn (array $file) => VerificationCache::field($file['name'], $file['size'], $file['modified']),
            $files
        ), 'is_string'));
        // Addon version read ONCE for the whole request: every key (cache,
        // batch, abandoned files) is built with it.
        $version = VerificationCache::addonVersion();
        // (Unreadable version: readAndPrune() reads it again, finds no key,
        // logs and returns null.)
        $cache = VerificationCache::readAndPrune($server->uuid, $presentFields, $version) ?? [];

        // Steps 1 and 2, with no side effect (classify()).
        $classified = $this->classify($game, $files, $cache);

        // Files recently abandoned by a batch (incomplete read, API
        // unreachable…): not offered again before ABANDONED_TTL.
        $abandoned = array_flip($version !== null ? (VerificationCache::abandonedMembers($server->uuid, $version) ?? []) : []);
        $folder = $this->gameMapService->targetFolder($game);
        $extensions = $this->gameMapService->installedDetectionExtensions($game);

        $installed = [];
        $collisions = 0;
        $toVerify = [];
        foreach ($classified as $item) {
            $file = $item['file'];
            if ($item['collision'] !== null) {
                // Step 1 without a conclusion (several mods): journaled, even
                // if step 2 concludes afterwards.
                $collisions++;
                $this->journalCollision($server, $game, $file, $item['collision']);
            }

            if ($item['class'] === self::CLASS_NAME) {
                $installed[] = $this->entry($item['mod'], $file, self::MATCHED_BY_NAME);
                continue;
            }
            if ($item['class'] === self::CLASS_CACHE) {
                $installed[] = $this->entry($item['mod'], $file, self::MATCHED_BY_CACHE);
                continue;
            }
            if ($item['class'] !== self::CLASS_UNVERIFIED) {
                // Verified, unknown to the APIs → not installed.
                continue;
            }

            // No usable cache entry.
            $field = $item['field'];
            if ($item['beyond']) {
                // Past the cache bound: never a step 2.
                $overflow++;
                continue;
            }
            // No field (unreadable size or date): never verified (nothing
            // could be cached), counted. Over 50 MB: never verified nor
            // counted (never read).
            if ($field === null) {
                $overflow++;
                continue;
            }
            if ($file['size'] > WingsFileReader::MAX_BYTES) {
                continue;
            }
            // Recently abandoned, or a name the reader would refuse (it would
            // never be read): counted, never put in a batch.
            if (isset($abandoned[$field]) || WingsFileReader::path($folder, $file['name'], $extensions) === null) {
                $overflow++;
                continue;
            }
            $toVerify[] = ['name' => $file['name'], 'size' => $file['size'], 'field' => $field];
        }

        usort($installed, fn (array $a, array $b) => strcmp($a['filename'], $b['filename']));

        // Step 6: hash verification batch.
        [$pending, $outside] = $version !== null ? $this->verificationBatch($server, $game, $toVerify, $version) : [0, count($toVerify)];
        $overflow += $outside;

        $this->log('debug', 'Installed mods detected.', [
            'server' => $server->uuid,
            'game' => $game,
            'files' => count($files),
            'installed' => count($installed),
            'collisions' => $collisions,
            'pending' => $pending,
            'unverified' => $overflow,
        ]);

        return [
            'enabled' => true,
            'compatible' => true,
            'installed' => $installed,
            'pending_verification' => $pending,
            'unverified_overflow' => $overflow,
        ];
    }

    /**
     * Step 6. A batch is already running (non-empty set of files being
     * verified) → nothing is started, `pending_verification` = its size.
     * Otherwise, the files to verify, from smallest to largest, MAX_BATCH at
     * most: atomic claim (VerificationCache::claimBatch()), then dispatch of
     * **one** job, inside a `try` (failure → claim released, response
     * unchanged). The response doesn't wait for the job.
     *
     * Returns [pending_verification, files to verify left out of the batch].
     * Unreadable addon version or Redis unavailable: nothing is started, every
     * file to verify is counted as left out.
     *
     * @param array<int, array{name: string, size: int, field: string}> $toVerify
     * @return array{0: int, 1: int}
     */
    private function verificationBatch(Server $server, string $game, array $toVerify, string $version): array
    {
        $members = VerificationCache::pendingMembers($server->uuid, $version);
        if ($members === null) {
            return [0, count($toVerify)];
        }
        if ($members !== []) {
            // Batch running: its files aren't "left out".
            $inBatch = array_flip($members);
            $outside = count(array_filter($toVerify, fn (array $file) => ! isset($inBatch[$file['field']])));

            return [count($members), $outside];
        }
        if ($toVerify === []) {
            return [0, 0];
        }

        usort($toVerify, fn (array $a, array $b) => [$a['size'], $a['name']] <=> [$b['size'], $b['name']]);
        $batch = array_slice($toVerify, 0, VerificationCache::MAX_BATCH);
        $outside = count($toVerify) - count($batch);

        if (! VerificationCache::claimBatch($server->uuid, array_column($batch, 'field'), $version)) {
            // Another call just started a batch (or Redis is unavailable):
            // that batch's count, estimate for the rest.
            $count = (int) VerificationCache::pendingCount($server->uuid, $version);

            return [$count, max(0, count($toVerify) - $count)];
        }

        try {
            // The version is carried along: the job stops if the addon was
            // updated in the meantime (another version's keys).
            Bus::dispatch(new VerifyInstalledModsJob($server->uuid, $game, $batch, [], 0, null, $version));
        } catch (\Throwable $ex) {
            VerificationCache::releasePending($server->uuid, array_column($batch, 'field'), $version);
            $this->log('warning', 'Hash verification not dispatched, batch released.', ['server' => $server->uuid, 'error' => $ex->getMessage()]);

            return [0, count($toVerify)];
        }

        return [count($batch), $outside];
    }

    /**
     * Steps 1 and 2 of the cascade on an already filtered listing
     * (filterEntries()), **with no side effect at all**: no journal, no Redis
     * write, no batch. Shared by detect() (GET /installed, which adds its side
     * effects) and the admin screen (current-state computation, read-only).
     *
     * `$cache`: the server's cache entries by field, already read
     * (VerificationCache::readAndPrune() or ::read(); null = unreadable entry,
     * ignored). Queries: those of step 1 (one per KEY_CHUNK keys) and at most
     * one for the cache's SHA1s.
     *
     * Returns, in listing order, for each file:
     * - `file`: the file, with `key` (normalized key), `disabled` and `rank`;
     * - `field`: its cache field (null if size or date unreadable);
     * - `class`: CLASS_NAME (step 1), CLASS_CACHE (step 2: `known` looked up
     *   again in the referential, `cf_match`, or `unknown` whose SHA1 has
     *   reached it since), CLASS_UNKNOWN (usable entry but no mod: verified,
     *   unknown), CLASS_UNVERIFIED (no usable entry);
     * - `mod`: the mod's ids (`modrinth_project_id`, `curseforge_project_id`)
     *   for CLASS_NAME and CLASS_CACHE, null otherwise;
     * - `collision`: the mods (id, name, ids) if the key matches several mods
     *   (step 1 without a conclusion), null otherwise;
     * - `beyond`: true past MAX_FILES (never a step 2).
     *
     * @param array<int, array{name: string, size: mixed, modified: mixed}> $files
     * @param array<string, ?array> $cache
     * @return array<int, array{file: array, field: ?string, class: string, mod: ?array, collision: ?array, beyond: bool}>
     */
    public function classify(string $game, array $files, array $cache): array
    {
        $game = strtolower($game);
        $files = array_values($files);

        // Normalized key, "disabled" state and rank (name order) of each file.
        foreach ($files as $i => $file) {
            $normalized = $this->references->normalizeFor($game, $file['name']);
            $files[$i]['key'] = $normalized['key'] ?? null;
            $files[$i]['disabled'] = (bool) ($normalized['disabled'] ?? false);
            $files[$i]['rank'] = $i;
        }

        // Step 1: normalized name, for ALL the kept files.
        $modsByKey = $this->modsByKey($game, array_values(array_unique(array_filter(array_column($files, 'key'), 'is_string'))));

        $result = [];
        $knownSha1 = [];
        foreach ($files as $i => $file) {
            $mods = $file['key'] !== null ? ($modsByKey[$file['key']] ?? []) : [];
            $field = VerificationCache::field($file['name'], $file['size'], $file['modified']);
            $item = ['file' => $file, 'field' => $field, 'class' => self::CLASS_UNVERIFIED, 'mod' => null, 'collision' => null, 'beyond' => false, 'entry' => null];

            if (count($mods) === 1) {
                $mod = reset($mods);
                $item['class'] = self::CLASS_NAME;
                $item['mod'] = ['modrinth_project_id' => $mod['modrinth_project_id'], 'curseforge_project_id' => $mod['curseforge_project_id']];
                $result[$i] = $item;
                continue;
            }
            if (count($mods) > 1) {
                $item['collision'] = array_values($mods);
            }

            if ($file['rank'] >= self::MAX_FILES) {
                // Past the cache bound: never a step 2.
                $item['beyond'] = true;
                $result[$i] = $item;
                continue;
            }

            // No entry (or an unreadable one, ignored): not verified.
            $entry = $field !== null ? ($cache[$field] ?? null) : null;
            if (is_array($entry)) {
                // `known`: identity looked up again by SHA1. `unknown` with an
                // already computed SHA1: step 3 redone on that data (the
                // referential may have learned this file since, through a
                // download, a search…), without any re-read.
                if ($entry['sha1'] !== null && $entry['status'] !== VerificationCache::STATUS_CF_MATCH) {
                    $knownSha1[] = $entry['sha1'];
                }
                $item['entry'] = $entry;
                $item['class'] = self::CLASS_UNKNOWN;
            }
            $result[$i] = $item;
        }

        $knownFiles = $knownSha1 !== [] ? $this->references->findFilesBySha1($game, $knownSha1) : collect();
        foreach ($result as $i => $item) {
            $entry = $item['entry'];
            unset($result[$i]['entry']);
            if ($entry === null) {
                continue;
            }
            if ($entry['status'] === VerificationCache::STATUS_CF_MATCH) {
                // Recognized on this server only: CurseForge id alone.
                $result[$i]['class'] = self::CLASS_CACHE;
                $result[$i]['mod'] = ['modrinth_project_id' => null, 'curseforge_project_id' => $entry['curseforge_project_id']];
                continue;
            }
            // `known`: identity looked up again in the referential. A SHA1 no
            // longer there (shouldn't happen: a merged mod's files are
            // re-attached, never deleted): no badge. `unknown` whose SHA1 has
            // reached the referential since: installed (the cache isn't
            // rewritten here). Any other `unknown`: verified, unknown to the
            // APIs → not installed.
            $mod = $entry['sha1'] !== null ? $knownFiles->get($entry['sha1'])?->mod : null;
            if ($mod !== null) {
                $result[$i]['class'] = self::CLASS_CACHE;
                $result[$i]['mod'] = ['modrinth_project_id' => $mod->modrinth_project_id, 'curseforge_project_id' => $mod->curseforge_project_id];
            }
        }

        return array_values($result);
    }

    /**
     * Files of the game's target folder covered by the detection (see
     * filterEntries()). The path only comes from gameConfig.json, never from
     * the client.
     *
     * Null if the folder is missing (server not compatible with mods).
     *
     * @return array<int, array{name: string, size: mixed, modified: mixed}>|null
     *
     * @throws DaemonConnectionException
     */
    private function listFiles(Server $server, string $game): ?array
    {
        $folder = $this->gameMapService->targetFolder($game);
        $repo = $this->fileRepository->setServer($server);

        try {
            $entries = $repo->getDirectory('/' . $folder);
        } catch (DaemonConnectionException $ex) {
            if ($this->folderIsMissing($repo, $folder, $ex)) {
                return null;
            }
            throw $ex;
        }

        return $this->filterEntries($game, $entries);
    }

    /**
     * Filters a Wings listing (getDirectory()) of the game's target folder,
     * sorted by name: regular files only (no directory, no symlink, even to a
     * file: its target could be anything), whose extension is one of
     * `installed_detection.extensions`, optionally followed by `.disabled`,
     * and whose name is valid UTF-8 (otherwise the JSON response would fail;
     * no published mod has such a name). Shared by GET /installed and the
     * admin screen.
     *
     * @return array<int, array{name: string, size: mixed, modified: mixed}>
     */
    public function filterEntries(string $game, array $entries): array
    {
        $game = strtolower($game);
        $files = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ($entry['file'] ?? null) !== true || ! empty($entry['directory']) || ! empty($entry['symlink'])) {
                continue;
            }
            $name = $entry['name'] ?? null;
            if (! is_string($name) || $name === '' || ! mb_check_encoding($name, 'UTF-8')) {
                continue;
            }
            $base = str_ends_with(strtolower($name), FilenameNormalizer::DISABLED_SUFFIX)
                ? substr($name, 0, -strlen(FilenameNormalizer::DISABLED_SUFFIX))
                : $name;
            if (! $this->gameMapService->hasInstalledDetectionExtension($game, $base)) {
                continue;
            }
            $files[] = ['name' => $name, 'size' => $entry['size'] ?? null, 'modified' => $entry['modified'] ?? null];
        }

        usort($files, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $files;
    }

    /**
     * The listing failed: is the folder really missing? Wings answers 500
     * (not 404) for a folder that doesn't exist, as for other errors: the
     * folder is only considered missing if that's **proven** by the parent
     * folder's listing (no entry with that name, or a regular file with that
     * name). Otherwise (node unreachable: no answer from Wings; unreadable
     * parent; entry present as a directory or link), it isn't a missing
     * folder: the caller answers 503. No second call when the node didn't
     * answer (it would fail the same way, after the same delay).
     */
    private function folderIsMissing(DaemonFileRepository $repo, string $folder, DaemonConnectionException $ex): bool
    {
        $previous = $ex->getPrevious();
        if (! $previous instanceof RequestException || ! $previous->hasResponse()) {
            return false;
        }

        $path = '/' . trim($folder, '/');
        $parent = dirname($path);
        $name = basename($path);

        try {
            $siblings = $repo->getDirectory($parent);
        } catch (\Throwable $ex) {
            // Node unreachable, or a non-JSON response (TypeError): not a proof.
            return false;
        }

        foreach ($siblings as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $name) {
                return ($entry['file'] ?? null) === true && empty($entry['directory']) && empty($entry['symlink']);
            }
        }

        return true;
    }

    /**
     * Step 1: the referential's mods by normalized key, one query per
     * KEY_CHUNK keys, through the addon's models (join of the two tables, on
     * the model's connection). Strict equality on the key (binary collation
     * on MySQL, then a comparison in PHP), never "starts with".
     *
     * @param string[] $keys
     * @return array<string, array<int, array{id: int, name: string, modrinth_project_id: ?string, curseforge_project_id: ?int}>>
     */
    private function modsByKey(string $game, array $keys): array
    {
        $files = (new ModFile())->getTable();
        $mods = (new Mod())->getTable();

        $byKey = [];
        foreach (array_chunk($keys, self::KEY_CHUNK) as $chunk) {
            $rows = ModFile::query()
                ->join($mods, "{$mods}.id", '=', "{$files}.mod_id")
                ->where("{$files}.game", $game)
                ->whereIn("{$files}.filename_key", $chunk)
                ->distinct()
                ->toBase()
                ->get([
                    "{$files}.filename_key as filename_key",
                    "{$files}.mod_id as mod_id",
                    "{$mods}.name as name",
                    "{$mods}.modrinth_project_id as modrinth_project_id",
                    "{$mods}.curseforge_project_id as curseforge_project_id",
                ]);

            foreach ($rows as $row) {
                $key = (string) $row->filename_key;
                if (! in_array($key, $chunk, true)) {
                    continue;
                }
                $byKey[$key][(int) $row->mod_id] = [
                    'id' => (int) $row->mod_id,
                    'name' => (string) $row->name,
                    'modrinth_project_id' => $row->modrinth_project_id !== null ? (string) $row->modrinth_project_id : null,
                    'curseforge_project_id' => $row->curseforge_project_id !== null ? (int) $row->curseforge_project_id : null,
                ];
            }
        }

        return $byKey;
    }

    /**
     * `collision` line in the journal: date, game, key, server, file, mods;
     * at most once per day per server and per key (CollisionJournal,
     * $oncePerDay). Never throws.
     */
    private function journalCollision(Server $server, string $game, array $file, array $mods): void
    {
        CollisionJournal::collision(
            'Step 1 without a conclusion: normalized key shared by several mods.',
            [
                'game' => $game,
                'key' => $file['key'],
                'server' => $server->uuid,
                'file' => $file['name'],
                'mods' => array_values($mods),
            ],
            $server->uuid . '|' . $game . '|' . $file['key']
        );
    }

    /** One entry of `installed`. */
    private function entry(array $mod, array $file, string $matchedBy): array
    {
        return [
            'modrinth_project_id' => $mod['modrinth_project_id'] ?? null,
            'curseforge_project_id' => $mod['curseforge_project_id'] ?? null,
            'filename' => $file['name'],
            'disabled' => $file['disabled'],
            'matched_by' => $matchedBy,
        ];
    }

    /** Addon log that never throws. */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            AddonBrowserLogger::{$level}($message, $context);
        } catch (\Throwable $ex) {
            // Nothing more to do.
        }
    }
}
