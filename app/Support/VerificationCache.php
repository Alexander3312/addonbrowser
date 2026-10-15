<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support;

use Illuminate\Support\Facades\Redis;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;

/**
 * Per-server verification cache (installed-mods detection): one Redis hash per
 * server, through the Redis facade (Laravel's cache doesn't handle Redis
 * hashes).
 *
 *   key   : addonbrowser:<addon version>:verif:<server uuid>
 *   field : <filename>|<size>|<modification date>
 *   value : JSON { "status": "known|cf_match|unknown", "sha1": "…",
 *                  "fingerprint": 123, "curseforge_project_id": 456,
 *                  "checked_at": "…" }
 *
 * It's neither the referential (specific to one server, never shared) nor the
 * source of the badge (the listing is): it only remembers what has already
 * been verified, so a verification is never redone as long as the file
 * doesn't change.
 *
 * Read (step 2 of the cascade, see InstalledModsService) with cleanup on every
 * read (HDEL of the fields of files that are gone, EXPIRE reset to 90 days);
 * written by the hash verification job (write()), with the MAX_FIELDS bound
 * (HLEN before writing).
 *
 * **Files being verified**: a separate Redis set,
 *
 *   key    : addonbrowser:<addon version>:pending:<server uuid>
 *   members: fields (same format) of the current batch
 *
 * filled by GET /installed when the job is dispatched (claimBatch(), atomic:
 * one batch at a time, even if two requests arrive together), emptied by the
 * job as it goes (releasePending()), with an EXPIRE of PENDING_TTL (10 min)
 * reset on every job run (refreshPending()): a killed or stuck job never
 * blocks the next batches for longer than that.
 *
 * **Recently abandoned files**: one sorted set per server
 * (`addonbrowser:<version>:abandoned:<uuid>`, score = date), filled by the job
 * for each file removed from the batch without a result; GET /installed
 * doesn't offer them to a batch again for ABANDONED_TTL (30 min). Bounded to
 * MAX_FIELDS.
 *
 * **MAX_FIELDS bound**: at the bound, the key is deleted, then the new result
 * written. A server that permanently has more than 2,000 files to verify
 * would therefore cycle (deletion, re-verification); that's the price of the
 * memory bound (a Redis without maxmemory has no other limit), and the case is
 * outside real-world use (InstalledModsService::MAX_FILES = 2,000 files read at
 * most).
 *
 * KEY_PREFIX and key() are the only place these keys are built.
 *
 * **Field modification date**: `modified` from Wings' listing (RFC 3339 with
 * a UTC offset, e.g. `2026-09-27T15:46:22+02:00`) converted to Unix seconds.
 * A timestamp doesn't depend on the node's timezone: changing the timezone
 * doesn't re-verify everything. Missing or unreadable date → no field, so
 * neither step 2 nor hash verification for that file (nothing could be cached
 * or tracked in the batch).
 *
 * **Addon version** read at runtime from the installed conf.yml
 * (BlueprintAdminLibrary::extensionConfig()), never written in the code
 * (Blueprint placeholders). An addon update changes the key: everything is
 * re-verified once. Unreadable version → no key: step 2 is skipped (neither
 * read nor write), rather than mixing two versions' results under a fallback
 * key.
 */
class VerificationCache
{
    /** Start of every key of this cache (the only place it's defined). */
    public const KEY_PREFIX = 'addonbrowser:';

    /** The addon's identifier in Blueprint (to read its version). */
    private const EXTENSION = 'addonbrowser';

    /** Lifetime of a server's hash, reset on every read and write. */
    public const TTL_SECONDS = 90 * 24 * 3600;

    /**
     * Most fields in a server's hash: at MAX_FIELDS, the key is deleted before
     * writing (a Redis without maxmemory has no memory limit). ~250 bytes per
     * field, ~0.5 MB at most per server.
     */
    public const MAX_FIELDS = 2000;

    /** Lifetime of the set of files being verified (10 min). */
    public const PENDING_TTL = 600;

    /** Most files in one batch (30). */
    public const MAX_BATCH = 30;

