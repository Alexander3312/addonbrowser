<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\ExternalApi;

/**
 * Game versions: the list offered in the "Game Version" filter, and detection
 * of what's installed on a server (game version and loader), to pre-select
 * the "Game Version" and "Loader" filters (never to force them: the user
 * stays free).
 *
 * The provider is chosen per game in gameConfig.json (`game_versions`), not by
 * a test on the game's name: only `minecraft` exists today. A game without a
 * provider has neither a list nor detection, and the frontend then hides the
 * filter.
 */
class GameVersionService
{
    /** Format of a Minecraft version: 1.21.1, 1.12.2, 26.2… */
    private const MINECRAFT_VERSION = '/^\d+\.\d+(\.\d+)?$/';

    public function __construct(
        private AddonGameMapService $gameMapService,
        private DaemonFileRepository $fileRepository
    ) {
    }

    /**
     * Versions offered in the filter, from newest to oldest.
     *
     * @return string[]
     */
    public function listVersions(string $game): array
    {
        return match ($this->gameMapService->gameVersionProvider($game)) {
            'minecraft' => $this->minecraftVersions(),
            default => [],
        };
    }

    /**
     * Game version and loader installed on the server (each null if it couldn't
     * be determined). Only reads what's actually installed (modpack manifest,
     * folders created by the loader): the egg's variables aren't reliable
     * (often `latest`, only resolved at install time).
     *
     * Cached: the key contains the egg and the install date, so the result is
     * recomputed by itself after a reinstall or an egg change. An incomplete
     * result is only kept for 10 minutes (a server that was never started
     * doesn't have its loader's folders yet).
     *
     * @return array{version: ?string, version_source: ?string, loader: ?string, loader_source: ?string}
     */
    public function detectServerEnvironment(Server $server, string $game): array
    {
        $unknown = ['version' => null, 'version_source' => null, 'loader' => null, 'loader_source' => null];

        if ($this->gameMapService->gameVersionProvider($game) !== 'minecraft') {
            return $unknown;
        }

        $key = sprintf(
            'addonbrowser_server_env_%s_%s_%s',
            $server->uuid,
            $server->egg_id,
            optional($server->installed_at)->timestamp ?? 0
        );

        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('loader', $cached)) {
            return $cached;
        }

        $result = $this->detectMinecraftEnvironment($server) + $unknown;
        $complete = $result['version'] !== null && $result['loader'] !== null;
        Cache::put($key, $result, $complete ? now()->addDay() : now()->addMinutes(10));

