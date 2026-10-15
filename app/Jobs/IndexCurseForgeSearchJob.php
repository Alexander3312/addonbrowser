<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\ModFile;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\AddonGameMapService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\CurseForgeService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModReferenceResult;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModReferenceService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModrinthService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;

/**
 * Indexing of a CurseForge search's results into the installed-mods
 * referential.
 *
 * Dispatched by AddonBrowserController::search() (CurseForge, `mod` mode, game
 * with `installed_detection`, at most RATE_LIMIT_PER_MINUTE dispatches per
 * minute and per user) with a **minimal, bounded** payload built by
 * payloadFromSearch() from the response to **our** API call (never the raw
 * response, never data from the browser): for the game's mods (`gameId`
 * checked, a mods class of the game), the recent files (`latestFiles`) in
 * release (`releaseType = 1`), with a SHA1 (algo 1) and an extension from
 * `installed_detection.extensions`: mod id and name, file id, name, size,
 * fingerprint and SHA1. MAX_FILES files at most.
 *
 * Also reused for the files of a CurseForge modpack: ProcessModpackManifestJob
 * builds the same payload from files checked against the API and the disk,
 * with `source = modpack_index` (see SOURCES); everything else is identical.
 *
 * For each file (`source = search_result`, or `modpack_index`),
 * ModReferenceService::recordFile() applies these steps:
 * 1. SHA1 already in the database → the mod exists (link, merge or journaled
 *    conflict, see ModReferenceService);
 * 2. otherwise, a mod already carries this CurseForge id → file added;
 * 3. Modrinth: **one** grouped call (`version_files`) per run, for every SHA1
 *    still unknown in the database **and** those known to a mod without a
 *    Modrinth id (not only those that steps 1 and 2 don't resolve: otherwise the
 *    link would depend on the order of the files, and an already known
 *    CurseForge-only mod would never be linked). File known to Modrinth (same
 *    SHA1, same size) → the Modrinth id (and version id) is passed to
 *    recordFile() too, whatever the step. If no mod carries either this
 *    Modrinth id or this CurseForge id, the mod is **created directly with
 *    both ids** (same SHA1: enough proof); if one of them already exists,
 *    the usual rules (link, merge of a Modrinth-only mod with a
 *    CurseForge-only mod, otherwise journaled conflict).
 *    Project type guard, as after a download: for Modrinth projects
 *    **missing from the database**, **one** grouped call
 *    (`GET /v2/projects?ids=`) per run. A `project_type` other than `mod` →
 *    no link (CurseForge id only). Project missing from the response or API
 *    unreachable → no link this time (CurseForge id only) and **no** negative
 *    cache: the next replay asks again and links. Deadline already exceeded
 *    before that call → it isn't made, the files that depend on it move to
 *    the next run;
 * 4. otherwise, creation of the mod and its file (CurseForge id only).
 *
 * **Negative results cached**, for one day, per (game, SHA1, reason), prefix
 * NEGATIVE_CACHE_PREFIX:
 * - `modrinth_unknown` (Modrinth answered without this SHA1, or with another
 *   size) and `modrinth_not_mod` (Modrinth project of a type other than
 *   `mod`): the SHA1 isn't sent to Modrinth again for a day. Nothing is
 *   cached if Modrinth was unreachable (neither `version_files` nor
 *   `projects`): the next replay asks again;
 * - `conflict` (recordFile() refused the file): the file is ignored for a day
 *   (no Modrinth call, no write), the first pass having already journaled the
 *   conflict.
 * A replay within the day therefore only does database reads and "unchanged"
 * results, without API calls (except for the SHA1s whose Modrinth call
 * failed). Cache unavailable: treated as empty.
 *
 * Short: FILES_PER_RUN files, two grouped Modrinth calls and TIME_BUDGET
 * seconds at most per run, then the job dispatches itself again for the rest
 * (MAX_RUNS runs at most). `low` queue, **no retry**: `$tries = 1` and every
 * exception caught and logged. A failure never has any effect on the search,
 * already returned.
 */
class IndexCurseForgeSearchJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'low';

    /** No automatic retry (the panel's worker runs with --tries=3). */
    public int $tries = 1;

    /** Most files in one job's payload (a page of 50 mods often has fewer). */
    public const MAX_FILES = 300;

    /** Most files per run (a single Modrinth call for them). */
    public const FILES_PER_RUN = 100;

    /** Target duration of a run, in seconds (~20 s, worker killed at 60 s). */
    public const TIME_BUDGET = 20;

    /** Most runs for the same payload (guard against a loop). */
    public const MAX_RUNS = 5;

    /**
     * Prefix of the dispatch deduplication cache keys (the only place it's
     * defined): the same results page (same files) is only indexed once per
     * DEDUP_TTL, even if it's displayed again.
     */
    public const DEDUP_CACHE_PREFIX = 'addonbrowser_cfindex_';

    public const DEDUP_TTL = 600;

    /**
     * Limit of dispatches per user (RateLimiter, key RATE_LIMIT_PREFIX . user
     * id, the only place the prefix is defined): past it, the search answers
     * normally, without indexing.
     */
    public const RATE_LIMIT_PREFIX = 'addonbrowser_cfrate_';

    public const RATE_LIMIT_PER_MINUTE = 10;

    /** Prefix of the negative cache keys (the only place it's defined). */
    public const NEGATIVE_CACHE_PREFIX = 'addonbrowser_cfneg_';

    /** Lifetime of the negative cache, in seconds (one day). */
    public const NEGATIVE_TTL = 86400;

    public const NEGATIVE_MODRINTH_UNKNOWN = 'modrinth_unknown';
    public const NEGATIVE_MODRINTH_NOT_MOD = 'modrinth_not_mod';
    public const NEGATIVE_CONFLICT = 'conflict';

    /** Modrinth project type required to link (same guard as after a download). */
    private const MODRINTH_MOD_TYPE = 'mod';

    private const UINT32_MAX = 4294967295;

    /**
     * Accepted sources: search results, and the files of a CurseForge modpack
     * manifest already checked against the API and the disk by
     * ProcessModpackManifestJob, which thereby reuses this job's Modrinth link,
     * type guard and negative cache.
     */
    public const SOURCES = [ModFile::SOURCE_SEARCH_RESULT, ModFile::SOURCE_MODPACK_INDEX];

    /**
     * @param array<int, array{mod_id: int, mod_name: string, file_id: int, filename: string, size: int, fingerprint: ?int, sha1: string}> $files
     */
    public function __construct(
        public readonly string $game,
        public readonly int $gameId,
        public readonly array $files,
        public readonly int $run = 0,
        public readonly string $source = ModFile::SOURCE_SEARCH_RESULT,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Source of the writes (the file's `source`, and therefore the
     * `file_added_<source>` statistic). Payload without this property:
     * `search_result`. Value outside SOURCES: null (nothing is written).
     */
    private function source(): ?string
    {
        $source = $this->source ?? ModFile::SOURCE_SEARCH_RESULT;

        return in_array($source, self::SOURCES, true) ? $source : null;
    }

    /**
     * Job payload from the response of CurseForgeService::search() (our API
     * call): only the mods whose `gameId` = $gameId and whose class is one of
     * $modClassIds, their `latestFiles` of the same mod and game, in release,
     * with a SHA1 and an extension from $extensions (the game's
     * `installed_detection.extensions`); deduplicated by file id, MAX_FILES at
     * most. Any malformed object is ignored.
     *
     * @param int[] $modClassIds
     * @param string[] $extensions lowercase
     * @return array<int, array{mod_id: int, mod_name: string, file_id: int, filename: string, size: int, fingerprint: ?int, sha1: string}>
     */
    public static function payloadFromSearch(array $result, int $gameId, array $modClassIds, array $extensions): array
    {
        $files = [];
        $mods = is_array($result['data'] ?? null) ? $result['data'] : [];

        foreach ($mods as $mod) {
            if (! is_array($mod)
                || ! is_int($mod['id'] ?? null) || $mod['id'] <= 0 || $mod['id'] > self::UINT32_MAX
                || ($mod['gameId'] ?? null) !== $gameId
                || ! in_array($mod['classId'] ?? null, $modClassIds, true)
                || ! is_string($mod['name'] ?? null) || trim($mod['name']) === '' || ! mb_check_encoding($mod['name'], 'UTF-8')
                || ! is_array($mod['latestFiles'] ?? null)) {
                continue;
            }

            foreach ($mod['latestFiles'] as $file) {
                if (count($files) >= self::MAX_FILES) {
                    break 2;
                }
                if (! is_array($file)
                    || ! is_int($file['id'] ?? null) || $file['id'] <= 0 || $file['id'] > self::UINT32_MAX
                    || ($file['modId'] ?? null) !== $mod['id']
                    || ($file['gameId'] ?? null) !== $gameId
                    || ($file['releaseType'] ?? null) !== 1
                    || ! is_string($file['fileName'] ?? null) || $file['fileName'] === '' || strlen($file['fileName']) > 255
                    || ! in_array(strtolower(pathinfo($file['fileName'], PATHINFO_EXTENSION)), $extensions, true)
                    || ! is_int($file['fileLength'] ?? null) || $file['fileLength'] < 0
                    || isset($files[$file['id']])) {
                    continue;
                }
                $sha1 = CurseForgeService::sha1Of($file);
                if ($sha1 === null) {
                    continue;
                }
                $fingerprint = $file['fileFingerprint'] ?? null;

                $files[$file['id']] = [
                    'mod_id' => $mod['id'],
                    'mod_name' => mb_substr(trim($mod['name']), 0, 255, 'UTF-8'),
                    'file_id' => $file['id'],
                    'filename' => $file['fileName'],
                    'size' => $file['fileLength'],
                    'fingerprint' => is_int($fingerprint) && $fingerprint >= 0 && $fingerprint <= self::UINT32_MAX ? $fingerprint : null,
                    'sha1' => $sha1,
                ];
            }
        }

        return array_values($files);
    }

    /**
     * Claims the dispatch of a payload: false if the same (game, file ids) was
     * already dispatched less than DEDUP_TTL seconds ago. Cache unavailable:
     * true (the job is idempotent, a duplicate only costs one run).
     */
    public static function claimDispatch(string $game, array $files): bool
    {
        $ids = array_column($files, 'file_id');
        sort($ids);

        try {
            return Cache::add(self::dedupKey($game, $ids), 1, self::DEDUP_TTL);
        } catch (\Throwable $ex) {
            return true;
        }
    }

    /** @param int[] $sortedFileIds */
    public static function dedupKey(string $game, array $sortedFileIds): string
    {
        return self::DEDUP_CACHE_PREFIX . sha1(strtolower($game) . '|' . implode(',', $sortedFileIds));
    }

    /** RateLimiter key of the dispatches for a user. */
    public static function rateLimitKey(int|string $userId): string
    {
        return self::RATE_LIMIT_PREFIX . $userId;
    }

    /** Negative cache key for (game, SHA1, reason). */
    public static function negativeKey(string $game, string $sha1, string $reason): string
    {
        return self::NEGATIVE_CACHE_PREFIX . sha1(strtolower($game) . '|' . strtolower($sha1) . '|' . $reason);
    }

    public function handle(): void
    {
        try {
            $this->index();
        } catch (\Throwable $ex) {
            // isset() / ??: never an error here, even on an incompletely
            // unserialized payload (uninitialized typed property).
            self::log('warning', 'Mod referential: indexing of a CurseForge search given up (unexpected error).', [
                'game' => $this->game ?? null,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    private function index(): void
    {
        $references = app(ModReferenceService::class);
        if (! $references->acceptsGame($this->game)) {
            return;
        }

        $source = $this->source();
        if ($source === null) {
            self::log('warning', 'Mod referential: indexing source refused.', ['game' => $this->game]);

            return;
        }

        // gameId checked again: the game's, never a value taken as received.
        $curseForge = app(CurseForgeService::class);
        if ($curseForge->getGameId($this->game) !== $this->gameId) {
            self::log('warning', 'Mod referential: inconsistent gameId, indexing skipped.', ['game' => $this->game, 'game_id' => $this->gameId]);

            return;
        }

        $gameMap = app(AddonGameMapService::class);
        $deadline = microtime(true) + static::TIME_BUDGET;
        // The payload went through Redis: every item is checked again, extension included.
        $files = array_values(array_filter(
            $this->files,
            fn ($file) => self::isValidItem($file) && $gameMap->hasInstalledDetectionExtension($this->game, $file['filename'])
        ));
        $batch = array_slice($files, 0, self::FILES_PER_RUN);
        $rest = array_slice($files, self::FILES_PER_RUN);

        $negative = $this->readNegativeCache(array_column($batch, 'sha1'));
        $batch = array_values(array_filter($batch, fn ($file) => ! isset($negative[$file['sha1']][self::NEGATIVE_CONFLICT])));

        // Step 3 prepared: a single Modrinth call for the SHA1s unknown in the
        // database and those known to a mod without a Modrinth id, negative
        // cache aside.
        $known = $references->findFilesBySha1($this->game, array_column($batch, 'sha1'));
        $toAsk = [];
        foreach ($batch as $file) {
            $knownFile = $known->get($file['sha1']);
            if (($knownFile === null || $knownFile->mod?->modrinth_project_id === null)
                && ! isset($negative[$file['sha1']][self::NEGATIVE_MODRINTH_UNKNOWN])
                && ! isset($negative[$file['sha1']][self::NEGATIVE_MODRINTH_NOT_MOD])) {
                $toAsk[$file['sha1']] = true;
            }
        }
        $modrinthOk = false;
        $modrinthVersions = $toAsk !== [] && in_array('modrinth', $gameMap->availablePlatforms($this->game), true)
            ? app(ModrinthService::class)->getVersionsByHashes(array_keys($toAsk), $modrinthOk)
            : [];

        // Kept matches: file known to Modrinth with the same SHA1 and size
        // ([sha1 => version]). Modrinth projects of those matches missing from
        // the database: their type is checked by **one** grouped call
        // (`GET /v2/projects?ids=`), as after a download.
        $matches = [];
        $missingProjects = [];
        $checkedProjects = [];
        foreach ($modrinthOk ? $batch : [] as $file) {
            $version = isset($toAsk[$file['sha1']]) ? ($modrinthVersions[$file['sha1']] ?? null) : null;
            $modrinthFile = $version !== null ? ModrinthService::fileBySha1($version, $file['sha1']) : null;
            if ($modrinthFile === null || ($modrinthFile['size'] ?? null) !== $file['size']) {
                continue;
            }
            $matches[$file['sha1']] = $version;
            $projectId = $version['project_id'];
            if (! isset($checkedProjects[$projectId])) {
                $checkedProjects[$projectId] = true;
                if ($references->findModByModrinthId($projectId) === null) {
                    $missingProjects[$projectId] = true;
                }
            }
        }

        // Deadline already exceeded: no more calls, the files that depend on
        // it move to the next run (neither a lost link nor a CurseForge-only
        // mod created for lack of time).
        $projects = [];
        $projectsAsked = false;
        if ($missingProjects !== [] && microtime(true) <= $deadline) {
            $projects = app(ModrinthService::class)->getProjectsByIds(array_keys($missingProjects));
            $projectsAsked = true;
        }

        $counts = [];
        $newNegatives = [];
        $deferred = [];
        $projectLookupFailures = 0;
        foreach ($batch as $i => $file) {
            if ($i > 0 && microtime(true) > $deadline) {
                $rest = array_merge(array_slice($batch, $i), $rest);
                break;
            }

            $project = ['name' => $file['mod_name'], 'curseforge_project_id' => $file['mod_id']];
            $record = [
                'filename' => $file['filename'],
                'size' => $file['size'],
                'sha1' => $file['sha1'],
                'cf_fingerprint' => $file['fingerprint'],
                'curseforge_file_id' => $file['file_id'],
            ];

            $version = $matches[$file['sha1']] ?? null;
            if ($version === null && isset($toAsk[$file['sha1']]) && $modrinthOk) {
                $newNegatives[] = [$file['sha1'], self::NEGATIVE_MODRINTH_UNKNOWN];
            } elseif ($version !== null && isset($missingProjects[$version['project_id']])) {
                if (! $projectsAsked) {
                    $deferred[] = $file;
                    continue;
                }
                $type = $projects[$version['project_id']]['project_type'] ?? null;
                if (! is_string($type)) {
                    // Project missing from the response or API unreachable: no
                    // link this time, CurseForge id only, and **no** negative
                    // cache (the next replay asks again and links).
                    $version = null;
                    $projectLookupFailures++;
                } elseif ($type !== self::MODRINTH_MOD_TYPE) {
                    // Not a mod (resource pack, plugin…): never linked.
                    $version = null;
                    $newNegatives[] = [$file['sha1'], self::NEGATIVE_MODRINTH_NOT_MOD];
                }
            }

            // Same SHA1 and size on Modrinth: both ids are passed, recordFile()
            // creates the mod with both, links, merges or refuses (conflict
            // journaled).
            if ($version !== null) {
                $project['modrinth_project_id'] = $version['project_id'];
                $record['modrinth_version_id'] = $version['id'];
            }

            $result = $references->recordFile($this->game, $project, $record, $source);
            $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;
            if ($result->status === ModReferenceResult::CONFLICT) {
                $newNegatives[] = [$file['sha1'], self::NEGATIVE_CONFLICT];
            }
        }
        $rest = array_merge($deferred, $rest);

        $this->writeNegativeCache($newNegatives);

        self::log('info', $source === ModFile::SOURCE_MODPACK_INDEX
            ? 'Mod referential: files of a CurseForge modpack indexed.'
            : 'Mod referential: CurseForge search results indexed.', [
            'game' => $this->game,
            'source' => $source,
            'run' => $this->run,
            'files' => array_sum($counts),
            'statuses' => $counts,
            'modrinth_lookups' => count($toAsk),
            'modrinth_ok' => $modrinthOk,
            'modrinth_projects_checked' => $projectsAsked ? count($missingProjects) : 0,
            'modrinth_project_failures' => $projectLookupFailures,
            'deferred' => count($deferred),
            'remaining' => count($rest),
        ]);

        if ($rest === []) {
            return;
        }

        if ($this->run + 1 >= self::MAX_RUNS) {
            self::log('warning', 'Mod referential: indexing stopped, too many runs for the same search.', ['game' => $this->game, 'remaining' => count($rest)]);

            return;
        }

        try {
            Bus::dispatch(new self($this->game, $this->gameId, $rest, $this->run + 1, $source));
        } catch (\Throwable $ex) {
            self::log('warning', 'Mod referential: follow-up indexing not dispatched.', ['game' => $this->game, 'error' => $ex->getMessage()]);
        }
    }

    /**
     * Negative cache reasons for these SHA1s: [sha1 => [reason => true]].
     * A single grouped read; cache unavailable → empty.
     *
     * @param string[] $sha1s
     */
    private function readNegativeCache(array $sha1s): array
    {
        $keys = [];
        foreach (array_unique($sha1s) as $sha1) {
            foreach ([self::NEGATIVE_MODRINTH_UNKNOWN, self::NEGATIVE_MODRINTH_NOT_MOD, self::NEGATIVE_CONFLICT] as $reason) {
                $keys[self::negativeKey($this->game, $sha1, $reason)] = [$sha1, $reason];
            }
        }
        if ($keys === []) {
            return [];
        }

        try {
            $values = Cache::many(array_keys($keys));
        } catch (\Throwable $ex) {
            return [];
        }

        $negative = [];
        foreach ($values as $key => $value) {
            if ($value !== null && isset($keys[$key])) {
                [$sha1, $reason] = $keys[$key];
                $negative[$sha1][$reason] = true;
            }
        }

        return $negative;
    }

    /** @param array<int, array{0: string, 1: string}> $entries [sha1, reason] */
    private function writeNegativeCache(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $values = [];
        foreach ($entries as [$sha1, $reason]) {
            $values[self::negativeKey($this->game, $sha1, $reason)] = 1;
        }

        try {
            Cache::putMany($values, self::NEGATIVE_TTL);
        } catch (\Throwable $ex) {
            self::log('warning', 'Mod referential: negative cache unavailable.', ['error' => $ex->getMessage()]);
        }
    }

    /** Well-formed payload item (the payload went through Redis). */
    private static function isValidItem(mixed $file): bool
    {
        return is_array($file)
            && is_int($file['mod_id'] ?? null) && $file['mod_id'] > 0
            && is_string($file['mod_name'] ?? null)
            && is_int($file['file_id'] ?? null) && $file['file_id'] > 0
            && is_string($file['filename'] ?? null)
            && is_int($file['size'] ?? null)
            && (($file['fingerprint'] ?? null) === null || is_int($file['fingerprint']))
            && is_string($file['sha1'] ?? null) && preg_match('/^[0-9a-f]{40}$/', $file['sha1']) === 1;
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