    /** How long an abandoned file isn't offered to a batch again (30 min). */
    public const ABANDONED_TTL = 1800;

    public const STATUS_KNOWN = 'known';
    public const STATUS_CF_MATCH = 'cf_match';
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUSES = [self::STATUS_KNOWN, self::STATUS_CF_MATCH, self::STATUS_UNKNOWN];

    /** Fields removed per HDEL command (bounds the size of one command). */
    private const HDEL_CHUNK = 500;

    /** Version accepted in a key: no `:` and no space, 64 characters at most. */
    private const VERSION_FORMAT = '/^[0-9A-Za-z][0-9A-Za-z._+\-]{0,63}$/';

    private const SHA1 = '/^[0-9a-f]{40}$/';

    private const UINT32_MAX = 4294967295;

    /**
     * Installed addon version, or null if unreadable (addon not declared to
     * Blueprint, missing conf.yml, unexpected format). Re-read on every call
     * (a small YAML file): a PHP-FPM process that outlives an addon update
     * doesn't keep the previous version.
     */
    public static function addonVersion(): ?string
    {
        try {
            $value = app(BlueprintExtensionLibrary::class)->extensionConfig(self::EXTENSION)['info']['version'] ?? null;
        } catch (\Throwable $ex) {
            $value = null;
        }

        return is_string($value) && preg_match(self::VERSION_FORMAT, $value) === 1 ? $value : null;
    }

    /**
     * Key of one of a server's data sets: `<KEY_PREFIX><version>:<type>:<uuid>`
     * (`verif`, `pending` or `abandoned`). Null if the addon version is
     * unreadable.
     *
     * `$version`: version already read by the caller (addonVersion()), so all
     * its keys are built with the same version: an /installed request or a job
     * run reads it **once** (and a job never touches another version's keys if
     * the addon is updated during a batch). Null → read here. A version out of
     * format → no key.
     */
    public static function key(string $kind, string $serverUuid, ?string $version = null): ?string
    {
        $version ??= self::addonVersion();

        return $version !== null && preg_match(self::VERSION_FORMAT, $version) === 1
            ? self::KEY_PREFIX . $version . ':' . $kind . ':' . $serverUuid
            : null;
    }

    /** Key of a server's verification hash, or null (unreadable version). */
    public static function verifKey(string $serverUuid, ?string $version = null): ?string
    {
        return self::key('verif', $serverUuid, $version);
    }

    /** Key of a server's set of files being verified, or null (unreadable version). */
    public static function pendingKey(string $serverUuid, ?string $version = null): ?string
    {
        return self::key('pending', $serverUuid, $version);
    }

    /**
     * Key of a server's recently abandoned files (sorted set: member = field,
     * score = abandon date), or null (unreadable version).
     */
    public static function abandonedKey(string $serverUuid, ?string $version = null): ?string
    {
        return self::key('abandoned', $serverUuid, $version);
    }

    /**
     * markAbandoned() script: ZADD of the fields at the given date, removal of
     * abandoned entries older than the lifetime, size bound (oldest first), EXPIRE.
     * ARGV = date, lifetime, bound, fields.
     */
    private const ABANDON_SCRIPT = <<<'LUA'
local now = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])
local max = tonumber(ARGV[3])
for i = 4, #ARGV do
    redis.call('ZADD', KEYS[1], now, ARGV[i])
end
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - ttl)
local n = redis.call('ZCARD', KEYS[1])
if n > max then
    redis.call('ZREMRANGEBYRANK', KEYS[1], 0, n - max - 1)