        return $result;
    }

    /**
     * @return string[]
     */
    private function minecraftVersions(): array
    {
        return Cache::remember('addonbrowser_minecraft_release_versions', now()->addHours(12), function () {
            $response = ExternalApi::interactive(ExternalApi::modrinthHeaders())->get('https://api.modrinth.com/v2/tag/game_version');

            if (! $response->successful()) {
                return [];
            }

            // Official releases only (no snapshots or pre-releases), in the
            // order returned by Modrinth: from newest to oldest.
            return collect($response->json())
                ->filter(fn ($v) => ($v['version_type'] ?? null) === 'release')
                ->pluck('version')
                ->filter(fn ($v) => is_string($v) && preg_match(self::MINECRAFT_VERSION, $v))
                ->values()
                ->all();
        });
    }

    /**
     * Sources, in order (for each piece of information, the first one that
     * answers wins):
     * 1. modpack manifest at the root: `modpack.json` (mrpack-install:
     *    `dependencies.minecraft`, and the loader whose version is set among
     *    `fabric-loader`, `quilt-loader`, `forge`, `neoforge`),
     *    `manifest.json` / `client.manifest.json` (CurseForge:
     *    `minecraft.version`, `minecraft.modLoaders[].id` of the `forge-…` kind);
     * 2. folders created by the loader:
     *    - version: `libraries/net/minecraft/server/<v>` (Forge/NeoForge
     *      1.17+), `libraries/net/minecraftforge/forge/<v>-…` (old Forge),
     *      `libraries/net/fabricmc/intermediary/<v>` (Fabric/Quilt),
     *      `versions/<v>` (Vanilla, Paper… since 1.18),
     *      `.fabric/server/<v>-server.jar`;
     *    - loader: `libraries/org/quiltmc/quilt-loader` (Quilt, checked before
     *      Fabric because a Quilt server may also contain Fabric libraries),
     *      `libraries/net/neoforged` (NeoForge, checked before Forge),
     *      `libraries/net/minecraftforge/forge` (Forge),
     *      `libraries/net/fabricmc/fabric-loader` or `.fabric/` (Fabric).
     *
     * Node unreachable or folder missing: move on to the next source, without
     * an error (the tab is never blocked because of this).
     *
     * @return array{version: ?string, version_source: ?string, loader: ?string, loader_source: ?string}
     */
    private function detectMinecraftEnvironment(Server $server): array
    {
        $result = ['version' => null, 'version_source' => null, 'loader' => null, 'loader_source' => null];

        $repo = $this->fileRepository->setServer($server);
        $list = function (string $path) use ($repo): array {
            try {
                return collect($repo->getDirectory($path))->pluck('name')->filter()->values()->all();
            } catch (\Throwable $ex) {
                return [];
            }
        };

        $root = $list('/');
        if ($root === []) {
            return $result;
        }

        // 1. Modpack manifest (small file; size bounded on read).
        foreach (['modpack.json', 'manifest.json', 'client.manifest.json'] as $manifest) {
            if (! in_array($manifest, $root, true)) {
                continue;
            }
            try {
                $json = json_decode($repo->getContent('/' . $manifest, 2 * 1024 * 1024), true);
            } catch (\Throwable $ex) {
                AddonBrowserLogger::debug('Server environment detection: manifest unreadable', ['server' => $server->uuid, 'file' => $manifest]);
                continue;
            }
            if (! is_array($json)) {
                continue;
            }

            $version = $json['dependencies']['minecraft'] ?? $json['minecraft']['version'] ?? null;
            if ($result['version'] === null && is_string($version) && preg_match(self::MINECRAFT_VERSION, $version)) {
                $result['version'] = $version;
                $result['version_source'] = $manifest;
            }

            $loader = $this->loaderFromManifest($json);
            if ($result['loader'] === null && $loader !== null) {
                $result['loader'] = $loader;
                $result['loader_source'] = $manifest;
            }
        }

        // 2. Loader folders.
        $libraries = in_array('libraries', $root, true);

        if ($result['version'] === null) {
            $candidates = [];
            if ($libraries) {
                $candidates['libraries/net/minecraft/server'] = $list('/libraries/net/minecraft/server');
                $candidates['libraries/net/fabricmc/intermediary'] = $list('/libraries/net/fabricmc/intermediary');
                $candidates['libraries/net/minecraftforge/forge'] = array_map(
                    fn ($name) => explode('-', $name)[0],
                    $list('/libraries/net/minecraftforge/forge')
                );
            }
            if (in_array('versions', $root, true)) {
                $candidates['versions'] = $list('/versions');
            }
            if (in_array('.fabric', $root, true)) {
                $candidates['.fabric/server'] = array_map(
                    fn ($name) => preg_replace('/-server\.jar$/', '', $name),
                    $list('/.fabric/server')
                );
            }

            foreach ($candidates as $source => $names) {
                $versions = array_values(array_filter($names, fn ($n) => is_string($n) && preg_match(self::MINECRAFT_VERSION, $n)));
                if ($versions !== []) {
                    // Several versions (leftovers of an earlier install): the newest.
                    usort($versions, fn ($a, $b) => version_compare($b, $a));
                    $result['version'] = $versions[0];
                    $result['version_source'] = $source;
                    break;
                }
            }
        }

        if ($result['loader'] === null) {
            $checks = [];
            if ($libraries) {
                $checks['quilt'] = ['/libraries/org/quiltmc', 'quilt-loader'];
                $checks['neoforge'] = ['/libraries/net', 'neoforged'];
                $checks['forge'] = ['/libraries/net/minecraftforge', 'forge'];
                $checks['fabric'] = ['/libraries/net/fabricmc', 'fabric-loader'];
            }
            foreach ($checks as $loader => [$dir, $entry]) {
                if (in_array($entry, $list($dir), true)) {
                    $result['loader'] = $loader;
                    $result['loader_source'] = ltrim($dir, '/') . '/' . $entry;
                    break;
                }
            }
            if ($result['loader'] === null && in_array('.fabric', $root, true)) {
                $result['loader'] = 'fabric';
                $result['loader_source'] = '.fabric';
            }
        }

        return $result;
    }

    /**
     * Loader of a modpack manifest: mrpack-install's `modpack.json` (keys of
     * `dependencies` whose value is set) or a CurseForge manifest
     * (`minecraft.modLoaders[].id`, e.g. `forge-14.23.5.2854`, the primary one
     * first).
     */
    private function loaderFromManifest(array $json): ?string
    {
        $dependencies = $json['dependencies'] ?? null;
        if (is_array($dependencies)) {
            foreach (['quilt-loader' => 'quilt', 'neoforge' => 'neoforge', 'forge' => 'forge', 'fabric-loader' => 'fabric'] as $key => $loader) {
                if (! empty($dependencies[$key])) {
                    return $loader;
                }
            }
        }

        $modLoaders = $json['minecraft']['modLoaders'] ?? null;
        if (is_array($modLoaders) && $modLoaders !== []) {
            usort($modLoaders, fn ($a, $b) => (int) ! empty($b['primary']) - (int) ! empty($a['primary']));
            $id = strtolower((string) ($modLoaders[0]['id'] ?? ''));
            $prefix = explode('-', $id)[0];

            return in_array($prefix, ['forge', 'neoforge', 'fabric', 'quilt'], true) ? $prefix : null;
        }

        return null;
    }
}
