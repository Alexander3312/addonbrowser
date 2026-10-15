<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

/**
 * Recommends a Docker image (Java version) among those of a Minecraft egg,
 * from a target Minecraft version — used to pre-fill a sensible choice when
 * installing a modpack (the required Minecraft version is already known from
 * Modrinth's/CurseForge's game_versions), without forcing it:
 * ModpackInstallService remains free to pass another image from the same list
 * (see install()).
 *
 * Lives HERE on purpose, not in the egg-switching addon: that one is generic,
 * it knows nothing about Minecraft or about "game versions". It's up to
 * ModpackInstallService, which already knows the game, the platform and the
 * modpack, to settle on a concrete choice and pass an already-decided
 * `dockerImage` to EggSwitchRequestService::requestReinstallForAddon().
 *
 * **Hardcoded values, to update by hand**: THRESHOLDS and FALLBACK_LABEL
 * follow the Java versions Minecraft requires; when a new Minecraft release
 * moves to another Java, this is where it goes (see the README). The labels of
 * the target egg's `docker_images` must start with "Java <n>" (e.g. "Java 21
 * (MC 1.20.5 - 1.21.x)"): an egg whose labels don't follow this format simply
 * gets no recommendation. Pure function over the docker_images array already
 * loaded by the caller, reads and writes nothing in the database.
 */
class MinecraftJavaImages
{
    /**
     * Ascending thresholds: the first entry whose ceiling is >= the requested
     * version gives the label to use. Past the last ceiling, FALLBACK_LABEL is
     * used. Hardcoded values (see the class header).
     */
    private const THRESHOLDS = [
        ['max' => '1.16.99', 'label' => 'Java 8'],
        ['max' => '1.17.99', 'label' => 'Java 16'],
        ['max' => '1.20.4', 'label' => 'Java 17'],
        ['max' => '1.21.99', 'label' => 'Java 21'],
    ];

    private const FALLBACK_LABEL = 'Java 25';

    /**
     * Guesses the "main" Minecraft version of a modpack file/version from its
     * raw game_versions array (as returned by Modrinth or CurseForge), then
     * recommends an image — in one step, so the caller (the controller) never
     * has to interpret that array itself. game_versions sometimes mixes in
     * non-numeric tags (CurseForge also slips the loader name in, e.g.
     * "Forge"): only what looks like a real version is kept, and the most
     * recent one is taken, the most relevant to pick the Java to use.
     *
     * @param string[] $gameVersions
     */
    public static function recommendFromGameVersions(array $dockerImages, array $gameVersions): ?string
    {
        return self::recommend($dockerImages, self::guessVersion($gameVersions));
    }

    /**
     * @param string[] $gameVersions
     */
    public static function guessVersion(array $gameVersions): ?string
    {
        $numeric = array_values(array_filter($gameVersions, function ($v) {
            return is_string($v) && preg_match('/^\d{1,4}(\.\d{1,4}){0,2}$/', trim($v));
        }));

        if ($numeric === []) {
            return null;
        }

        usort($numeric, fn ($a, $b) => self::compare(self::normalize($a), self::normalize($b)));

        return end($numeric);
    }

    /**
     * @param array<string, string> $dockerImages label => image (e.g. "Java 21 (MC 1.20.5 - 1.21.x)" => "ghcr.io/...")
     * @return string|null The recommended image, or null if $minecraftVersion
     *                      is missing/invalid or if no image in the list
     *                      matches the expected label (the caller must then
     *                      fall back on its own default choice).
     */
    public static function recommend(array $dockerImages, ?string $minecraftVersion): ?string
    {
        $normalized = self::normalize($minecraftVersion);

        if ($normalized === null) {
            return null;
        }

        foreach (self::THRESHOLDS as $threshold) {
            if (self::compare($normalized, self::normalize($threshold['max'])) <= 0) {
                return self::findImageByLabel($dockerImages, $threshold['label']);
            }
        }

        return self::findImageByLabel($dockerImages, self::FALLBACK_LABEL);
    }

    /**
     * "1.20.1" -> [1, 20, 1]; "1.21" -> [1, 21, 0]; "26.1" -> [26, 1, 0].
     * Rejects anything that isn't a sequence of 1 to 3 dot-separated integers
     * (snapshots like "24w14a", branch names, etc.) rather than guessing —
     * no recommendation is better than a wrong one.
     *
     * @return int[]|null
     */
    private static function normalize(?string $version): ?array
    {
        if ($version === null || !preg_match('/^\d{1,4}(\.\d{1,4}){0,2}$/', trim($version))) {
            return null;
        }

        $parts = array_map('intval', explode('.', trim($version)));

        return array_pad($parts, 3, 0);
    }

    /**
     * @param int[] $a
     * @param int[] $b
     */
    private static function compare(array $a, array $b): int
    {
        return $a <=> $b;
    }

    /**
     * Looks, among the keys of $dockerImages, for the one starting with $label
     * followed by a non-digit (so that a future "Java 2" doesn't match
     * "Java 21") — skips "(legacy)" entries: those are alternatives offered for
     * manual selection, never an automatic recommendation.
     */
    private static function findImageByLabel(array $dockerImages, string $label): ?string
    {
        foreach ($dockerImages as $key => $image) {
            if (stripos($key, 'legacy') !== false) {
                continue;
            }

            if (preg_match('/^' . preg_quote($label, '/') . '(?!\d)/i', trim((string) $key))) {
                return $image;
            }
        }

        return null;
    }
}
