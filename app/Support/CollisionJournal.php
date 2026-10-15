<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Text journal of the installed-mods referential, read by the admin screen: a
 * troubleshooting aid, not data the detection depends on. Separate from the
 * Laravel logs (AddonBrowserLogger), so it can be shown and filtered by type
 * regardless of LOG_LEVEL.
 *
 * One line per event:
 *
 *   2026-10-01T09:30:00+00:00 merge Two mods merged (same SHA1). {"game":"minecraft",…}
 *
 * (ISO 8601 date, type, message, JSON context on the same line). Types:
 * - `collision`: step 1 of the detection didn't conclude, the normalized key
 *   matching several mods;
 * - `merge`: two mods merged by ModReferenceService, never deduplicated;
 * - `conflict`: write refused by ModReferenceService (conflicting ids).
 *
 * **At most one line per day** for a repeated event: the caller passes a
 * deduplication key (`$oncePerDay`, e.g. game + SHA1 + reason for a conflict,
 * server + key for a collision). A short-lived cache key (CACHE_PREFIX,
 * expiring after a day) remembers that today's line is written. Cache
 * unavailable: the line is written anyway (better a duplicate than a lost
 * event).
 *
 * Append-only writes with a lock (FILE_APPEND | LOCK_EX). Past 1 MB, the file
 * is renamed to `.1` (overwriting the previous one) and the journal starts
 * again from an empty file: two files of 1 MB at most. "Check the size,
 * rename, write" happens under an exclusive lock on `<journal>.lock`: without
 * it, two simultaneous writes at rotation time would rename twice in a row and
 * the 1 MB archive would be replaced by an almost empty file.
 *
 * **Never makes the caller fail**: every error (file, lock, cache, and even
 * the Laravel log reporting it) is swallowed.
 */
class CollisionJournal
{
    public const TYPE_COLLISION = 'collision';
    public const TYPE_MERGE = 'merge';
    public const TYPE_CONFLICT = 'conflict';

    public const TYPES = [self::TYPE_COLLISION, self::TYPE_MERGE, self::TYPE_CONFLICT];

    /** Size past which the journal is renamed to `.1`. */
    public const MAX_BYTES = 1024 * 1024;

    /** Prefix of the deduplication cache keys (the only place it's defined). */
    public const CACHE_PREFIX = 'addonbrowser_journal_';

    /**
     * Journal path. The only place it's defined (the admin screen reads it
     * from here too). The rotation lock is `<path>.lock`, the archive
     * `<path>.1`.
     */
    public static function path(): string
    {
        return storage_path('logs/addonbrowser-collisions.log');
    }

    public static function collision(string $message, array $context = [], ?string $oncePerDay = null): void
    {
        self::write(self::TYPE_COLLISION, $message, $context, $oncePerDay);
    }

    public static function merge(string $message, array $context = []): void
    {
        self::write(self::TYPE_MERGE, $message, $context);
    }

    public static function conflict(string $message, array $context = [], ?string $oncePerDay = null): void
    {
        self::write(self::TYPE_CONFLICT, $message, $context, $oncePerDay);
    }

    /**
     * Deduplication cache key: type, day and hash of the caller's key (a short
     * key whatever its length).
     */
    public static function cacheKey(string $type, string $oncePerDay): string
    {
        return self::CACHE_PREFIX . $type . '_' . now()->format('Ymd') . '_' . sha1($oncePerDay);
    }

    /**
     * @param string|null $oncePerDay deduplication key: at most one line per
     *                                day for the same key (null: always write)
     */
    public static function write(string $type, string $message, array $context = [], ?string $oncePerDay = null): void
    {
        try {
            if (! in_array($type, self::TYPES, true)) {
                self::warn("Unknown journal line type: {$type}");

                return;
            }

            if ($oncePerDay !== null && ! self::firstToday($type, $oncePerDay)) {
                return;
            }

            $path = self::path();
            // One line = one event: no line break in the message (the context
            // is JSON-encoded, which escapes its own).
            $message = trim(preg_replace('/[\r\n]+/', ' ', $message) ?? '');
            $json = $context === []
                ? ''
                : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line = now()->format(DATE_ATOM) . ' ' . $type . ' ' . $message . $json . "\n";

            self::append($path, $line);
        } catch (\Throwable $ex) {
            self::warn('Could not write to the collision journal: ' . $ex->getMessage());
        }
    }

    /**
     * Rotation if needed, then append the line, under an exclusive lock. If no
     * lock can be taken (read-only directory…), the line is written anyway.
     */
    private static function append(string $path, string $line): void
    {
        $lock = @fopen($path . '.lock', 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }

        try {
            clearstatcache(true, $path);
            if (is_file($path) && filesize($path) >= self::MAX_BYTES) {
                // rename() overwrites an existing target (POSIX).
                @rename($path, $path . '.1');
            }

            if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
                self::warn("Could not write to the collision journal ({$path}).");
            }
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /**
     * First occurrence today for this key? Cache::add() is atomic (only one
     * write wins). Cache unavailable: yes (the event gets journaled).
     */
    private static function firstToday(string $type, string $oncePerDay): bool
    {
        try {
            return Cache::add(self::cacheKey($type, $oncePerDay), 1, now()->addDay());
        } catch (\Throwable $ex) {
            return true;
        }
    }

    /** Warning in the addon's logs, never throwing. */
    private static function warn(string $message): void
    {
        try {
            AddonBrowserLogger::warning($message);
        } catch (\Throwable $ex) {
            // Laravel's log itself is unusable: nothing more to do.
        }
    }
}
