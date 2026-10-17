<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;

class AddonGameMapService
{
    /**
     * Load the single source-of-truth configuration for available games.
     *
     * If the file is missing or invalid (incomplete install, corrupted
     * JSON...), the whole addon doesn't crash with an exception: it falls back
     * on an empty configuration, logged as an error. Every getter of this
     * service already has its own fallback (platforms, target folder,
     * extensions...), so an empty config degrades the behavior cleanly (game
     * not recognized, default folder, etc.) instead of a 500 error on every
     * route of the addon.
     */
    private function config(): array
    {
        static $config = null;

        if ($config !== null) {
            return $config;
        }

        $path = base_path('.blueprint/extensions/addonbrowser/components/server/addon/gameConfig.json');
        $raw = @file_get_contents($path);

        if ($raw === false) {
            AddonBrowserLogger::error("Addon browser game config file could not be loaded ({$path}), falling back on an empty configuration.");
            return $config = [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            AddonBrowserLogger::error("Addon browser game config is invalid JSON ({$path}), falling back on an empty configuration.");
            return $config = [];
        }

        return $config = $decoded;
    }

    /**
     * Return the platforms available for a game (in order: the first one is
     * the default), from the "platforms" field of gameConfig.json. Most games
     * have only one (CurseForge); Minecraft has two ("platforms": ["modrinth",
     * "curseforge"]) — on the CurseForge side, the Minecraft classId is forced
     * by the server: Mods (6) in mods mode, Modpacks (4471) in modpacks mode
     * (see AddonBrowserController::resolveCurseForgeClassId()). CurseForge
     * modpacks are installed through the CurseForge modpack egg (ModpackEggs).
     *
     * @return string[]
     */
    public function availablePlatforms(string $game): array
    {
        $normalizedGame = strtolower($game);
        $entry = $this->config()[$normalizedGame] ?? [];

        return is_array($entry['platforms'] ?? null) && $entry['platforms'] !== []
            ? $entry['platforms']
            : ['curseforge'];
    }

    public function defaultPlatform(string $game): string
    {
        return $this->availablePlatforms($game)[0];
    }

    /**
     * Download target folder for a game (`target_folder` in gameConfig.json).
     * One folder per game: addonbrowser only handles mods (and modpacks, which
     * go through a reinstall, not through this folder). Never chosen by the
     * client.
     */
    public function targetFolder(string $game): string
    {
        $folder = $this->config()[strtolower($game)]['target_folder'] ?? null;

        return is_string($folder) && $folder !== '' ? trim($folder, '/\\') : 'mods';
    }

    /**
     * Extensions allowed for download for a game (`allowed_extensions` in
     * gameConfig.json, a list). Every configured game declares it: a game that
     * doesn't allows **nothing** (empty list), rather than a permissive
     * fallback.
     *
     * @return string[]
     */
    public function allowedExtensions(string $game): array
    {
        $configured = $this->config()[strtolower($game)]['allowed_extensions'] ?? null;

        return is_array($configured) && array_is_list($configured)
            ? array_map('strtolower', $configured)
            : [];
    }

    /**
     * Provider of the game's versions (`game_versions` in gameConfig.json):
     * list for the "Game Version" filter and detection of a server's version,
     * see GameVersionService. Null = no version filter for this game.
     */
    public function gameVersionProvider(string $game): ?string
    {
        $provider = $this->config()[strtolower($game)]['game_versions'] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /**
     * Loaders offered in the search filter (`loaders` in gameConfig.json), in
     * that order. Only the main loaders for Minecraft (Fabric, Forge,
     * NeoForge, Quilt): the platforms list many more, rare ones (babric,
     * ornithe, rift, liteloader…). Empty list = no loader filter for this game.
     *
     * @return string[]
     */
    public function loaders(string $game): array
    {
        $loaders = $this->config()[strtolower($game)]['loaders'] ?? null;

        return is_array($loaders) && array_is_list($loaders)
            ? array_values(array_filter(array_map('strtolower', $loaders), 'is_string'))
            : [];
    }

    /**
     * The game's filename normalizer (`installed_detection.normalizer` in
     * gameConfig.json, see FilenameNormalizer::TOKENS). Null = game without
     * installed-mods detection: ModReferenceService then records nothing for
     * this game (no normalized key can be computed).
     */
    public function normalizer(string $game): ?string
    {
        $normalizer = $this->config()[strtolower($game)]['installed_detection']['normalizer'] ?? null;

        return is_string($normalizer) && $normalizer !== '' ? $normalizer : null;
    }

    /**
     * Extensions of the files covered by the installed-mods detection
     * (`installed_detection.extensions` in gameConfig.json, e.g. `["jar"]`),
     * lowercase. Only these files enter the referential (a `.zip` downloaded
     * into `mods` is never a detected mod). Empty list = game without
     * detection.
     *
     * @return string[]
     */
    public function installedDetectionExtensions(string $game): array
    {
        $extensions = $this->config()[strtolower($game)]['installed_detection']['extensions'] ?? null;

        return is_array($extensions) && array_is_list($extensions)
            ? array_values(array_map('strtolower', array_filter($extensions, fn ($e) => is_string($e) && $e !== '')))
            : [];
    }

    /**
     * Does the filename have one of this game's installed-mods detection
     * extensions? (last extension, case-insensitive)
     */
    public function hasInstalledDetectionExtension(string $game, string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, $this->installedDetectionExtensions($game), true);
    }

    /**
     * The game's CurseForge gameId (`curseforge.game_id` in gameConfig.json),
     * or null if it has none (no CurseForge search for this game).
     */
    public function curseForgeGameId(string $game): ?int
    {
        return self::positiveId($this->config()[strtolower($game)]['curseforge']['game_id'] ?? null);
    }

    /**
     * The game's CurseForge mod classes (`curseforge.mod_class_ids` in
     * gameConfig.json), see CurseForgeService::modClassIds(). Invalid ids are
     * dropped; empty list = no mods offered on CurseForge for this game.
     *
     * @return int[]
     */
    public function curseForgeModClassIds(string $game): array
    {
        $ids = $this->config()[strtolower($game)]['curseforge']['mod_class_ids'] ?? null;

        return is_array($ids) && array_is_list($ids)
            ? array_values(array_filter(array_map([self::class, 'positiveId'], $ids), 'is_int'))
            : [];
    }

    /**
     * The game's CurseForge modpack class (`curseforge.modpack_class_id` in
     * gameConfig.json), or null if it has none.
     */
    public function curseForgeModpackClassId(string $game): ?int
    {
        return self::positiveId($this->config()[strtolower($game)]['curseforge']['modpack_class_id'] ?? null);
    }

    /** A positive integer id (unsigned 32-bit), or null. */
    private static function positiveId(mixed $value): ?int
    {
        return is_int($value) && $value > 0 && $value <= 4294967295 ? $value : null;
    }

    public function resolveGameFromNestName(string $nestName): ?string
    {
        $normalizedNest = strtolower(trim($nestName));

        foreach ($this->config() as $gameKey => $entry) {
            $nestNames = array_map('strtolower', $entry['nest_names'] ?? []);
            if (in_array($normalizedNest, $nestNames, true)) {
                return $gameKey;
            }
        }

        return null;
    }

    public function isConfigured(string $game): bool
    {
        return isset($this->config()[strtolower($game)]);
    }

    /**
     * Keys of every game in gameConfig.json, lowercase, in file order (admin
     * screen: games with detection enabled).
     *
     * @return string[]
     */
    public function games(): array
    {
        return array_values(array_filter(
            array_map(fn ($key) => strtolower((string) $key), array_keys($this->config())),
            fn (string $key) => $key !== ''
        ));
    }
}
