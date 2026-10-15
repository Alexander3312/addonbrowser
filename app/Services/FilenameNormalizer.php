<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use InvalidArgumentException;

/**
 * Normalization of mod filenames (installed-mods detection): a stable key for
 * the same mod across its versions and loader variants.
 * `sodium-fabric-0.5.8+mc1.20.1.jar` and `sodium-fabric-mc1.20.1-0.5.8.jar`
 * both give `sodium`.
 *
 * Algorithm:
 * 1. strip the `.disabled` suffix if present (disabled file);
 * 2. strip the extension (`extensions` of the token set, `.jar` for Minecraft);
 * 3. lowercase;
 * 4. remove segments in brackets or parentheses: `[1.20.1]`, `(fabric)`;
 * 5. split on the `-` `_` `+` separators and whitespace, no empty token;
 * 6. remove every VERSION token, wherever it is;
 * 7. on the remaining list, remove LOADER, QUALIFIER and INTEGER tokens,
 *    **except the first one**: some mods have a loader in their name
 *    (`fabric-api`, `forgeconfigapiport`), and versions are removed before
 *    the first token is determined (`1.20.1-fabric-api-0.92.0.jar` →
 *    `fabric-api`);
 * 8. join the remaining tokens with `-`;
 * 9. empty result → original name, lowercase, without extension.
 *
 * A token is only removed if it matches **in full** (`kotlinforforge` isn't
 * touched). INTEGER = digits only (build numbers: `Quark-4.0-460.jar`); `ae2`,
 * `2x2crafting`, `e4mc` aren't.
 *
 * The token lists are game-specific: stored in TOKENS by normalizer name,
 * chosen per game in gameConfig.json (`installed_detection.normalizer`, read
 * by AddonGameMapService::normalizer()), never by a test on the game's name.
 *
 * Unknown normalizer: exception (InvalidArgumentException), never a fallback
 * on a generic normalization. A key computed with the wrong rules would give
 * false "installed" results once recorded in the referential; the caller
 * checks with has() and neither records nor looks up anything without a
 * normalizer.
 *
 * Aligned with Python's Unicode string semantics (the reference these rules
 * follow) when the name is valid UTF-8:
 * - lowercase through mb_strtolower() (full case mapping, final sigma
 *   included, like str.lower());
 * - `/u` regexes: `\d` = Unicode digits (INTEGER included: `١٢` is an integer
 *   for Python);
 * - whitespace: the exact list of Python's `\s` (UNICODE_SPACES), not PCRE's
 *   `\s`, which differs (U+001C to U+001F missing, U+180E extra).
 * Known remaining gap: 20 Unicode 15 digits (Kawi and Nag Mundari scripts)
 * that the PCRE2 tables bundled with PHP may not know yet.
 *
 * Invalid UTF-8 name (impossible to represent in Python): works on bytes,
 * regexes without `/u`, ASCII-only lowercasing; every separator is ASCII, so
 * the name is still split without error.
 */
class FilenameNormalizer
{
    /** Suffix of a disabled file (`iris-mc1.20.1-1.6.4.jar.disabled`). */
    public const DISABLED_SUFFIX = '.disabled';

    /**
     * Whitespace in the sense of Python's `\s` (str.isspace()), for a PCRE
     * character class in `/u` mode.
     */
    private const UNICODE_SPACES = '\t\n\x0B\f\r\x1C-\x1F \x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    /**
     * Token sets, by normalizer name:
     * - extensions: extensions stripped at step 2 (without the dot). Must
     *   cover the `installed_detection.extensions` of the games using this
     *   normalizer;
     * - versions: patterns of a VERSION token (one match is enough), without
     *   modifier (`u` is added for a valid UTF-8 name);
     * - loaders, qualifiers: LOADER and QUALIFIER tokens, lowercase.
     */
    public const TOKENS = [
        'minecraft' => [
            'extensions' => ['jar'],
            'versions' => [
                // Contains a dotted number: 0.5.8, mc1.20.1, 1.20.1, 0.5.1.f…
                '/\d+\.\d+/',
                // v1, v12
                '/^v\d+$/',
                // mc followed by a digit: mc1.20.1, mc26.2
                '/^mc\d/',
                // Numbered pre-releases: beta.1, rc2, build.1, snapshot3…
                '/^(alpha|beta|pre|rc|build|snapshot)\.?\d+$/',
            ],
            'loaders' => ['fabric', 'forge', 'neoforge', 'quilt'],
            'qualifiers' => ['mc', 'minecraft', 'all', 'universal', 'release', 'alpha', 'beta', 'snapshot', 'pre', 'rc', 'dev'],
        ],
    ];

