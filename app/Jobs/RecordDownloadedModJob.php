<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\ModFile;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\AddonGameMapService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\CurseForgeService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModReferenceResult;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModReferenceService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModrinthService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;

/**
 * Records in the installed-mods referential a file that addonbrowser has just
 * downloaded to a server.
 *
 * Dispatched by AddonBrowserController::download() after the file was written
 * successfully, with **only** the game, the download URL, the SHA1 and the
 * size of the downloaded bytes (never the bytes themselves: they would go
 * through Redis). Nothing comes from the browser except the URL, which is only
 * a hint: only the APIs' official answers, which must match the SHA1 computed
 * by the backend, are recorded.
 *
 * - **Modrinth** (`cdn.modrinth.com`): `POST /v2/version_files` with the SHA1
 *   → version, official file (found by its SHA1), project; project name
 *   through `GET /v2/projects?ids=` only if the mod isn't known yet (it's only
 *   required to create it), project of type `mod` required.
 * - **CurseForge** (`edge.forgecdn.net`): file id read from the URL
 *   (`/files/<a>/<b>/<name>` → `a × 1000 + b`, curseForgeFileIdFromUrl()),
 *   then `POST /v1/mods/files` → official file. Recorded only if its `gameId`
 *   is the game's, its official SHA1 (algo 1) matches the downloaded bytes'
 *   and its size matches; mod name through `POST /v1/mods` if the mod isn't
 *   known yet (same game and a mods class required). Then a link attempt: if
 *   Modrinth knows that same SHA1 (same size), the file is recorded again with
 *   both ids (ModReferenceService applies the link, merge and conflict rules).
 *   A Modrinth project missing from the database must be of type `mod` (one
 *   more `GET /v2/projects?ids=` call); otherwise, or if the call fails, no
 *   link.
 * - A platform is only queried if it's one of the game's platforms
 *   (gameConfig.json), and only for a game whose referential accepts writes
 *   (`installed_detection`).
 * - Only files whose extension is one of the game's
 *   `installed_detection.extensions` are recorded (URL name checked before any
 *   API call, official name checked before the write): a downloaded `.zip`
 *   does nothing.
 *
 * `source = download`. `low` queue, short job: at most four grouped calls with
 * short timeouts, and an overall deadline (TIME_BUDGET) checked before each
 * call and before each write; past it, a clean stop with a log line, no retry.
 * **No retry**: `$tries = 1` (the panel's worker runs with `--tries=3`) and
 * every exception is caught and logged.
 * A failure (different SHA1, API unreachable, unknown host) records nothing
 * and never has any effect on the download, already finished.
 */
class RecordDownloadedModJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'low';

    /** No automatic retry (see the class docblock). */
    public int $tries = 1;

    /**
     * Accepted hosts, and the platform of each. The download's other hosts
     * (CurseForge redirects) are never the original URL of a file offered by
     * addonbrowser: nothing is recorded.
     */
    public const HOSTS = [
        'cdn.modrinth.com' => 'modrinth',
        'edge.forgecdn.net' => 'curseforge',
    ];

    /** Same ceiling as download(): past it, nothing could have been downloaded. */
    private const MAX_BYTES = 50 * 1024 * 1024;

    private const UINT32_MAX = 4294967295;

    /** Longest duration of a run, in seconds (~20 s, worker killed at 60 s). */
    public const TIME_BUDGET = 20;

    /** Deadline of the current run (set by handle(), never usefully serialized). */
    private ?float $deadline = null;

    public function __construct(
        public readonly string $game,
        public readonly string $url,
        public readonly string $sha1,
        public readonly int $size,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $this->deadline = microtime(true) + static::TIME_BUDGET;

        try {
            $this->record();
        } catch (\Throwable $ex) {
            // isset() / ??: never an error here, even on an incompletely
            // unserialized payload (uninitialized typed property).
            self::log('warning', 'Mod referential: recording after download given up (unexpected error).', [
                'game' => $this->game ?? null,
                'url' => $this->url ?? null,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    /**
     * Deadline exceeded? If so, a log line: the caller gives up without
     * writing anything more (no retry).
     */
    private function timeUp(string $step): bool
    {
        if ($this->deadline === null || microtime(true) <= $this->deadline) {
            return false;
        }

        self::log('warning', "Mod referential: recording after download given up, deadline exceeded ({$step}).", [
            'game' => $this->game,
            'url' => $this->url,
        ]);

        return true;
    }

    /**
     * Platform of a download URL (`https`, exact host from HOSTS, no port nor
     * credentials), or null.
     */
    public static function platformForUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return self::HOSTS[strtolower($parts['host'] ?? '')] ?? null;
    }

    /**
     * Id of a CurseForge file from its URL:
     * `https://edge.forgecdn.net/files/<a>/<b>/<name>` → `a × 1000 + b`, `<b>`
     * being written without padding zeros (`8945/86` → `8945086`). A `<b>`
     * with padding (`086`) gives the same id. Null if the URL doesn't have
     * this shape. It's only a hint: the file returned by the API must then
     * have the downloaded bytes' SHA1.
     */
    public static function curseForgeFileIdFromUrl(string $url): ?int
    {
        if (self::platformForUrl($url) !== 'curseforge') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || preg_match('#^/files/(\d{1,7})/(\d{1,3})/[^/]+$#', $path, $m) !== 1) {
            return null;
        }

        $id = (int) $m[1] * 1000 + (int) $m[2];

        return $id > 0 && $id <= self::UINT32_MAX ? $id : null;
    }

    private function record(): void
    {
        $references = app(ModReferenceService::class);
        $sha1 = strtolower($this->sha1);

        if (! $references->acceptsGame($this->game)) {
            self::log('debug', 'Mod referential: game without detection, download not recorded.', ['game' => $this->game]);

            return;
        }

        if (preg_match('/^[0-9a-f]{40}$/', $sha1) !== 1 || $this->size < 0 || $this->size > self::MAX_BYTES) {
            self::log('warning', 'Mod referential: invalid download data, nothing recorded.', ['game' => $this->game, 'url' => $this->url]);

            return;
        }

        $gameMap = app(AddonGameMapService::class);
        $platform = self::platformForUrl($this->url);
        if ($platform === null || ! in_array($platform, $gameMap->availablePlatforms($this->game), true)) {
            self::log('info', 'Mod referential: host or platform not supported, download not recorded.', ['game' => $this->game, 'url' => $this->url]);

            return;
        }

        // Extension of the URL's name (the one written on the server): outside
        // `installed_detection.extensions`, nothing, without an API call.
        $urlPath = parse_url($this->url, PHP_URL_PATH);
        if (! is_string($urlPath) || ! $gameMap->hasInstalledDetectionExtension($this->game, rawurldecode(basename($urlPath)))) {
            self::log('debug', 'Mod referential: extension outside detection, download not recorded.', ['game' => $this->game, 'url' => $this->url]);

            return;
        }

        $platform === 'modrinth'
            ? $this->recordFromModrinth($references, $sha1)
            : $this->recordFromCurseForge($references, $sha1);
    }

    private function recordFromModrinth(ModReferenceService $references, string $sha1): void
    {
        $modrinth = app(ModrinthService::class);

        $version = $modrinth->getVersionsByHashes([$sha1])[$sha1] ?? null;
        $file = $version !== null ? ModrinthService::fileBySha1($version, $sha1) : null;
        if ($file === null) {
            self::log('info', 'Mod referential: SHA1 unknown to Modrinth (or API unreachable), nothing recorded.', ['game' => $this->game, 'url' => $this->url, 'sha1' => $sha1]);

            return;
        }

        if (! is_int($file['size'] ?? null) || $file['size'] !== $this->size) {
            self::log('warning', 'Mod referential: official Modrinth size differs, nothing recorded.', ['game' => $this->game, 'sha1' => $sha1]);

            return;
        }

        if (! $this->officialExtensionAllowed($file['filename'] ?? null) || $this->timeUp('after version_files')) {
            return;
        }

        $projectId = $version['project_id'];
        $name = null;
        // Name only required to create the mod: one more call, only if it
        // isn't known yet.
        if ($references->findModByModrinthId($projectId) === null) {
            $project = $modrinth->getProjectsByIds([$projectId])[$projectId] ?? null;
            if ($project !== null && ($project['project_type'] ?? null) !== 'mod') {
                self::log('info', 'Mod referential: the Modrinth project isn\'t a mod, nothing recorded.', ['game' => $this->game, 'project_id' => $projectId]);

                return;
            }
            $name = is_string($project['title'] ?? null) ? $project['title'] : null;
            if ($this->timeUp('after projects')) {
                return;
            }
        }

        $result = $references->recordFile($this->game, [
            'name' => $name,
            'modrinth_project_id' => $projectId,
        ], [
            'filename' => $file['filename'] ?? null,
            'size' => $file['size'],
            'sha1' => $sha1,
            'modrinth_version_id' => $version['id'],
        ], ModFile::SOURCE_DOWNLOAD);

        $this->logResult('Modrinth', $result, $sha1);
    }

    private function recordFromCurseForge(ModReferenceService $references, string $sha1): void
    {
        $fileId = self::curseForgeFileIdFromUrl($this->url);
        if ($fileId === null) {
            self::log('info', 'Mod referential: CurseForge URL without a file id, nothing recorded.', ['game' => $this->game, 'url' => $this->url]);

            return;
        }

        $curseForge = app(CurseForgeService::class);
        $gameId = $curseForge->getGameId($this->game);
        if ($gameId === null) {
            return;
        }

        $file = $curseForge->getFilesByIds([$fileId])[$fileId] ?? null;
        if ($file === null) {
            self::log('info', 'Mod referential: CurseForge file not found (or API unreachable), nothing recorded.', ['game' => $this->game, 'file_id' => $fileId]);

            return;
        }

        $modId = $file['modId'] ?? null;
        if (($file['gameId'] ?? null) !== $gameId
            || CurseForgeService::sha1Of($file) !== $sha1
            || ! is_int($file['fileLength'] ?? null) || $file['fileLength'] !== $this->size
            || ! is_int($modId) || $modId <= 0) {
            self::log('warning', 'Mod referential: CurseForge file differs from the downloaded bytes (game, SHA1 or size), nothing recorded.', [
                'game' => $this->game,
                'file_id' => $fileId,
                'sha1' => $sha1,
            ]);

            return;
        }

        if (! $this->officialExtensionAllowed($file['fileName'] ?? null) || $this->timeUp('after mods/files')) {
            return;
        }

        $name = null;
        if ($references->findModByCurseForgeId($modId) === null) {
            $mod = $curseForge->getModsByIds([$modId])[$modId] ?? null;
            if ($mod !== null && (($mod['gameId'] ?? null) !== $gameId || ! in_array($mod['classId'] ?? null, $curseForge->modClassIds($this->game), true))) {
                self::log('info', 'Mod referential: the CurseForge project isn\'t a mod of this game, nothing recorded.', ['game' => $this->game, 'mod_id' => $modId]);

                return;
            }
            $name = is_string($mod['name'] ?? null) ? $mod['name'] : null;
            if ($this->timeUp('after mods')) {
                return;
            }
        }

        $result = $references->recordFile($this->game, [
            'name' => $name,
            'curseforge_project_id' => $modId,
        ], [
            'filename' => $file['fileName'] ?? null,
            'size' => $file['fileLength'],
            'sha1' => $sha1,
            'cf_fingerprint' => $file['fileFingerprint'] ?? null,
            'curseforge_file_id' => $fileId,
        ], ModFile::SOURCE_DOWNLOAD);

        $this->logResult('CurseForge', $result, $sha1);

        // Link: one Modrinth call with this same SHA1. Only if the file really
        // is in the referential, and if the game has Modrinth.
        if (! $result->isRecorded() || ! in_array('modrinth', app(AddonGameMapService::class)->availablePlatforms($this->game), true)
            || $this->timeUp('before the Modrinth link')) {
            return;
        }

        $version = app(ModrinthService::class)->getVersionsByHashes([$sha1])[$sha1] ?? null;
        $modrinthFile = $version !== null ? ModrinthService::fileBySha1($version, $sha1) : null;
        if ($modrinthFile === null || ($modrinthFile['size'] ?? null) !== $this->size
            || ! $this->officialExtensionAllowed($modrinthFile['filename'] ?? null) || $this->timeUp('after the Modrinth link')) {
            return;
        }

        // Same guard as when indexing searches: a Modrinth project missing
        // from the database must be of type `mod` (a resource pack or a plugin
        // published with the same jar must never be linked). Project already
        // in the database: it was checked when it was created. Response
        // without that project or API unreachable: no link (this job isn't
        // replayed).
        if ($references->findModByModrinthId($version['project_id']) === null) {
            $project = app(ModrinthService::class)->getProjectsByIds([$version['project_id']])[$version['project_id']] ?? null;
            $type = $project['project_type'] ?? null;
            if ($type !== 'mod') {
                self::log('info', is_string($type)
                    ? 'Mod referential: the Modrinth project isn\'t a mod, no link.'
                    : 'Mod referential: Modrinth project type unknown (or API unreachable), no link.', [
                        'game' => $this->game,
                        'project_id' => $version['project_id'],
                        'project_type' => is_string($type) ? $type : null,
                    ]);

                return;
            }
            if ($this->timeUp('after projects (link)')) {
                return;
            }
        }

        // Both ids come from official answers about this same SHA1: the
        // service links, merges or refuses (conflict journaled).
        $link = $references->recordFile($this->game, [
            'modrinth_project_id' => $version['project_id'],
            'curseforge_project_id' => $modId,
        ], [
            'filename' => $modrinthFile['filename'] ?? null,
            'size' => $modrinthFile['size'],
            'sha1' => $sha1,
            'modrinth_version_id' => $version['id'],
        ], ModFile::SOURCE_DOWNLOAD);

        $this->logResult('Modrinth link', $link, $sha1);
    }

    /**
     * Does the official name returned by the API have one of the
     * `installed_detection.extensions`? Otherwise, a log line and nothing.
     */
    private function officialExtensionAllowed(mixed $filename): bool
    {
        if (is_string($filename) && app(AddonGameMapService::class)->hasInstalledDetectionExtension($this->game, $filename)) {
            return true;
        }

        self::log('info', 'Mod referential: official name outside the detection extensions, nothing recorded.', ['game' => $this->game, 'filename' => is_string($filename) ? $filename : null]);

        return false;
    }

    private function logResult(string $origin, ModReferenceResult $result, string $sha1): void
    {
        self::log($result->isRecorded() ? 'info' : 'warning', "Mod referential: download ({$origin}) → {$result->status}.", [
            'game' => $this->game,
            'sha1' => $sha1,
            'mod_id' => $result->mod?->id,
            'reason' => $result->reason,
        ]);
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
