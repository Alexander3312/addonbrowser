<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

use Illuminate\Database\Query\Expression;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\ModFile;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\Stat;

/**
 * Event statistics of the installed-mods detection: `addonbrowser_stats`
 * table, one total per day, per game and per metric.
 *
 * Each event is counted **exactly once, where it happens**:
 * - hash verification job (Jobs\VerifyInstalledModsJob), one metric per file
 *   leaving the batch: VERIFY_*;
 * - referential writer service (Services\ModReferenceService), after the
 *   transaction commits: MOD_CREATED, MOD_LINKED, MOD_MERGED,
 *   CONFLICT_REFUSED, and `file_added_<source>` (fileAdded()).
 * Never in GET /installed (called each time the tab opens and every 10 s
 * during a verification: it would count reloads), nor in the admin screen.
 *
 * Writing: one upsert per metric, through the Stat model's connection (never
 * DB:: on the default connection): `INSERT … ON DUPLICATE KEY UPDATE
 * total = total + n` on MySQL/MariaDB, `INSERT … ON CONFLICT (day, game,
 * metric) DO UPDATE SET total = total + n` on SQLite (the connection's
 * grammar). `n` is a checked integer, never a received value. Day in the
 * application's timezone.
 *
 * **Never makes the caller fail** (same rule as the journal): every error is
 * swallowed, with a warning in the addon's logs.
 */
class AddonStats
{
    // Hash verification job, one metric per processed file.
    public const VERIFY_SHA1_DB = 'verify_sha1_db';
    public const VERIFY_MODRINTH = 'verify_modrinth';
    public const VERIFY_CF_FINGERPRINT = 'verify_cf_fingerprint';
    public const VERIFY_CF_MATCH = 'verify_cf_match';
    public const VERIFY_UNKNOWN = 'verify_unknown';
    public const VERIFY_ABANDONED = 'verify_abandoned';
    public const VERIFY_TOO_LARGE = 'verify_too_large';

    // Referential writer service (ModReferenceService).
    public const MOD_CREATED = 'mod_created';
    public const MOD_LINKED = 'mod_linked';
    public const MOD_MERGED = 'mod_merged';
    public const CONFLICT_REFUSED = 'conflict_refused';

    /** Start of the file-added metrics, followed by the source (ModFile::SOURCES). */
    public const FILE_ADDED_PREFIX = 'file_added_';

    /** Verification job metrics, in display order. */
    public const VERIFY_METRICS = [
        self::VERIFY_SHA1_DB,
        self::VERIFY_MODRINTH,
        self::VERIFY_CF_FINGERPRINT,
        self::VERIFY_CF_MATCH,
        self::VERIFY_UNKNOWN,
        self::VERIFY_ABANDONED,
        self::VERIFY_TOO_LARGE,
    ];

    /** Referential metrics (file additions aside), in display order. */
    public const REFERENCE_METRICS = [
        self::MOD_CREATED,
        self::MOD_LINKED,
        self::MOD_MERGED,
        self::CONFLICT_REFUSED,
    ];

    /** Metric name: lowercase letters, digits and `_`, 64 characters at most (`metric` column). */
    public const METRIC_FORMAT = '/^[a-z0-9_]{1,64}$/';

    /** Accepted game key (`game` column, 191 characters). */
    private const GAME_FORMAT = '/^[a-z0-9][a-z0-9_.\-]{0,190}$/';

    /** Largest increment per call (guard against an absurd value). */
    private const MAX_INCREMENT = 1000000;

    /**
     * Every known metric, in display order: verification, file additions per
     * source, then referential metrics.
     *
     * @return string[]
     */
    public static function metrics(): array
    {
        return array_merge(
            self::VERIFY_METRICS,
            array_map(fn (string $source) => self::fileAdded($source), ModFile::SOURCES),
            self::REFERENCE_METRICS
        );
    }

    /** Metric for a file row added to the referential, per source. */
    public static function fileAdded(string $source): string
    {
        return self::FILE_ADDED_PREFIX . $source;
    }

    /** Adds `$n` to today's metric for a game. Never throws. */
    public static function increment(string $game, string $metric, int $n = 1): void
    {
        self::incrementMany($game, [$metric => $n]);
    }

    /**
     * Adds several of today's metrics for a game (`[metric => n]`), one upsert
     * per metric. Metric with an invalid name or `n` out of bounds: skipped
     * (warning). Never throws: on the first write failure, warning and stop
     * (the following ones would fail the same way).
     *
     * @param array<string, int> $counts
     */
    public static function incrementMany(string $game, array $counts): void
    {
        try {
            $game = strtolower(trim($game));
            if (preg_match(self::GAME_FORMAT, $game) !== 1) {
                self::warn('Statistics: invalid game, nothing counted.', ['game' => $game]);

                return;
            }

            $day = now()->toDateString();
            foreach ($counts as $metric => $n) {
                $metric = (string) $metric;
                if (! is_int($n) || $n === 0) {
                    continue;
                }
                if ($n < 0 || $n > self::MAX_INCREMENT || preg_match(self::METRIC_FORMAT, $metric) !== 1) {
                    self::warn('Statistics: invalid metric, skipped.', ['game' => $game, 'metric' => $metric, 'n' => $n]);
                    continue;
                }

                Stat::query()->toBase()->upsert(
                    [['day' => $day, 'game' => $game, 'metric' => $metric, 'total' => $n]],
                    ['day', 'game', 'metric'],
                    ['total' => new Expression('total + ' . $n)]
                );
            }
        } catch (\Throwable $ex) {
            self::warn('Statistics: write failed, events not counted.', ['game' => $game, 'error' => $ex->getMessage()]);
        }
    }

    /** Warning in the addon's logs, never throwing. */
    private static function warn(string $message, array $context = []): void
    {
        try {
            AddonBrowserLogger::warning($message, $context);
        } catch (\Throwable $ex) {
            // Laravel's log itself is unusable: nothing more to do.
        }
    }
}
