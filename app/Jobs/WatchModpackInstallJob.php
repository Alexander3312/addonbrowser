<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\AddonGameMapService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModpackInstallService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModReferenceService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\ModpackEggs;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\WingsFileReader;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Watches a modpack install, then **captures** its manifest.
 *
 * Dispatched by ModpackInstallService::install() (last step) with the id of
 * the eggswitch request, the server, the platform and the request date. The
 * native `Server\Installed` event isn't used: it fires even on failure, and
 * only if e-mails are enabled.
 *
 * **Watch** (one run every POLL_SECONDS, through `release`):
 * - request state asked from ModpackInstallService::installState() (the only
 *   class that knows eggswitch): pending (backup, wipe) → `release`;
 *   abandoned (failure, cancellation) → stop;
 * - started (`done` = reinstall started, not finished) → server state:
 *   `installing` → `release`; empty `status` and `installed_at` later than
 *   the request → capture; install failure, suspension, server deleted →
 *   stop. Empty `status` but `installed_at` not later yet → `release`.
 * - Lifetime: LIFETIME after the request (backup ~10 min + a large CurseForge
 *   pack), checked in handle(); `retryUntil` = LIFETIME + RETRY_MARGIN,
 *   because the worker fails a job picked up after `retryUntil` **before**
 *   handle() (row in failed_jobs). No `$tries`: the worker runs with
 *   `--tries=3` and every `release` counts; retryUntil() takes precedence.
 * - No WithoutOverlapping per server: with `dontRelease()`, the watch of a
 *   second install would be lost if the first one still held the lock. An
 *   install only has one watch.
 *
 * **Capture** (as soon as the install is done): listing of the root, then for
 * each manifest of ModpackEggs::manifestsFor() present (regular file, listed
 * size ≤ MAX_MANIFEST_BYTES, otherwise ignored): bounded streamed read
 * (WingsFileReader, cut past the listed size). Only useful, well-formed
 * entries are extracted (a few tens of KB in Redis instead of the raw
 * manifest):
 * - Modrinth: `file` keyed by `<target folder>/<name>` (no subfolder, name
 *   accepted by WingsFileReader::path()) with a SHA1 → `{filename, sha1}`;
 * - CurseForge: `files[]` → `{project_id, file_id}`, deduplicated by `file_id`
 *   across both manifests (the folder isn't given: the disk check, during
 *   processing, rules out the others).
 * Past MAX_ENTRIES entries → manifest ignored. No manifest: nothing, it isn't
 * an error. Then ProcessModpackManifestJob is dispatched with a delay of
 * PROCESS_DELAY (the mods are already on disk).
 *
 * Node unreachable: stop with a log line, no retry. No exception gets out, no
 * failure has any effect on the install. `low` queue.
 */
class WatchModpackInstallJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'low';

    /** Interval between two looks at the request and the server, in seconds. */
    public const POLL_SECONDS = 30;

    /**
     * Lifetime of the watch after the request, in seconds (backup up to
     * ~10 min, and a large CurseForge pack can take over 15 min to install).
     */
    public const LIFETIME = 2700;

    /** retryUntil() margin past LIFETIME (see the class docblock). */
    public const RETRY_MARGIN = 3600;

    /** Largest manifest read (2 MB). */
    public const MAX_MANIFEST_BYTES = 2 * 1024 * 1024;

    /** Longest read of a manifest, in seconds. */
    public const READ_SECONDS = 10;

    /** Most entries in a manifest; past that, it's ignored. */
    public const MAX_ENTRIES = 1000;

    /**
     * Delay before processing, in seconds: short, because the Addons tab is
     * opened right after the install, before the hash-based verification
     * takes over.
     */
    public const PROCESS_DELAY = 60;

    private const SHA1 = '/^[0-9a-f]{40}$/';

    private const UINT32_MAX = 4294967295;

    public function __construct(
        public readonly int $pendingId,
        public readonly string $serverUuid,
        public readonly string $platform,
        public readonly int $requestedAt,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function retryUntil(): \DateTimeInterface
    {
        return (new \DateTimeImmutable())->setTimestamp($this->requestedAt + self::LIFETIME + self::RETRY_MARGIN);
    }

    public function handle(): void
    {
        try {
            $this->watch();
        } catch (\Throwable $ex) {
            // isset() / ??: never an error here, even on an incompletely
            // unserialized payload (uninitialized typed property).
            self::log('warning', 'Modpack: install watch given up (unexpected error).', [
                'server' => $this->serverUuid ?? null,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    private function watch(): void
    {
        $context = ['server' => $this->serverUuid, 'pending' => $this->pendingId];

        if (time() > $this->requestedAt + self::LIFETIME) {
            self::log('warning', 'Modpack: install still not finished at the end of the watch, manifest not read.', $context);

            return;
        }

        $state = app(ModpackInstallService::class)->installState($this->pendingId);
        if ($state === ModpackInstallService::STATE_ABANDONED) {
            self::log('info', 'Modpack: install abandoned (failure, cancellation or request gone), watch stopped.', $context);

            return;
        }
        if ($state === ModpackInstallService::STATE_PENDING) {
            $this->again('request pending');

            return;
        }

        $server = $this->findServer();
        if ($server === null) {
            self::log('info', 'Modpack: server deleted, watch stopped.', $context);

            return;
        }
        if ($server->status === Server::STATUS_INSTALLING) {
            $this->again('install in progress');

            return;
        }
        if ($server->status !== null) {
            self::log('info', 'Modpack: install finished without success (or server suspended), manifest not read.', $context + ['status' => $server->status]);

            return;
        }
        if ($server->installed_at === null || $server->installed_at->getTimestamp() <= $this->requestedAt) {
            $this->again('end of install not reported yet');

            return;
        }

        $this->capture($server);
    }

    /** Watched server, read again on every run (up-to-date status and install date). */
    protected function findServer(): ?Server
    {
        return Server::query()->where('uuid', $this->serverUuid)->with('egg.nest')->first();
    }

    /**
     * Another look in POLL_SECONDS, unless the next run would fall after the
     * end of life (clean stop with a log line).
     */
    private function again(string $why): void
    {
        if (time() + self::POLL_SECONDS > $this->requestedAt + self::LIFETIME) {
            self::log('warning', "Modpack: end of the watch ({$why}), manifest not read.", ['server' => $this->serverUuid, 'pending' => $this->pendingId]);

            return;
        }

        self::log('debug', "Modpack: watching, {$why}.", ['server' => $this->serverUuid, 'pending' => $this->pendingId]);
        $this->release(self::POLL_SECONDS);
    }

    private function capture(Server $server): void
    {
        $context = ['server' => $server->uuid, 'platform' => $this->platform];

        // Game checked again from the nest, detection required: nothing comes from the payload.
        $nestName = $server->egg?->nest?->name;
        $game = is_string($nestName) ? app(AddonGameMapService::class)->resolveGameFromNestName($nestName) : null;
        $manifests = ModpackEggs::manifestsFor($this->platform);
        if ($game === null || ! app(ModReferenceService::class)->acceptsGame($game) || $manifests === []) {
            self::log('info', 'Modpack: game without detection or platform without a manifest, nothing to read.', $context);

            return;
        }
        $context['game'] = $game;

        $repo = app(DaemonFileRepository::class)->setServer($server);
        try {
            $root = $repo->getDirectory('/');
        } catch (\Throwable $ex) {
            self::log('warning', 'Modpack: server root unreadable (node unreachable?), manifest not read.', $context + ['error' => $ex->getMessage()]);

            return;
        }

        $sizes = [];
        foreach (is_array($root) ? $root : [] as $entry) {
            if (is_array($entry) && ($entry['file'] ?? null) === true && empty($entry['directory']) && empty($entry['symlink'])
                && is_string($entry['name'] ?? null) && is_int($entry['size'] ?? null)) {
                $sizes[$entry['name']] = $entry['size'];
            }
        }

        $reader = app(WingsFileReader::class);
        $folder = app(AddonGameMapService::class)->targetFolder($game);
        $extensions = app(AddonGameMapService::class)->installedDetectionExtensions($game);
        $entries = [];
        foreach ($manifests as $manifest) {
            if (! isset($sizes[$manifest])) {
                continue;
            }
            if ($sizes[$manifest] > self::MAX_MANIFEST_BYTES) {
                self::log('warning', 'Modpack: manifest too large, ignored.', $context + ['manifest' => $manifest, 'size' => $sizes[$manifest]]);

                continue;
            }

            $content = '';
            $status = $reader->read($server, '/' . $manifest, $sizes[$manifest], function (string $chunk) use (&$content) {
                $content .= $chunk;
            }, self::READ_SECONDS);
            if ($status === WingsFileReader::READ_UNREACHABLE) {
                self::log('warning', 'Modpack: node unreachable while reading the manifest, nothing done.', $context + ['manifest' => $manifest]);

                return;
            }
            if ($status !== WingsFileReader::READ_OK) {
                self::log('warning', 'Modpack: manifest unreadable, ignored.', $context + ['manifest' => $manifest, 'read' => $status]);

                continue;
            }

            $json = json_decode($content, true, 64);
            unset($content);
            $extracted = is_array($json)
                ? ($this->platform === 'modrinth' ? self::modrinthEntries($json, $folder, $extensions) : self::curseForgeEntries($json))
                : null;
            if ($extracted === null) {
                self::log('warning', 'Modpack: malformed manifest or too many entries, ignored.', $context + ['manifest' => $manifest]);

                continue;
            }
            // Deduplication across manifests (CurseForge: server pack and client pack).
            $entries += $extracted;
        }

        if ($entries === []) {
            self::log('info', 'Modpack: no usable manifest, nothing to record.', $context);

            return;
        }
        if (count($entries) > self::MAX_ENTRIES) {
            self::log('warning', 'Modpack: too many entries in the manifests, ignored.', $context + ['entries' => count($entries)]);

            return;
        }

        Bus::dispatch((new ProcessModpackManifestJob($server->uuid, $this->platform, array_values($entries)))
            ->delay(self::PROCESS_DELAY));

        self::log('info', 'Modpack: manifest captured, processing queued.', $context + ['entries' => count($entries)]);
    }

    /**
     * Useful entries of `modpack.json` (mrpack-install): files directly in the
     * target folder (`<folder>/<name>`), name accepted by
     * WingsFileReader::path() for the detection extensions, hexadecimal SHA1.
     * Keyed by name. Null if the manifest doesn't have the expected shape or
     * has more than MAX_ENTRIES entries in the target folder.
     *
     * @param string[] $extensions
     * @return array<string, array{filename: string, sha1: string}>|null
     */
    public static function modrinthEntries(array $json, string $folder, array $extensions): ?array
    {
        $files = $json['file'] ?? null;
        if (! is_array($files)) {
            return null;
        }

        $prefix = trim($folder, '/') . '/';
        $entries = [];
        foreach ($files as $path => $file) {
            if (! is_string($path) || ! str_starts_with($path, $prefix)) {
                continue;
            }
            $name = substr($path, strlen($prefix));
            $sha1 = is_array($file) && is_string($file['Hash'] ?? null) ? strtolower($file['Hash']) : null;
            if ($sha1 === null || preg_match(self::SHA1, $sha1) !== 1
                || WingsFileReader::path($folder, $name, $extensions) === null
                || str_ends_with(strtolower($name), '.disabled')) {
                continue;
            }
            $entries[$name] = ['filename' => $name, 'sha1' => $sha1];
            if (count($entries) > self::MAX_ENTRIES) {
                return null;
            }
        }

        return $entries;
    }

    /**
     * Useful entries of a CurseForge manifest: `files[]` with integer
     * `projectID` and `fileID` within bounds. Keyed by `cf<file_id>`. Null if the
     * manifest doesn't have the expected shape or has more than MAX_ENTRIES
     * entries.
     *
     * @return array<string, array{project_id: int, file_id: int}>|null
     */
    public static function curseForgeEntries(array $json): ?array
    {
        $files = $json['files'] ?? null;
        if (! is_array($files) || ! array_is_list($files) || count($files) > self::MAX_ENTRIES) {
            return null;
        }

        $entries = [];
        foreach ($files as $file) {
            $projectId = is_array($file) ? ($file['projectID'] ?? null) : null;
            $fileId = is_array($file) ? ($file['fileID'] ?? null) : null;
            if (! is_int($projectId) || $projectId <= 0 || $projectId > self::UINT32_MAX
                || ! is_int($fileId) || $fileId <= 0 || $fileId > self::UINT32_MAX) {
                continue;
            }
            $entries['cf' . $fileId] = ['project_id' => $projectId, 'file_id' => $fileId];
        }

        return $entries;
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
