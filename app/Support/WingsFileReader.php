<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * **Bounded, streamed** read of a server's file through Wings, for the
 * hash-based verification. Read-only: no other Wings method is called, nothing
 * is ever written to the server.
 *
 * The panel's `getContent()` doesn't fit: it only checks the size after the
 * whole transfer. Here, Wings' HTTP client is used in streaming mode
 * (`GET /api/servers/<uuid>/files/contents`, `stream => true`), the body is
 * read in CHUNK-byte chunks while counting, and the connection is **cut** as
 * soon as the ceiling (MAX_BYTES) or the listed size is exceeded: the file may
 * have been replaced by a huge one between the listing and the read. Each
 * chunk is passed to a callback (SHA1, fingerprint): memory stays bounded by
 * the caller.
 *
 * Actually cutting the connection requires Guzzle's StreamHandler, so
 * `allow_url_fopen` enabled (otherwise cURL loads the whole body before
 * handing it over): without it, nothing is read (READ_UNSUPPORTED). Bounded
 * durations: connection (CONNECT_SECONDS), waiting for the response and total
 * duration (`$maxSeconds`, checked between two chunks), a stalled read
 * (READ_STALL_SECONDS): a read lasts at most CONNECT_SECONDS + `$maxSeconds` +
 * READ_STALL_SECONDS.
 *
 * Result (READ_* constants): only READ_OK means "file read in full, exactly
 * the listed size"; in every other case, the caller must not compute or cache
 * anything.
 *
 * **Path**: built only by path() from the game's target folder
 * (gameConfig.json) and a name from the listing, checked (no `/`, no `\`,
 * neither `.` nor `..`, no null or control byte, a detection extension
 * optionally followed by `.disabled`). Never a path from the client.
 */
class WingsFileReader
{
    /** Ceiling for a file read (50 MB). */
    public const MAX_BYTES = 50 * 1024 * 1024;

    /** Size of a read chunk. */
    public const CHUNK = 65536;

    public const READ_OK = 'ok';

    /** Ceiling exceeded (per the listing, or during the read): connection cut. */
    public const READ_TOO_LARGE = 'too_large';

    /** Bytes received ≠ listed size (file replaced, truncated, still being uploaded). */
    public const READ_SIZE_MISMATCH = 'size_mismatch';

    /** Maximum read duration exceeded: connection cut. */
    public const READ_TIMEOUT = 'timeout';

    /** Wings answered with an error (file gone…): only this file is given up. */
    public const READ_FAILED = 'failed';

    /** Node unreachable (no response): the caller stops the whole batch. */
    public const READ_UNREACHABLE = 'unreachable';

    /** Name refused: nothing is asked of Wings. */
    public const READ_REFUSED = 'refused';

    /** Streaming impossible on this PHP (allow_url_fopen disabled): nothing is read. */
    public const READ_UNSUPPORTED = 'unsupported';

    /** Connection timeout to the node, in seconds. */
    public const CONNECT_SECONDS = 5;

    /** Longest wait for one chunk (stalled read), in seconds. */
    public const READ_STALL_SECONDS = 5;

    public function __construct(private DaemonFileRepository $repository)
    {
    }

    /**
     * Is the read really streamed? With `stream => true`, Guzzle only uses its
     * StreamHandler (read as data arrives, can be cut) if `allow_url_fopen` is
     * enabled; otherwise the request goes through cURL, which loads the
     * **whole** body before handing it over: cutting at 50 MB would no longer
     * protect anything. To be safe: without `allow_url_fopen`, nothing is read.
     */
    protected function streamingAvailable(): bool
    {
        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Path of a file in the target folder, or null if the name is refused.
     * `$folder` comes from gameConfig.json (`target_folder`), `$name` from
     * Wings' listing; `$extensions` = `installed_detection.extensions`.
     *
     * @param string[] $extensions lowercase
     */
    public static function path(string $folder, string $name, array $extensions): ?string
    {
        $folder = trim($folder, '/');
        if ($folder === '' || str_contains($folder, '..') || str_contains($folder, '\\') || preg_match('/[\x00-\x1f\x7f]/', $folder) === 1) {
            return null;
        }
        if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 255
            || str_contains($name, '/') || str_contains($name, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || ! mb_check_encoding($name, 'UTF-8')) {
            return null;
        }

        $base = preg_replace('/\.disabled$/i', '', $name);
        $extension = strtolower(pathinfo((string) $base, PATHINFO_EXTENSION));
        if ($extension === '' || ! in_array($extension, $extensions, true) || $base === '.' . $extension) {
            return null;
        }

        return '/' . $folder . '/' . $name;
    }

    /**
     * Reads `$path` on the server as a stream, passing each chunk to
     * `$onChunk`. `$expectedSize` = listed size. Cuts the connection as soon as
     * MAX_BYTES or `$expectedSize` is exceeded, or after `$maxSeconds`. Never
     * throws (an exception from the callback gives READ_FAILED).
     *
     * @param callable(string): void $onChunk
     * @return string a READ_* constant
     */
    public function read(Server $server, string $path, int $expectedSize, callable $onChunk, float $maxSeconds = 30.0): string
    {
        if ($expectedSize < 0 || $expectedSize > self::MAX_BYTES) {
            return self::READ_TOO_LARGE;
        }
        if ($path === '' || $path[0] !== '/' || str_contains($path, '..') || str_contains($path, '\\')) {
            return self::READ_REFUSED;
        }

        if (! $this->streamingAvailable()) {
            // Without a truly streamed read, the whole body would be loaded
            // before any cut: nothing is read.
            try {
                AddonBrowserLogger::warning('Streaming read impossible (allow_url_fopen disabled), file not verified.', ['path' => $path]);
            } catch (\Throwable $ex) {
                // Nothing more to do.
            }

            return self::READ_UNSUPPORTED;
        }

        $deadline = microtime(true) + $maxSeconds;
        $body = null;
        try {
            $response = $this->repository->setServer($server)->getHttpClient()->get(
                sprintf('/api/servers/%s/files/contents', $server->uuid),
                [
                    'query' => ['file' => $path],
                    'stream' => true,
                    // Duration bounds: connection, waiting for the response
                    // (at most the granted duration), and each chunk read
                    // (READ_STALL_SECONDS); the overall deadline is checked
                    // between two chunks.
                    'connect_timeout' => self::CONNECT_SECONDS,
                    'timeout' => max(1.0, $maxSeconds),
                    'read_timeout' => self::READ_STALL_SECONDS,
                ]
            );
            $body = $response->getBody();

            $received = 0;
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK);
                if ($chunk === '') {
                    // Read with no data: actual end or waiting; eof() decides.
                    if (microtime(true) > $deadline) {
                        return self::READ_TIMEOUT;
                    }
                    continue;
                }
                $received += strlen($chunk);
                if ($received > self::MAX_BYTES) {
                    return self::READ_TOO_LARGE;
                }
                if ($received > $expectedSize) {
                    return self::READ_SIZE_MISMATCH;
                }
                if (microtime(true) > $deadline) {
                    return self::READ_TIMEOUT;
                }
                $onChunk($chunk);
            }

            return $received === $expectedSize ? self::READ_OK : self::READ_SIZE_MISMATCH;
        } catch (ConnectException $ex) {
            return self::READ_UNREACHABLE;
        } catch (RequestException $ex) {
            // Error response from Wings (file gone, unreadable…): this file
            // only. No response: node unreachable.
            return $ex->hasResponse() ? self::READ_FAILED : self::READ_UNREACHABLE;
        } catch (\GuzzleHttp\Exception\GuzzleException $ex) {
            return self::READ_UNREACHABLE;
        } catch (\Throwable $ex) {
            // Stream interrupted mid-read (timeout, cut), or an error from the
            // callback: nothing is computed.
            return self::READ_FAILED;
        } finally {
            // Cut the connection (without reading the rest of the body).
            try {
                $body?->close();
            } catch (\Throwable $ex) {
                // Nothing more to do.
            }
        }
    }
}
