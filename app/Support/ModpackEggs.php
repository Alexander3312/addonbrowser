<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

/**
 * (game, platform) -> id of the egg dedicated to modpack installs (see
 * architecture-addonbrowser-addon.md §6).
 *
 * The ids are panel-specific, so they come from the panel's `.env`, through
 * config/addonbrowser.php (`modpack_eggs`), e.g.
 * `ADDONBROWSER_MINECRAFT_MODPACK_EGG_MODRINTH=26` — never from the code, and
 * never overwritten by an addon update. No id set → modpack installs are off
 * for that (game, platform). See the README, "Setting up modpack installs".
 *
 * One egg per (game, platform): each source platform has its own install tool
 * and variables (see ModpackInstallService::buildEnvironment()):
 * - `modrinth`: an egg that installs a .mrpack with mrpack-install
 *   (MODRINTH_PROJECT_ID / MODRINTH_VERSION_ID = the version_number);
 * - `curseforge`: an egg that installs a full CurseForge modpack (mods +
 *   loader + overrides), with the API key as an admin-only variable
 *   (PROJECT_ID / VERSION_ID = the numeric fileId).
 * Both must wipe the server at the start of their install script.
 *
 * Grants access to NOTHING on its own: a configured id isn't enough
 * to use it — it must also be whitelisted in eggswitch
 * (`EggWhitelist::isAllowedForAddon()`), which stays the only authority on
 * what's actually allowed. See ModpackInstallService::isAvailable().
 */
class ModpackEggs
{
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

        $map = config('addonbrowser.modpack_eggs');
        $id = is_array($map) ? ($map[$game][$platform] ?? null) : null;
        if (is_string($id) && ctype_digit(trim($id))) {
            $id = (int) trim($id);
        }

        // A positive integer, or nothing (unset, empty or malformed value).
        return is_int($id) && $id > 0 && $id <= 4294967295 ? $id : null;
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