end
redis.call('EXPIRE', KEYS[1], ttl)
return n
LUA;

    /**
     * Records files abandoned without a result (incomplete read, API
     * unreachable…): they aren't offered to a batch again for ABANDONED_TTL
     * (30 min), otherwise every GET /installed would start a batch with the
     * same files (pointless re-reads, `pending_verification` never at 0, and
     * permanently abandoned files among the smallest ones would starve the
     * others).
     * At most MAX_FIELDS files per server. Never throws.
     *
     * @param string[] $fields
     */
    public static function markAbandoned(string $serverUuid, array $fields, ?string $version = null): void
    {
        $key = self::abandonedKey($serverUuid, $version);
        $fields = array_values(array_unique(array_filter($fields, fn ($f) => is_string($f) && $f !== '')));
        if ($key === null || $fields === []) {
            return;
        }

        try {
            foreach (array_chunk($fields, self::HDEL_CHUNK) as $chunk) {
                Redis::connection()->eval(self::ABANDON_SCRIPT, 1, $key, time(), self::ABANDONED_TTL, self::MAX_FIELDS, ...$chunk);
            }
        } catch (\Throwable $ex) {
            self::log('warning', 'Verification cache unavailable (Redis), abandons not recorded.', ['server' => $serverUuid, 'error' => $ex->getMessage()]);
        }
    }

    /**
     * Fields of the files abandoned less than ABANDONED_TTL ago, or null
     * (unreadable version, Redis unavailable). Never throws.
     *
     * @return string[]|null
     */
    public static function abandonedMembers(string $serverUuid, ?string $version = null): ?array
    {
        $key = self::abandonedKey($serverUuid, $version);
        if ($key === null) {
            return null;
        }

        try {
            $members = Redis::connection()->zrangebyscore($key, time() - self::ABANDONED_TTL, '+inf');

            return array_values(array_map('strval', is_array($members) ? $members : []));
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * claimBatch() script: nothing if a batch is already running (key
     * present), otherwise SADD of the fields and EXPIRE, in a single operation
     * (two simultaneous GET /installed never start two batches).
     * ARGV[1] = lifetime, ARGV[2..] = fields.
     */
    private const CLAIM_SCRIPT = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 1 then
    return 0
end
for i = 2, #ARGV do
    redis.call('SADD', KEYS[1], ARGV[i])
end
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
return 1
LUA;

    /**
     * write() script: HLEN, deletion of the key if it already has MAX_FIELDS
     * fields, HSET, EXPIRE, in a single operation. Returns the number of
     * fields before the write. ARGV = field, value, bound, lifetime.
     */
    private const WRITE_SCRIPT = <<<'LUA'
local n = redis.call('HLEN', KEYS[1])
if n >= tonumber(ARGV[3]) then
    redis.call('DEL', KEYS[1])
end
redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
redis.call('EXPIRE', KEYS[1], tonumber(ARGV[4]))
return n
LUA;

    /**
     * Claims a batch for a server: true only if no batch was running; the
     * fields are then in the set, with an EXPIRE of PENDING_TTL. False if a
     * batch is already running, if the addon version is unreadable (no
     * verification started), if the batch is empty or too large, or if Redis
     * is unavailable. The job must only be dispatched once the claim is
     * obtained. Never throws.
     *
     * @param string[] $fields
     */
    public static function claimBatch(string $serverUuid, array $fields, ?string $version = null): bool
    {
        $key = self::pendingKey($serverUuid, $version);
        $fields = array_values(array_unique(array_filter($fields, fn ($f) => is_string($f) && $f !== '')));
        if ($key === null || $fields === [] || count($fields) > self::MAX_BATCH) {
            return false;
        }

        try {
            return (int) Redis::connection()->eval(self::CLAIM_SCRIPT, 1, $key, self::PENDING_TTL, ...$fields) === 1;
        } catch (\Throwable $ex) {
            self::log('warning', 'Verification cache unavailable (Redis), no batch started.', ['server' => $serverUuid, 'error' => $ex->getMessage()]);

            return false;
        }
    }

    /**
     * Number of files in the current batch (SCARD), or null (unreadable
     * version, Redis unavailable). Never throws.
     */
    public static function pendingCount(string $serverUuid, ?string $version = null): ?int
    {
        $key = self::pendingKey($serverUuid, $version);
        if ($key === null) {
            return null;
        }

        try {
            return (int) Redis::connection()->scard($key);
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * Fields of the current batch (SMEMBERS, 30 at most), or null (unreadable
     * version, Redis unavailable). Never throws.
     *
     * @return string[]|null
     */
    public static function pendingMembers(string $serverUuid, ?string $version = null): ?array
    {
        $key = self::pendingKey($serverUuid, $version);
        if ($key === null) {
            return null;
        }

        try {
            $members = Redis::connection()->smembers($key);

            return array_values(array_map('strval', is_array($members) ? $members : []));
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * Resets the PENDING_TTL EXPIRE on the current batch (on every job run).
     * No effect if the set no longer exists. Never throws.
     */
    public static function refreshPending(string $serverUuid, ?string $version = null): void
    {
        $key = self::pendingKey($serverUuid, $version);
        if ($key === null) {
            return;
        }

        try {
            Redis::connection()->expire($key, self::PENDING_TTL);
        } catch (\Throwable $ex) {
            self::log('warning', 'Verification cache unavailable (Redis), batch EXPIRE not reset.', ['server' => $serverUuid, 'error' => $ex->getMessage()]);
        }
    }

    /**
     * Removes fields from the current batch (Redis SREM): file concluded,
     * abandoned, or batch interrupted. Never throws.
     *
     * @param string[] $fields
     */
    public static function releasePending(string $serverUuid, array $fields, ?string $version = null): void
    {
        $key = self::pendingKey($serverUuid, $version);
        $fields = array_values(array_filter($fields, fn ($f) => is_string($f) && $f !== ''));
        if ($key === null || $fields === []) {
            return;
        }

        try {
            foreach (array_chunk($fields, self::HDEL_CHUNK) as $chunk) {
                Redis::connection()->srem($key, ...$chunk);
            }
        } catch (\Throwable $ex) {
            self::log('warning', 'Verification cache unavailable (Redis), files not removed from the batch.', ['server' => $serverUuid, 'error' => $ex->getMessage()]);
        }
    }

    /**
     * releasePendingTracked() script: SREM of each field, returns those that
     * were really in the set. ARGV = fields.
     */
    private const RELEASE_TRACKED_SCRIPT = <<<'LUA'
local removed = {}
for i = 1, #ARGV do
    if redis.call('SREM', KEYS[1], ARGV[i]) == 1 then
        removed[#removed + 1] = ARGV[i]
    end
end
return removed
LUA;

    /**
     * Like releasePending(), but returns the fields that were really in the
     * current batch (effective SREM), in one atomic operation: used for the
     * job's statistics, so a file is only counted once when it leaves the
     * batch, even if a run is replayed (`release`) or a follow-up run removes
     * it a second time. Null if it can't be known (unreadable version, Redis
     * unavailable): nothing must be counted then. Never throws.
     *
     * @param string[] $fields
     * @return string[]|null
     */
    public static function releasePendingTracked(string $serverUuid, array $fields, ?string $version = null): ?array
    {
        $key = self::pendingKey($serverUuid, $version);
        $fields = array_values(array_unique(array_filter($fields, fn ($f) => is_string($f) && $f !== '')));
        if ($key === null) {
            return null;
        }
        if ($fields === []) {
            return [];
        }

        try {
            $removed = [];
            foreach (array_chunk($fields, self::HDEL_CHUNK) as $chunk) {
                $result = Redis::connection()->eval(self::RELEASE_TRACKED_SCRIPT, 1, $key, ...$chunk);
                foreach (is_array($result) ? $result : [] as $field) {
                    $removed[] = (string) $field;
                }
            }

            return $removed;
        } catch (\Throwable $ex) {
            self::log('warning', 'Verification cache unavailable (Redis), files not removed from the batch.', ['server' => $serverUuid, 'error' => $ex->getMessage()]);

            return null;
        }
    }

    /**
     * Does the file already have an entry in the server's cache (HEXISTS)?
     * Null if it can't be known (unreadable version, Redis unavailable).
     * Never throws.
     */
    public static function has(string $serverUuid, string $field, ?string $version = null): ?bool
    {
        $key = self::verifKey($serverUuid, $version);
        if ($key === null) {
            return null;
        }

        try {
            return (bool) Redis::connection()->hexists($key, $field);
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * Writes a verification result: JSON { status, sha1, fingerprint (if
     * computed), curseforge_project_id (cf_match only), checked_at }, then an
     * EXPIRE of 90 days. If the hash already has MAX_FIELDS fields, it's
     * deleted first (log line): the bound holds, and the result just computed
     * isn't lost. Invalid entry (unknown status, missing SHA1 for `known`,
     * missing CurseForge id for `cf_match`): nothing is written. Returns true
     * if the entry is written. Never throws.
     *
     * @param array{status: string, sha1?: ?string, fingerprint?: ?int, curseforge_project_id?: ?int} $entry
     */
    public static function write(string $serverUuid, string $field, array $entry, ?string $version = null): bool
    {
        $key = self::verifKey($serverUuid, $version);
        if ($key === null || $field === '') {
            return false;
        }

        $value = ['status' => $entry['status'] ?? null, 'sha1' => $entry['sha1'] ?? null];
        $fingerprint = $entry['fingerprint'] ?? null;
        if (is_int($fingerprint) && $fingerprint >= 0 && $fingerprint <= self::UINT32_MAX) {
            $value['fingerprint'] = $fingerprint;
        }
        if (($entry['status'] ?? null) === self::STATUS_CF_MATCH) {
            $value['curseforge_project_id'] = $entry['curseforge_project_id'] ?? null;
        }
        $value['checked_at'] = now()->toIso8601String();
        $json = json_encode($value);

        // Same check as on read: never an entry that decode() would reject.
        if (! is_string($json) || self::decode($json) === null) {
            self::log('warning', 'Verification cache: invalid entry, nothing written.', ['server' => $serverUuid, 'status' => $entry['status'] ?? null]);

            return false;
        }

        try {
            $before = (int) Redis::connection()->eval(self::WRITE_SCRIPT, 1, $key, $field, $json, self::MAX_FIELDS, self::TTL_SECONDS);
        } catch (\Throwable $ex) {
            self::log('warning', 'Verification cache unavailable (Redis), result not written.', ['server' => $serverUuid, 'error' => $ex->getMessage()]);

            return false;
        }

        if ($before >= self::MAX_FIELDS) {
            self::log('warning', 'Verification cache: too many fields for this server, key deleted.', ['server' => $serverUuid, 'fields' => $before]);
        }

        return true;
    }

    /**
     * Field of a file from the listing: `<name>|<size>|<modification date in
     * Unix seconds>`, or null if the size or the date is missing. The name may
     * contain `|`: the field is never split again, only compared.
     */
    public static function field(string $name, mixed $size, mixed $modified): ?string
    {
        if (! is_int($size) || $size < 0 || ! is_string($modified) || $modified === '') {
            return null;
        }

        // Strict format (RFC 3339, with or without milliseconds): a permissive
        // parser would accept relative dates ("now"), which would change on
        // every call.
        $date = \DateTimeImmutable::createFromFormat(DATE_RFC3339, $modified)
            ?: \DateTimeImmutable::createFromFormat(DATE_RFC3339_EXTENDED, $modified);
        if ($date === false) {
            return null;
        }

        return $name . '|' . $size . '|' . $date->getTimestamp();
    }

    /**
     * Reads a server's cache for GET /installed: HGETALL, then HDEL of the
     * fields that no longer match any file of the listing ($presentFields),
     * then an EXPIRE of 90 days if the key still exists. Returns the entries
     * of the present files, decoded (null for an unreadable value, ignored by
     * the caller), keyed by field.
     *
     * Null if step 2 can't happen: unreadable addon version, or Redis
     * unavailable (the caller goes on without it; one log line). Never throws.
     *
     * @param string[] $presentFields fields of every file of the filtered listing
     * @return array<string, array{status: string, sha1: ?string, curseforge_project_id: ?int}|null>|null
     */
    public static function readAndPrune(string $serverUuid, array $presentFields, ?string $version = null): ?array
    {
        $key = self::verifKey($serverUuid, $version);
        if ($key === null) {
            self::log('debug', 'Verification cache: addon version unreadable, step 2 skipped.', ['server' => $serverUuid]);

            return null;
        }

        try {
            $redis = Redis::connection();
            $all = $redis->hgetall($key);
            $all = is_array($all) ? $all : [];

            $present = array_flip($presentFields);
            $gone = array_values(array_filter(array_keys($all), fn ($field) => ! isset($present[(string) $field])));
            foreach (array_chunk($gone, self::HDEL_CHUNK) as $chunk) {
                $redis->hdel($key, ...$chunk);
            }

            // Only if the key still exists (a hash emptied by HDEL is deleted
            // by Redis): never creates anything.
            if (count($all) > count($gone)) {
                $redis->expire($key, self::TTL_SECONDS);
            }
        } catch (\Throwable $ex) {
            self::log('warning', 'Verification cache unavailable (Redis), step 2 skipped.', ['server' => $serverUuid, 'error' => $ex->getMessage()]);

            return null;
        }

        $entries = [];
        foreach ($all as $field => $raw) {
            $field = (string) $field;
            if (isset($present[$field])) {
                $entries[$field] = self::decode($raw);
            }
        }

        return $entries;
    }

    /**
     * Read-only access to a server's cache (admin screen, current-state
     * computation): HGETALL and decoding, **without** HDEL or EXPIRE (changes
     * nothing). Returns every entry, decoded (null for an unreadable value),
     * keyed by field; null if the addon version is unreadable or Redis is
     * unavailable. Never throws.
     *
     * @return array<string, array{status: string, sha1: ?string, curseforge_project_id: ?int}|null>|null
     */
    public static function read(string $serverUuid, ?string $version = null): ?array
    {
        $key = self::verifKey($serverUuid, $version);
        if ($key === null) {
            return null;
        }

        try {
            $all = Redis::connection()->hgetall($key);
        } catch (\Throwable $ex) {
            self::log('warning', 'Verification cache unavailable (Redis), read failed.', ['server' => $serverUuid, 'error' => $ex->getMessage()]);

            return null;
        }

        $entries = [];
        foreach (is_array($all) ? $all : [] as $field => $raw) {
            $entries[(string) $field] = self::decode($raw);
        }

        return $entries;
    }

    /**
     * A field's value, decoded and checked, or null if it's unusable (invalid
     * JSON, unknown status, missing SHA1 for `known`, missing CurseForge id for
     * `cf_match`): it's then ignored, as if the file had no entry.
     *
     * @return array{status: string, sha1: ?string, curseforge_project_id: ?int}|null
     */
    public static function decode(mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return null;
        }

        $value = json_decode($raw, true);
        if (! is_array($value) || ! in_array($value['status'] ?? null, self::STATUSES, true)) {
            return null;
        }

        $sha1 = is_string($value['sha1'] ?? null) ? strtolower($value['sha1']) : null;
        $sha1 = $sha1 !== null && preg_match(self::SHA1, $sha1) === 1 ? $sha1 : null;

        $cfId = $value['curseforge_project_id'] ?? null;
        if (is_string($cfId) && ctype_digit($cfId) && strlen($cfId) <= 10) {
            $cfId = (int) $cfId;
        }
        $cfId = is_int($cfId) && $cfId >= 1 && $cfId <= self::UINT32_MAX ? $cfId : null;

        return match ($value['status']) {
            self::STATUS_KNOWN => $sha1 !== null ? ['status' => self::STATUS_KNOWN, 'sha1' => $sha1, 'curseforge_project_id' => null] : null,
            self::STATUS_CF_MATCH => $cfId !== null ? ['status' => self::STATUS_CF_MATCH, 'sha1' => $sha1, 'curseforge_project_id' => $cfId] : null,
            default => ['status' => self::STATUS_UNKNOWN, 'sha1' => $sha1, 'curseforge_project_id' => null],
        };
    }

    /** Addon log that never throws. */
    private static function log(string $level, string $message, array $context = []): void
    {
        try {
            AddonBrowserLogger::{$level}($message, $context);
        } catch (\Throwable $ex) {
            // Nothing more to do.
        }
    }
}
