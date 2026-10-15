<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

/**
 * Static mapping (game, platform) -> id of the egg dedicated to modpack
 * installs (see architecture-addonbrowser-addon.md §6).
 *
 * **Hardcoded values, specific to the panel the addon was developed on**: the
 * ids in MAP are only examples, to replace with the ids of your own panel's
 * modpack eggs (see the README, "Setting up modpack installs"). These eggs
 * aren't shipped with the addon.
 *
 * A two-key table rather than a single constant: one game has one modpack egg
 * per source platform, each with its own install tool.
 *
 * Grants access to NOTHING on its own: an id being listed here isn't enough
 * to use it — it must also be whitelisted in eggswitch
 * (`EggWhitelist::isAllowedForAddon()`), which stays the only authority on
 * what's actually allowed. See ModpackInstallService::isAvailable().
 */
class ModpackEggs
{
    /**
     * @var array<string, array<string, int>>
     */
    private const MAP = [
        'minecraft' => [
            // Example: id of an egg that installs a .mrpack with mrpack-install
            // (MODRINTH_PROJECT_ID / MODRINTH_VERSION_ID).
            'modrinth' => 26,
            // Example: id of an egg that installs a full CurseForge modpack
            // (mods + loader + overrides), wiping the server at the start of
            // script_install, with the API key as an admin-only variable. See
            // ModpackInstallService::buildEnvironment() for the expected format
            // (PROJECT_ID/VERSION_ID, VERSION_ID = numeric fileId, not a
            // filename).
            'curseforge' => 18,
        ],
    ];

    /**
     * Manifests left at the server root by the install script of each
     * platform's modpack egg, read after the install by WatchModpackInstallJob.
     * Their format depends on the egg's install tool, not on the game:
     * - `modrinth` (mrpack-install): `modpack.json`, `file` object keyed by
     *   path (`mods/<name>.jar` → SHA1 `Hash`);
     * - `curseforge`: `manifest.json` (server pack) and/or `client.manifest.json`
     *   (client pack renamed by the script), `files` array of `projectID` /
     *   `fileID` pairs.
     *
     * @var array<string, string[]>
     */
    private const MANIFESTS = [
        'modrinth' => ['modpack.json'],
        'curseforge' => ['manifest.json', 'client.manifest.json'],
    ];

    public static function eggIdFor(string $game, string $platform): ?int
    {
        $game = strtolower(trim($game));
        $platform = strtolower(trim($platform));

        return self::MAP[$game][$platform] ?? null;
    }

    /**
     * Names of the manifests (at the server root) of this platform's modpack
     * egg; [] if none.
     *
     * @return string[]
     */
    public static function manifestsFor(string $platform): array
    {
        return self::MANIFESTS[strtolower(trim($platform))] ?? [];
    }
}
