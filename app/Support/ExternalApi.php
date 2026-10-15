<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Single place where requests to the external APIs are built (Modrinth,
 * CurseForge, download CDNs).
 *
 * Some Modrinth calls can hang for 30 s without receiving a single byte
 * (connection open, no response), intermittently and only for some projects,
 * while the same call answers in 0.05 s right before or after. Laravel's
 * default timeout (30 s) is longer than the browser's (20 s, axios): the
 * versions window would fail on a "timeout" instead of showing a result. Hence:
 *
 * - **interactive()** (calls made during a user's request: search, versions,
 *   categories…): short timeouts (TIMEOUT, CONNECT_TIMEOUT) and **one** retry,
 *   only on a connection error (timeout included) — never on an HTTP error
 *   response, which is returned as is to the caller. Worst case ≈ 2 × TIMEOUT +
 *   RETRY_DELAY_MS, under the browser's 20 s. The retry goes out on a fresh
 *   connection, which is enough for a hung one.
 * - **batch()** (grouped calls from the jobs): timeouts given by the caller,
 *   **no** retry (a job has its own time budget).
 * - Everywhere, an identifying `User-Agent`, as Modrinth's documentation asks
 *   (instead of Guzzle's default one).
 */
class ExternalApi
{
    public const TIMEOUT = 8;
    public const CONNECT_TIMEOUT = 4;
    public const TRIES = 2;
    public const RETRY_DELAY_MS = 300;

    /**
     * `pterodactyl-addonbrowser/<version> (<panel host>)`, in the format
     * recommended by https://docs.modrinth.com/api/ ("project/version
     * (contact)"): a User-Agent that only identifies the HTTP library (Guzzle's)
     * "increases the likelihood" of being blocked (Modrinth's Cloudflare
     * firewall can answer 403 "Request blocked" on version_files, or leave
     * connections hanging elsewhere). Version read at runtime (conf.yml through
     * Blueprint, without the `-addon-browser` suffix), host taken from APP_URL:
     * nothing hardcoded.
     */
    public static function userAgent(): string
    {
        $version = VerificationCache::addonVersion();
        $version = $version !== null ? explode('-', $version)[0] : null;
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return 'pterodactyl-addonbrowser'
            . ($version !== null ? "/{$version}" : '')
            . (is_string($host) && $host !== '' ? " ({$host})" : '');
    }

    /**
     * Headers specific to api.modrinth.com: the personal Modrinth token
     * (`MODRINTH_API_KEY`, through config/addonbrowser.php) if configured, sent
     * raw in `Authorization` (Modrinth API format, no "Bearer"). Optional:
     * without a token, nothing is added. Only for calls to api.modrinth.com —
     * never the CDN nor CurseForge. A value with unexpected characters is
     * ignored (never a malformed header), and never written to a log.
     */
    public static function modrinthHeaders(): array
    {
        $token = trim((string) config('addonbrowser.modrinth_api_key', ''));

        return $token !== '' && preg_match('/^[A-Za-z0-9_\-.]{8,512}$/', $token) === 1
            ? ['Authorization' => $token]
            : [];
    }

    public static function interactive(array $headers = []): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::userAgent()] + $headers)
            ->timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->retry(self::TRIES, self::RETRY_DELAY_MS, fn ($exception) => $exception instanceof ConnectionException, false);
    }

    public static function batch(int $timeout, int $connectTimeout, array $headers = []): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::userAgent()] + $headers)
            ->timeout($timeout)
            ->connectTimeout($connectTimeout);
    }

    /** Download (CDN): only the header, the controller handles its own stream and timeouts. */
    public static function download(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => self::userAgent()]);
    }
}
