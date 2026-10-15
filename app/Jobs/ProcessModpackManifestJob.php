<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\ModFile;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\AddonGameMapService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\CurseForgeService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\InstalledModsService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModReferenceService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModrinthService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\ModpackEggs;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Processing of an installed modpack's manifest entries, captured by
 * WatchModpackInstallJob.
 *
 * The manifest can be edited by the server's user: each entry is checked
 * **against the API and against the disk**, and only official data is
 * recorded (`source = modpack_index`). The payload went through Redis:
 * server, game, platform and every entry are checked again.
 *
 * Per run, ENTRIES_PER_RUN entries at most, one listing of the game's target
 * folder (name → size, regular detection files), then:
 * - **Modrinth**: **one** `POST /v2/version_files` with the SHA1s; file found
 *   by its SHA1, official name = the entry's name, a detection extension;
 *   project missing from the database → **one** `GET /v2/projects?ids=`,
 *   type `mod` required (same guard as elsewhere); file with the official
 *   name and size present on disk; then ModReferenceService::recordFile().
 * - **CurseForge**: **one** `POST /v1/mods/files` with the `fileID`s; the
 *   game's `gameId`, `modId` = `projectID`, official SHA1, a detection
 *   extension; **one** `POST /v1/mods` (name, game and a mods class of the
 *   game required: the manifest's resource packs or shader packs are left
 *   out); official name and size present on disk. The kept files are handed
 *   to IndexCurseForgeSearchJob (same payload as for a search,
 *   `source = modpack_index`), which applies the grouped Modrinth link, the
 *   type guard and the negative cache without them being copied here.
 *
 * A rejected entry is only counted in the log (reason by reason): the file
 * stays recognized by the detection cascade. The pack's "client only" mods
 * (`env.server: unsupported` on Modrinth, `required: false` on CurseForge)
 * aren't installed on the server: they're counted as `not_on_disk`, it isn't
 * an error.
 *
 * Short: TIME_BUDGET seconds, then the rest is dispatched again (MAX_RUNS
 * runs at most). `low` queue, **no retry**: `$tries = 1`, every exception
 * caught. Node or API unreachable: stop with a log line.
 */
class ProcessModpackManifestJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'low';

    /** No automatic retry (the panel's worker runs with --tries=3). */
    public int $tries = 1;

    /** Target duration of a run, in seconds (~20 s, worker killed at 60 s). */
    public const TIME_BUDGET = 20;

    /** Most entries per run (a single grouped call per API for them). */
    public const ENTRIES_PER_RUN = 250;

    /** Most runs for the same manifest (1,000 entries: 4, plus some margin). */
    public const MAX_RUNS = 8;

    /** Modrinth project type required to record (same guard as after a download). */
    private const MODRINTH_MOD_TYPE = 'mod';

    private const SHA1 = '/^[0-9a-f]{40}$/';

    private const UINT32_MAX = 4294967295;

    /** @var array<string, int> rejections and results, by reason; empty in the payload */
    private array $counts = [];

    /**
     * @param array<int, array{filename: string, sha1: string}|array{project_id: int, file_id: int}> $entries
     */
    public function __construct(
        public readonly string $serverUuid,
        public readonly string $platform,
        public readonly array $entries,
        public readonly int $run = 0,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        try {
            $this->process();
        } catch (\Throwable $ex) {
            // isset() / ??: never an error here, even on an incompletely
            // unserialized payload (uninitialized typed property).
            self::log('warning', 'Modpack: manifest processing given up (unexpected error).', [
                'server' => $this->serverUuid ?? null,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    private function process(): void
    {
        $deadline = microtime(true) + static::TIME_BUDGET;
        $context = ['server' => $this->serverUuid, 'platform' => $this->platform, 'run' => $this->run];

        // Server, game, detection and platform checked again.
        $server = Server::query()->where('uuid', $this->serverUuid)->with('egg.nest')->first();
        $gameMap = app(AddonGameMapService::class);
        $nestName = $server?->egg?->nest?->name;
        $game = is_string($nestName) ? $gameMap->resolveGameFromNestName($nestName) : null;
        if ($server === null || $game === null || ! app(ModReferenceService::class)->acceptsGame($game)
            || ! in_array($this->platform, ['modrinth', 'curseforge'], true)
            || ! in_array($this->platform, $gameMap->availablePlatforms($game), true)
            || ModpackEggs::manifestsFor($this->platform) === []) {
            self::log('info', 'Modpack: server, game or platform not supported, manifest not processed.', $context);

            return;
        }
        $context['game'] = $game;

        $entries = array_values(array_filter($this->entries, fn ($entry) => $this->isValidEntry($entry)));
        $batch = array_slice($entries, 0, self::ENTRIES_PER_RUN);
        $rest = array_slice($entries, self::ENTRIES_PER_RUN);
        if (count($entries) < count($this->entries)) {
            $this->count('invalid_entry', count($this->entries) - count($entries));
        }

        // Files actually present in the target folder: name → size.
        try {
            $listing = app(DaemonFileRepository::class)->setServer($server)->getDirectory('/' . $gameMap->targetFolder($game));
        } catch (\Throwable $ex) {
            self::log('warning', 'Modpack: mods folder unreadable (node unreachable?), manifest not processed.', $context + ['error' => $ex->getMessage()]);

            return;
        }
        $disk = [];
        foreach (app(InstalledModsService::class)->filterEntries($game, is_array($listing) ? $listing : []) as $file) {
            if (is_int($file['size'])) {
                $disk[$file['name']] = $file['size'];
            }
        }

        $continue = $this->platform === 'modrinth'
            ? $this->processModrinth($game, $batch, $disk, $deadline, $rest)
            : $this->processCurseForge($game, $batch, $disk, $deadline, $rest);

        self::log('info', 'Modpack: manifest entries processed.', $context + [
            'entries' => count($batch),
            'results' => $this->counts,
            'remaining' => $continue ? count($rest) : 0,
        ]);

        if (! $continue || $rest === []) {
            return;
        }
        if ($this->run + 1 >= self::MAX_RUNS) {
            self::log('warning', 'Modpack: processing stopped, too many runs for the same manifest.', $context + ['remaining' => count($rest)]);

            return;
        }

        try {
            Bus::dispatch(new self($this->serverUuid, $this->platform, $rest, $this->run + 1));
        } catch (\Throwable $ex) {
            self::log('warning', 'Modpack: follow-up processing not dispatched.', $context + ['error' => $ex->getMessage()]);
        }
    }

    /**
     * @param array<int, array{filename: string, sha1: string}> $batch
     * @param array<string, int> $disk
     * @param array<int, array> $rest remaining entries (the unprocessed candidates are prepended to it if the deadline is exceeded)
     * @return bool false if the API is unreachable (nothing will be dispatched again)
     */
    private function processModrinth(string $game, array $batch, array $disk, float $deadline, array &$rest): bool
    {
        $modrinth = app(ModrinthService::class);
        $references = app(ModReferenceService::class);
        $gameMap = app(AddonGameMapService::class);

        $ok = false;
        $versions = $modrinth->getVersionsByHashes(array_column($batch, 'sha1'), $ok);
        if (! $ok) {
            $this->count('api_unreachable', count($batch));

            return false;
        }

        // Entries checked against the API, then against the disk.
        $candidates = [];
        $missingProjects = [];
        foreach ($batch as $entry) {
            $version = $versions[$entry['sha1']] ?? null;
            $file = $version !== null ? ModrinthService::fileBySha1($version, $entry['sha1']) : null;
            if ($file === null) {
                $this->count('unknown_to_api');
                continue;
            }
            if (($file['filename'] ?? null) !== $entry['filename'] || ! is_int($file['size'] ?? null)
                || ! $gameMap->hasInstalledDetectionExtension($game, $entry['filename'])) {
                $this->count('official_mismatch');
                continue;
            }
            if (! $this->onDisk($disk, $entry['filename'], $file['size'])) {
                continue;
            }
            $candidates[] = [$entry, $version, $file];
            if ($references->findModByModrinthId($version['project_id']) === null) {
                $missingProjects[$version['project_id']] = true;
            }
        }

        // Type (and name) of the projects missing from the database: one grouped call.
        $projects = [];
        if ($missingProjects !== []) {
            if (microtime(true) > $deadline) {
                $rest = array_merge(array_column($candidates, 0), $rest);

                return true;
            }
            $projects = $modrinth->getProjectsByIds(array_keys($missingProjects));
        }

        foreach ($candidates as $i => [$entry, $version, $file]) {
            if ($i > 0 && microtime(true) > $deadline) {
                $rest = array_merge(array_column(array_slice($candidates, $i), 0), $rest);
                break;
            }

            $name = null;
            if (isset($missingProjects[$version['project_id']])) {
                $type = $projects[$version['project_id']]['project_type'] ?? null;
                if (! is_string($type)) {
                    $this->count('project_unknown');
                    continue;
                }
                if ($type !== self::MODRINTH_MOD_TYPE) {
                    $this->count('not_a_mod');
                    continue;
                }
                $title = $projects[$version['project_id']]['title'] ?? null;
                $name = is_string($title) ? $title : null;
            }

            $result = $references->recordFile($game, [
                'name' => $name,
                'modrinth_project_id' => $version['project_id'],
            ], [
                'filename' => $file['filename'],
                'size' => $file['size'],
                'sha1' => $entry['sha1'],
                'modrinth_version_id' => $version['id'],
            ], ModFile::SOURCE_MODPACK_INDEX);
            $this->count('recorded_' . $result->status);
        }

        return true;
    }

    /**
     * @param array<int, array{project_id: int, file_id: int}> $batch
     * @param array<string, int> $disk
     * @param array<int, array> $rest
     * @return bool false if the API is unreachable (nothing will be dispatched again)
     */
    private function processCurseForge(string $game, array $batch, array $disk, float $deadline, array &$rest): bool
    {
        $curseForge = app(CurseForgeService::class);
        $gameMap = app(AddonGameMapService::class);
        $gameId = $curseForge->getGameId($game);
        if ($gameId === null) {
            $this->count('unsupported_game', count($batch));

            return false;
        }

        $files = $curseForge->getFilesByIds(array_column($batch, 'file_id'));
        if ($files === []) {
            // No known file, or API unreachable (indistinguishable): stop.
            $this->count('unknown_to_api', count($batch));

            return false;
        }

        $candidates = [];
        foreach ($batch as $entry) {
            $file = $files[$entry['file_id']] ?? null;
            if (! is_array($file)) {
                $this->count('unknown_to_api');
                continue;
            }
            $sha1 = CurseForgeService::sha1Of($file);
            $filename = $file['fileName'] ?? null;
            $fingerprint = $file['fileFingerprint'] ?? null;
            if (($file['gameId'] ?? null) !== $gameId || ($file['modId'] ?? null) !== $entry['project_id']
                || $sha1 === null
                || ! is_string($filename) || $filename === '' || strlen($filename) > 255
                || ! $gameMap->hasInstalledDetectionExtension($game, $filename)
                || ! is_int($file['fileLength'] ?? null) || $file['fileLength'] < 0) {
                $this->count('official_mismatch');
                continue;
            }
            if (! $this->onDisk($disk, $filename, $file['fileLength'])) {
                continue;
            }
            $candidates[] = [
                'mod_id' => $entry['project_id'],
                'file_id' => $entry['file_id'],
                'filename' => $filename,
                'size' => $file['fileLength'],
                'fingerprint' => is_int($fingerprint) && $fingerprint >= 0 && $fingerprint <= self::UINT32_MAX ? $fingerprint : null,
                'sha1' => $sha1,
            ];
        }

        if ($candidates === []) {
            return true;
        }
        if (microtime(true) > $deadline) {
            $rest = array_merge(array_map(fn ($c) => ['project_id' => $c['mod_id'], 'file_id' => $c['file_id']], $candidates), $rest);

            return true;
        }

        // Name, game and class of the mods: one grouped call. Files of another
        // class (resource packs, shaders…) aren't mods.
        $mods = $curseForge->getModsByIds(array_values(array_unique(array_column($candidates, 'mod_id'))));
        $modClassIds = $curseForge->modClassIds($game);
        $items = [];
        foreach ($candidates as $candidate) {
            $mod = $mods[$candidate['mod_id']] ?? null;
            if (! is_array($mod)) {
                $this->count('project_unknown');
                continue;
            }
            $name = is_string($mod['name'] ?? null) ? trim($mod['name']) : '';
            if (($mod['gameId'] ?? null) !== $gameId || ! in_array($mod['classId'] ?? null, $modClassIds, true)) {
                $this->count('not_a_mod');
                continue;
            }
            if ($name === '' || ! mb_check_encoding($name, 'UTF-8')) {
                $this->count('project_unknown');
                continue;
            }
            $items[] = ['mod_name' => mb_substr($name, 0, 255, 'UTF-8')] + $candidate;
        }

        foreach (array_chunk($items, IndexCurseForgeSearchJob::MAX_FILES) as $chunk) {
            try {
                Bus::dispatch(new IndexCurseForgeSearchJob($game, $gameId, $chunk, 0, ModFile::SOURCE_MODPACK_INDEX));
                $this->count('sent_to_index', count($chunk));
            } catch (\Throwable $ex) {
                $this->count('index_not_dispatched', count($chunk));
                self::log('warning', 'Modpack: indexing of the CurseForge files not dispatched.', ['server' => $this->serverUuid, 'error' => $ex->getMessage()]);
            }
        }

        return true;
    }

    /**
     * Is the file with the official name and size in the folder? Otherwise,
     * the reason is counted (`not_on_disk` covers the pack's "client only"
     * mods, never installed on the server).
     *
     * @param array<string, int> $disk
     */
    private function onDisk(array $disk, string $filename, int $size): bool
    {
        if (! array_key_exists($filename, $disk)) {
            $this->count('not_on_disk');

            return false;
        }
        if ($disk[$filename] !== $size) {
            $this->count('size_mismatch');

            return false;
        }

        return true;
    }

    /** Well-formed entry for the platform (the payload went through Redis). */
    private function isValidEntry(mixed $entry): bool
    {
        if (! is_array($entry)) {
            return false;
        }

        if ($this->platform === 'modrinth') {
            return is_string($entry['filename'] ?? null) && $entry['filename'] !== '' && strlen($entry['filename']) <= 255
                && ! str_contains($entry['filename'], '/') && ! str_contains($entry['filename'], '\\')
                && is_string($entry['sha1'] ?? null) && preg_match(self::SHA1, $entry['sha1']) === 1;
        }

        return is_int($entry['project_id'] ?? null) && $entry['project_id'] > 0 && $entry['project_id'] <= self::UINT32_MAX
            && is_int($entry['file_id'] ?? null) && $entry['file_id'] > 0 && $entry['file_id'] <= self::UINT32_MAX;
    }

    private function count(string $reason, int $n = 1): void
    {
        $this->counts[$reason] = ($this->counts[$reason] ?? 0) + $n;
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
