<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

/**
 * Log verbosity of the Addon Browser addon, set independently of the panel's
 * global LOG_LEVEL through the following environment variable, defined in the
 * panel's .env and exposed by config/addonbrowser.php:
 *
 *   ADDONBROWSER_LOG_LEVEL=debug|info|warning|error|none
 *
 * - debug   : everything, including the raw Modrinth/CurseForge responses
 *             (very verbose, for one-off troubleshooting only)
 * - info    : normal events (requests received, summarized results)
 * - warning : unexpected responses from a third-party API, blocked attempts, etc.
 * - error   : errors only (default)
 * - none    : no log from the addon at all
 *
 * Every entry is prefixed with "[AddonBrowser]" so it stays easy to filter in
 * storage/logs/laravel.log even when the global LOG_LEVEL is more permissive.
 */
class AddonBrowserLogger
{
    private const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'none' => 4,
    ];

    private const DEFAULT_LEVEL = 'error';

    private const PREFIX = '[AddonBrowser] ';

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    private static function log(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < self::threshold()) {
            return;
        }

        \Log::{$level}(self::PREFIX . $message, $context);
    }

    /**
     * Configured verbosity threshold, read through config('addonbrowser.log_level')
     * (config/addonbrowser.php) rather than a direct env(): unlike env(),
     * config() stays correct when the panel runs with `php artisan config:cache`.
     * After changing ADDONBROWSER_LOG_LEVEL in the .env, run
     * `php artisan config:cache` again (or `config:clear` if the config isn't
     * cached) for it to take effect.
     */
    private static function threshold(): int
    {
        $configured = strtolower(trim((string) config('addonbrowser.log_level', self::DEFAULT_LEVEL)));

        return self::LEVELS[$configured] ?? self::LEVELS[self::DEFAULT_LEVEL];
    }
}