    /** Does the normalizer exist? (null or empty string: no) */
    public function has(?string $normalizer): bool
    {
        return $normalizer !== null && $normalizer !== '' && isset(self::TOKENS[$normalizer]);
    }

    /**
     * Normalized key of a filename, and a "disabled" flag.
     *
     * @return array{key: string, disabled: bool}
     *
     * @throws InvalidArgumentException unknown normalizer
     */
    public function normalize(string $filename, string $normalizer): array
    {
        if (! $this->has($normalizer)) {
            throw new InvalidArgumentException("Unknown filename normalizer: {$normalizer}");
        }
        $tokens = self::TOKENS[$normalizer];

        // 1. .disabled suffix
        $name = $filename;
        $disabled = str_ends_with(strtolower($name), self::DISABLED_SUFFIX);
        if ($disabled) {
            $name = substr($name, 0, -strlen(self::DISABLED_SUFFIX));
        }

        // 2. Extension (the first one that matches)
        foreach ($tokens['extensions'] as $extension) {
            $suffix = '.' . strtolower($extension);
            if (str_ends_with(strtolower($name), $suffix)) {
                $name = substr($name, 0, -strlen($suffix));
                break;
            }
        }

        // 3. Lowercase (Unicode if possible, bytes otherwise; see the header)
        $unicode = mb_check_encoding($name, 'UTF-8');
        $u = $unicode ? 'u' : '';
        $lower = $unicode ? mb_strtolower($name, 'UTF-8') : strtolower($name);

        // 4. Segments in brackets or parentheses
        $stripped = preg_replace('/\[[^\]]*\]|\([^)]*\)/' . $u, ' ', $lower) ?? $lower;

        // 5. Splitting
        $separators = $unicode ? '/[-_+' . self::UNICODE_SPACES . ']+/u' : '/[-_+\s]+/';
        $parts = preg_split($separators, $stripped, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // 6. VERSION tokens, wherever they are
        $parts = array_values(array_filter($parts, fn (string $part) => ! $this->isVersion($part, $tokens['versions'], $u)));

        // 7. LOADER, QUALIFIER, INTEGER, except the first token
        $kept = [];
        foreach ($parts as $i => $part) {
            $removable = in_array($part, $tokens['loaders'], true)
                || in_array($part, $tokens['qualifiers'], true)
                || preg_match('/^\d+$/' . $u, $part) === 1;

            if ($i === 0 || ! $removable) {
                $kept[] = $part;
            }
        }

        // 8. and 9.
        $key = implode('-', $kept);

        return ['key' => $key !== '' ? $key : $lower, 'disabled' => $disabled];
    }

    /**
     * Shortcut: the key alone.
     *
     * @throws InvalidArgumentException unknown normalizer
     */
    public function key(string $filename, string $normalizer): string
    {
        return $this->normalize($filename, $normalizer)['key'];
    }

    /**
     * @param string[] $patterns patterns without modifier (see TOKENS)
     * @param string $modifiers `u` for a valid UTF-8 name, '' otherwise
     */
    private function isVersion(string $token, array $patterns, string $modifiers): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern . $modifiers, $token) === 1) {
                return true;
            }
        }

        return false;
    }
}
