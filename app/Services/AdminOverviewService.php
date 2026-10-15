<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\ModFile;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Models\Stat;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonStats;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\CollisionJournal;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\VerificationCache;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

/**
 * Data of the admin screen, **read-only**: the admin controller
 * (admin/controller.php) calls this service, the view only displays. Every
 * query happens here.
 *
 * - collisions(): normalized keys shared by several mods of the referential,
 *   with the mods and their known files;
 * - journal(): last lines of the text journal (CollisionJournal), read **from
 *   the end**, in blocks, JOURNAL_MAX_BYTES at most per file (the journal,
 *   then its `.1` archive): memory stays bounded whatever the files' size;
 * - stats(): event statistics (`addonbrowser_stats` table, see
 *   Support\AddonStats), totals over 7 days, 30 days and all time, per game
 *   and per metric;
 * - currentState(): current state, computed **on demand** (form button,
 *   never stored): for each server of a game with detection enabled, a
 *   single listing of the target folder, then steps 1 and 2 with no side
 *   effect (InstalledModsService::classify(), cache read through
 *   VerificationCache::read(): neither HDEL nor EXPIRE). No dispatch, no
 *   write to the database or to the servers' cache, no statistic.
 *
 * **Load bounds of currentState()** (one PHP-FPM request): MAX_SERVERS
 * servers at most (the others are counted as "not computed"); a total budget
 * of TIME_BUDGET seconds, and a stop before a server if the remaining time is
 * under a listing's worst case (LISTING_TIMEOUT + LISTING_STALL_SECONDS): the
 * computation therefore lasts ~TIME_BUDGET at most, plus rendering. That's
 * the real bound: on Linux, `max_execution_time` (30 s) doesn't count network
 * waits. Each listing has its own limits (LISTING_CONNECT_TIMEOUT for the
 * connection, LISTING_TIMEOUT in total) instead of the panel's (5 s and
 * 15 s), and a bounded volume (LISTING_MAX_BYTES, streamed read cut past it:
 * "Listing too large"); a node that doesn't answer or cuts the transfer → its
 * other servers are "unavailable" without a new call; one computation at a
 * time for the whole panel (cache lock LOCK_KEY, LOCK_SECONDS), otherwise
 * "computation already running".
 *
 * Nothing is coded per game: games, folders and extensions come from
 * gameConfig.json (AddonGameMapService), the Modrinth and CurseForge links are
 * generic (project address by its id).
 *
 * Each section catches its own errors (table missing before the migration,
 * Redis unavailable…) and returns a message: the page always renders.
 */
class AdminOverviewService
{
    /** Most colliding keys displayed. */
    public const MAX_COLLISION_KEYS = 100;

    /** Most file rows read for those keys (a key can have many versions). */
    public const MAX_COLLISION_FILES = 5000;

    /** Most files displayed per mod of a collision (the others are counted). */
    public const MAX_FILES_PER_MOD = 20;

    /** Most journal lines displayed. */
    public const JOURNAL_LIMIT = 200;

    /** Most bytes read per journal file (from the end). */
    public const JOURNAL_MAX_BYTES = 256 * 1024;

    /** Size of a journal read block. */
    public const JOURNAL_BLOCK = 64 * 1024;

    /** Longest JSON context displayed for a journal line (characters). */
    public const JOURNAL_CONTEXT_MAX = 8192;

    /** Most servers listed by one current-state computation. */
    public const MAX_SERVERS = 50;

    /** Total budget of one current-state computation, in seconds (under PHP-FPM's 30 s). */
    public const TIME_BUDGET = 20;

    /** Longest duration of a listing (connection included), in seconds. */
    public const LISTING_TIMEOUT = 5;

    /** Longest connection time to the node for a listing, in seconds. */
    public const LISTING_CONNECT_TIMEOUT = 3;

    /** Longest stall of one read of a listing's body, in seconds. */
    public const LISTING_STALL_SECONDS = 1;

    /**
     * Largest size of a listing's response (JSON): past it, "unavailable"
     * row (a real mods folder is a few tens of KB; ~4 MB ≈ 17,000 entries).
     */
    public const LISTING_MAX_BYTES = 4 * 1024 * 1024;

    /** Read block for a listing's body. */
    private const LISTING_CHUNK = 65536;

    // Reasons for a failed listing (UI texts).
    public const LISTING_TOO_LARGE = 'Listing too large.';
    public const LISTING_UNREACHABLE = 'Node unreachable.';
    public const LISTING_INTERRUPTED = 'Transfer interrupted (connection cut while reading the listing).';
    public const LISTING_TIMED_OUT = 'Listing timed out.';
    public const LISTING_UNSUPPORTED = 'Streamed read not available (allow_url_fopen disabled).';

    /**
     * Lock of the current-state computation (one at a time for the whole
     * panel). The only place it's defined, under the addon's key prefix.
     */
    public const LOCK_KEY = VerificationCache::KEY_PREFIX . 'admin-state-lock';

    /** Lifetime of the lock (released at the end of the computation, or expired if the request is killed). */
    public const LOCK_SECONDS = 60;

    /** Generic project addresses (followed by the id). */
    public const MODRINTH_PROJECT_URL = 'https://modrinth.com/project/';
    public const CURSEFORGE_PROJECT_URL = 'https://www.curseforge.com/projects/';

    // Statuses of a current-state row.
    public const STATE_OK = 'ok';
    public const STATE_UNAVAILABLE = 'unavailable';
    public const STATE_NOT_COMPUTED = 'not_computed';

    /** Modrinth project id (base 62). */
    private const MODRINTH_ID = '/^[A-Za-z0-9]{1,64}$/';

    private const UINT32_MAX = 4294967295;

    /** Start of a journal line: ISO 8601 date, type, rest. */
    private const JOURNAL_LINE = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)) (\S+) (.*)$/s';

    /** Most attempts to find the start of a line's JSON context. */
    private const JOURNAL_JSON_ATTEMPTS = 3;

    public function __construct(
        private AddonGameMapService $gameMap,
        private InstalledModsService $installed,
        private DaemonFileRepository $files
    ) {
    }

    /**
     * Collisions in the referential: normalized keys shared by several mods
     * of the same game, MAX_COLLISION_KEYS at most, sorted by game and key,
     * each with its mods (name, ids, links) and their known files (name,
     * source, size).
     *
     * @return array{items: array, truncated: bool, error: ?string}
     */
    public function collisions(): array
    {
        try {
            $keys = ModFile::query()->toBase()
                ->select(['game', 'filename_key'])
                ->selectRaw('COUNT(DISTINCT mod_id) AS nb_mods')
                ->groupBy('game', 'filename_key')
                ->havingRaw('COUNT(DISTINCT mod_id) > 1')
                ->orderBy('game')
                ->orderBy('filename_key')
                ->limit(self::MAX_COLLISION_KEYS + 1)
                ->get();

            $truncated = $keys->count() > self::MAX_COLLISION_KEYS;
            $keys = $keys->take(self::MAX_COLLISION_KEYS);

            // Keys per game: one query per game for the files (and one for
            // their mods).
            $items = [];
            $byGame = [];
            foreach ($keys as $row) {
                $game = (string) $row->game;
                $key = (string) $row->filename_key;
                $items[$game . "\0" . $key] = ['game' => $game, 'key' => $key, 'nb_mods' => (int) $row->nb_mods, 'mods' => []];
                $byGame[$game][] = $key;
            }

            $filesRead = 0;
            foreach ($byGame as $game => $gameKeys) {
                $files = ModFile::query()
                    ->with('mod')
                    ->where('game', $game)
                    ->whereIn('filename_key', $gameKeys)
                    ->orderBy('filename_key')
                    ->orderBy('mod_id')
                    ->orderBy('filename')
                    ->limit(max(1, self::MAX_COLLISION_FILES - $filesRead))
                    ->get();
                $filesRead += $files->count();
                if ($filesRead >= self::MAX_COLLISION_FILES) {
                    // Bound reached (for this game or in total): some files,
                    // or even keys, aren't displayed.
                    $truncated = true;
                }

                foreach ($files as $file) {
                    // Strict equality (binary collation on MySQL, and in PHP here).
                    $id = $game . "\0" . (string) $file->filename_key;
                    $mod = $file->mod;
                    if (! isset($items[$id]) || $mod === null) {
                        continue;
                    }
                    $mods = &$items[$id]['mods'];
                    if (! isset($mods[$mod->id])) {
                        $mods[$mod->id] = $this->describeMod($mod->id, $mod->name, $mod->modrinth_project_id, $mod->curseforge_project_id) + ['files' => [], 'more_files' => 0];
                    }
                    if (count($mods[$mod->id]['files']) < self::MAX_FILES_PER_MOD) {
                        $mods[$mod->id]['files'][] = [
                            'filename' => (string) $file->filename,
                            'source' => (string) $file->source,
                            'size' => (int) $file->size,
                        ];
                    } else {
                        $mods[$mod->id]['more_files']++;
                    }
                    unset($mods);
                }
                if ($filesRead >= self::MAX_COLLISION_FILES) {
                    break;
                }
            }

            foreach ($items as $id => $item) {
                if ($item['mods'] === []) {
                    // Key of a game that wasn't read (file bound reached):
                    // never displayed without its mods.
                    unset($items[$id]);
                    $truncated = true;
                    continue;
                }
                $items[$id]['mods'] = array_values($item['mods']);
            }

            return ['items' => array_values($items), 'truncated' => $truncated, 'error' => null];
        } catch (\Throwable $ex) {
            $this->log('warning', 'Admin screen: could not read the collisions.', ['error' => $ex->getMessage()]);

            return ['items' => [], 'truncated' => false, 'error' => 'Could not read the collisions (see the addon logs).'];
        }
    }

    /**
     * Last journal lines, most recent first, filtered by type (`$type`
     * checked against CollisionJournal::TYPES; invalid → no filter). Read from
     * the end, in JOURNAL_BLOCK-byte blocks, JOURNAL_MAX_BYTES at most per
     * file: the journal, then its `.1` archive if lines are missing. Missing
     * file → nothing. Each line is split into date, type, message and context
     * (JSON decoded then re-encoded readably); a malformed line is counted,
     * not displayed.
     *
     * @return array{type: ?string, entries: array<int, array{date: string, type: string, message: string, context: ?string}>, malformed: int, error: ?string}
     */
    public function journal(?string $type = null, int $limit = self::JOURNAL_LIMIT): array
    {
        $type = in_array($type, CollisionJournal::TYPES, true) ? $type : null;
        $limit = max(1, min($limit, self::JOURNAL_LIMIT));
        $entries = [];
        $malformed = 0;

        try {
            $path = CollisionJournal::path();
            foreach ([$path, $path . '.1'] as $file) {
                if (count($entries) >= $limit) {
                    break;
                }
                $this->readJournalBackwards($file, $type, $limit, $entries, $malformed);
            }
        } catch (\Throwable $ex) {
            $this->log('warning', 'Admin screen: could not read the journal.', ['error' => $ex->getMessage()]);

            return ['type' => $type, 'entries' => $entries, 'malformed' => $malformed, 'error' => 'Could not read the journal (see the addon logs).'];
        }

        return ['type' => $type, 'entries' => $entries, 'malformed' => $malformed, 'error' => null];
    }

    /**
     * Event statistics: one query, totals per game and per metric over 7 days
     * (today included), 30 days and all time. Metrics in the order of
     * AddonStats::metrics() (all displayed, 0 if absent), then the unknown
     * metrics, sorted.
     *
     * @return array{games: array<string, array<int, array{metric: string, last7: int, last30: int, all: int}>>, since7: string, since30: string, error: ?string}
     */
    public function stats(): array
    {
        $today = now()->startOfDay();
        $since7 = $today->copy()->subDays(6)->toDateString();
        $since30 = $today->copy()->subDays(29)->toDateString();

        try {
            $query = Stat::query()->toBase();
            $day = $query->getGrammar()->wrap('day');
            $total = $query->getGrammar()->wrap('total');
            $rows = $query
                ->select(['game', 'metric'])
                ->selectRaw("SUM(CASE WHEN {$day} >= ? THEN {$total} ELSE 0 END) AS last7", [$since7])
                ->selectRaw("SUM(CASE WHEN {$day} >= ? THEN {$total} ELSE 0 END) AS last30", [$since30])
                ->selectRaw("SUM({$total}) AS alltime")
                ->groupBy('game', 'metric')
                ->get();
        } catch (\Throwable $ex) {
            $this->log('warning', 'Admin screen: could not read the statistics.', ['error' => $ex->getMessage()]);

            return ['games' => [], 'since7' => $since7, 'since30' => $since30, 'error' => 'Could not read the statistics (see the addon logs).'];
        }

        $byGame = [];
        foreach ($rows as $row) {
            $byGame[(string) $row->game][(string) $row->metric] = [
                'last7' => (int) $row->last7,
                'last30' => (int) $row->last30,
                'all' => (int) $row->alltime,
            ];
        }
        ksort($byGame);

        $known = AddonStats::metrics();
        $games = [];
        foreach ($byGame as $game => $metrics) {
            $unknown = array_diff(array_keys($metrics), $known);
            sort($unknown);
            foreach (array_merge($known, $unknown) as $metric) {
                $games[$game][] = ['metric' => $metric] + ($metrics[$metric] ?? ['last7' => 0, 'last30' => 0, 'all' => 0]);
            }
        }

        return ['games' => $games, 'since7' => $since7, 'since30' => $since30, 'error' => null];
    }

    /**
     * Current state, computed on demand, read-only (see the class header for
     * the load bounds). One computation at a time: otherwise `busy` is true
     * and nothing is computed.
     *
     * One row per server: game, node, number of files, recognized by name
     * (step 1), by the cache (step 2), in collision (step 1 without a
     * conclusion, and no conclusion from the cache), unknown (verified unknown
     * or never verified), of which unverified. "Unavailable" row with a short
     * reason on error, "not computed" past the time budget.
     *
     * @return array{busy: bool, error: ?string, rows: array, totals: array, servers_total: int, not_computed: int, games: string[], seconds: float, computed_at: string}
     */
    public function currentState(): array
    {
        $empty = ['busy' => false, 'error' => null, 'rows' => [], 'totals' => $this->emptyCounts(), 'servers_total' => 0, 'not_computed' => 0, 'games' => [], 'seconds' => 0.0, 'computed_at' => now()->toDateTimeString()];

        try {
            $lock = Cache::lock(static::LOCK_KEY, static::LOCK_SECONDS);
            $acquired = $lock->get();
        } catch (\Throwable $ex) {
            $this->log('warning', 'Admin screen: computation lock unavailable.', ['error' => $ex->getMessage()]);

            return ['error' => 'Could not compute the current state (lock unavailable, see the addon logs).'] + $empty;
        }
        if (! $acquired) {
            return ['busy' => true] + $empty;
        }

        try {
            return $this->computeState() + $empty;
        } catch (\Throwable $ex) {
            $this->log('warning', 'Admin screen: could not compute the current state.', ['error' => $ex->getMessage()]);

            return ['error' => 'Could not compute the current state (see the addon logs).'] + $empty;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $ex) {
                // The lock will expire on its own (LOCK_SECONDS).
            }
        }
    }

    /** Current-state computation, under the lock (see currentState()). */
    private function computeState(): array
    {
        $start = microtime(true);

        // Games with detection enabled, then the nests of those games (by
        // name, like the tab's controller), then the servers of those nests
        // (through their egg).
        $games = array_values(array_filter($this->gameMap->games(), fn (string $game) => $this->installed->isEnabled($game)));
        if ($games === []) {
            return ['games' => [], 'seconds' => round(microtime(true) - $start, 2)];
        }
        $nestIds = Nest::query()->get(['id', 'name'])
            ->filter(fn (Nest $nest) => in_array($this->gameMap->resolveGameFromNestName((string) $nest->name), $games, true))
            ->pluck('id')
            ->all();
        if ($nestIds === []) {
            return ['games' => $games, 'seconds' => round(microtime(true) - $start, 2)];
        }

        $query = Server::query()->whereHas('egg', fn ($q) => $q->whereIn('nest_id', $nestIds));
        $serversTotal = (clone $query)->count();
        $servers = $query->with(['egg.nest', 'node'])->orderBy('id')->limit(static::MAX_SERVERS)->get();

        // Addon version read once: keys of the servers' cache.
        $version = VerificationCache::addonVersion();
        $downNodes = [];
        $rows = [];
        $totals = $this->emptyCounts();
        $stopped = false;

        foreach ($servers as $server) {
            $nestName = $server->egg?->nest?->name;
            $game = is_string($nestName) ? $this->gameMap->resolveGameFromNestName($nestName) : null;
            $row = [
                'id' => (int) $server->id,
                'uuid' => (string) $server->uuid,
                'name' => (string) $server->name,
                'node' => (string) ($server->node?->name ?? ''),
                'game' => $game,
                'status' => self::STATE_OK,
                'reason' => null,
                'counts' => null,
                'cache_read' => false,
            ];

            if ($stopped || microtime(true) - $start + static::LISTING_TIMEOUT + static::LISTING_STALL_SECONDS > static::TIME_BUDGET) {
                // Not enough time left for a listing: this server and the
                // following ones aren't computed.
                $stopped = true;
                $rows[] = ['status' => self::STATE_NOT_COMPUTED, 'reason' => 'Not computed (time budget reached).'] + $row;
                continue;
            }
            if ($game === null || ! in_array($game, $games, true)) {
                $rows[] = ['status' => self::STATE_UNAVAILABLE, 'reason' => 'Game not recognized.'] + $row;
                continue;
            }
            if ($server->node === null) {
                $rows[] = ['status' => self::STATE_UNAVAILABLE, 'reason' => 'No node.'] + $row;
                continue;
            }
            if (isset($downNodes[$server->node_id])) {
                // Node already unreachable during this computation: no new call.
                $rows[] = ['status' => self::STATE_UNAVAILABLE, 'reason' => 'Node unreachable (not retried).'] + $row;
                continue;
            }

            [$failure, $entries] = $this->listing($server, $game);
            if ($failure !== null) {
                if (in_array($failure, [self::LISTING_UNREACHABLE, self::LISTING_INTERRUPTED, self::LISTING_TIMED_OUT], true)) {
                    // No complete answer from the node: its other servers
                    // aren't called again during this computation.
                    $downNodes[$server->node_id] = true;
                }
                $rows[] = ['status' => self::STATE_UNAVAILABLE, 'reason' => $failure] + $row;
                continue;
            }
            if (! is_array($entries)) {
                $rows[] = ['status' => self::STATE_UNAVAILABLE, 'reason' => 'Invalid response from Wings.'] + $row;
                continue;
            }

            $files = $this->installed->filterEntries($game, $entries);
            // Read-only access to the server's cache (neither HDEL nor EXPIRE).
            $cache = $version !== null ? VerificationCache::read($server->uuid, $version) : null;
            $counts = $this->count($this->installed->classify($game, $files, $cache ?? []));
            foreach ($counts as $k => $n) {
                $totals[$k] += $n;
            }
            $rows[] = ['counts' => $counts, 'cache_read' => $cache !== null] + $row;
        }

        $notComputed = max(0, $serversTotal - count($servers))
            + count(array_filter($rows, fn (array $r) => $r['status'] === self::STATE_NOT_COMPUTED));

        return [
            'games' => $games,
            'rows' => $rows,
            'totals' => $totals,
            'servers_total' => $serversTotal,
            'not_computed' => $notComputed,
            'seconds' => round(microtime(true) - $start, 2),
        ];
    }

    /**
     * One listing of the game's target folder (path taken from
     * gameConfig.json, never from the client), with the admin screen's
     * timeouts instead of the panel's, **bounded in volume**: streamed
     * response (`stream`), refused right away if its Content-Length exceeds
     * LISTING_MAX_BYTES, read in blocks while counting bytes and cut past it
     * (also without a Content-Length); total duration checked between two
     * blocks (LISTING_TIMEOUT), each read stalling LISTING_STALL_SECONDS at
     * most. As in Support\WingsFileReader, actually cutting requires
     * `allow_url_fopen` (Guzzle's StreamHandler): without it, nothing is read
     * (otherwise cURL would load the whole body into memory).
     *
     * Returns [failure reason (short UI text) or null, decoded JSON]. Never
     * throws.
     *
     * @return array{0: ?string, 1: mixed}
     */
    private function listing(Server $server, string $game): array
    {
        if (! filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return [self::LISTING_UNSUPPORTED, null];
        }

        $deadline = microtime(true) + static::LISTING_TIMEOUT;
        $body = null;
        try {
            $response = $this->files->setServer($server)->getHttpClient()->get(
                sprintf('/api/servers/%s/files/list-directory', $server->uuid),
                [
                    'query' => ['directory' => '/' . $this->gameMap->targetFolder($game)],
                    'stream' => true,
                    'connect_timeout' => static::LISTING_CONNECT_TIMEOUT,
                    'timeout' => static::LISTING_TIMEOUT,
                    'read_timeout' => static::LISTING_STALL_SECONDS,
                ]
            );
            $body = $response->getBody();

            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && (! ctype_digit($length) || strlen($length) > 15 || (int) $length > static::LISTING_MAX_BYTES)) {
                return [self::LISTING_TOO_LARGE, null];
            }

            $data = '';
            while (! $body->eof()) {
                if (microtime(true) > $deadline) {
                    return [self::LISTING_TIMED_OUT, null];
                }
                $chunk = $body->read(self::LISTING_CHUNK);
                if ($chunk === '') {
                    continue;
                }
                if (strlen($data) + strlen($chunk) > static::LISTING_MAX_BYTES) {
                    return [self::LISTING_TOO_LARGE, null];
                }
                $data .= $chunk;
            }
            if ($length !== '' && strlen($data) !== (int) $length) {
                // Body shorter than announced: transfer cut.
                return [self::LISTING_INTERRUPTED, null];
            }

            return [null, json_decode($data, true)];
        } catch (RequestException $ex) {
            $status = $ex->hasResponse() ? $ex->getResponse()->getStatusCode() : 0;
            if ($status >= 400) {
                // Wings answered with an error (missing folder: 500 on Wings,
                // like other errors; a single listing per server, so no proof
                // of absence).
                return ['Wings error (HTTP ' . $status . '): folder missing or unreadable.', null];
            }
            // No response, or transfer cut after a success status (e.g.
            // cURL 18): transfer error.
            return [$ex->hasResponse() ? self::LISTING_INTERRUPTED : self::LISTING_UNREACHABLE, null];
        } catch (\GuzzleHttp\Exception\GuzzleException $ex) {
            // Connection refused, timeout before the response…
            return [self::LISTING_UNREACHABLE, null];
        } catch (\Throwable $ex) {
            // Stream interrupted while reading the body (read timeout, cut).
            $this->log('warning', 'Admin screen: listing interrupted.', ['server' => $server->uuid, 'error' => $ex->getMessage()]);

            return [self::LISTING_INTERRUPTED, null];
        } finally {
            // Cut the connection (without reading the rest of the body).
            try {
                $body?->close();
            } catch (\Throwable $ex) {
                // Nothing more to do.
            }
        }
    }

    /**
     * A server's counts (exclusive categories): `name`, `cache`, `collision`
     * (step 1 without a conclusion and no conclusion from the cache),
     * `unknown` (verified unknown + never verified, collisions aside), of
     * which `unverified` (never verified).
     *
     * @param array<int, array{class: string, collision: ?array}> $classified
     * @return array{files: int, name: int, cache: int, collision: int, unknown: int, unverified: int}
     */
    private function count(array $classified): array
    {
        $counts = $this->emptyCounts();
        foreach ($classified as $item) {
            $counts['files']++;
            if ($item['class'] === InstalledModsService::CLASS_NAME) {
                $counts['name']++;
            } elseif ($item['class'] === InstalledModsService::CLASS_CACHE) {
                $counts['cache']++;
            } elseif ($item['collision'] !== null) {
                $counts['collision']++;
            } else {
                $counts['unknown']++;
                if ($item['class'] === InstalledModsService::CLASS_UNVERIFIED) {
                    $counts['unverified']++;
                }
            }
        }

        return $counts;
    }

    /** @return array{files: int, name: int, cache: int, collision: int, unknown: int, unverified: int} */
    private function emptyCounts(): array
    {
        return ['files' => 0, 'name' => 0, 'cache' => 0, 'collision' => 0, 'unknown' => 0, 'unverified' => 0];
    }

    /**
     * A mod for display: name, ids and generic links. An id without the
     * expected format gets no link (it's still displayed, escaped by the
     * view).
     */
    private function describeMod(int $id, mixed $name, mixed $modrinthId, mixed $curseforgeId): array
    {
        $modrinthId = is_string($modrinthId) ? $modrinthId : null;
        $curseforgeId = is_numeric($curseforgeId) ? (int) $curseforgeId : null;

        return [
            'id' => $id,
            'name' => is_string($name) ? $name : '',
            'modrinth_id' => $modrinthId,
            'modrinth_url' => $modrinthId !== null && preg_match(self::MODRINTH_ID, $modrinthId) === 1
                ? self::MODRINTH_PROJECT_URL . rawurlencode($modrinthId)
                : null,
            'curseforge_id' => $curseforgeId,
            'curseforge_url' => $curseforgeId !== null && $curseforgeId >= 1 && $curseforgeId <= self::UINT32_MAX
                ? self::CURSEFORGE_PROJECT_URL . $curseforgeId
                : null,
        ];
    }

    /**
     * Reads a journal file from the end, in blocks, JOURNAL_MAX_BYTES at
     * most, and adds its lines (most recent first) to `$entries` up to
     * `$limit`. The first fully read line is only kept if the start of the
     * file is reached (otherwise it may be cut). Missing or unreadable file →
     * nothing.
     */
    private function readJournalBackwards(string $path, ?string $type, int $limit, array &$entries, int &$malformed): void
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            return;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            $stat = fstat($handle);
            $pos = is_array($stat) ? (int) $stat['size'] : 0;
            $read = 0;
            $carry = '';

            while ($pos > 0 && $read < self::JOURNAL_MAX_BYTES && count($entries) < $limit) {
                $length = min(self::JOURNAL_BLOCK, $pos, self::JOURNAL_MAX_BYTES - $read);
                $pos -= $length;
                if (fseek($handle, $pos) !== 0) {
                    return;
                }
                $chunk = fread($handle, $length);
                if (! is_string($chunk)) {
                    return;
                }
                $read += strlen($chunk);

                $lines = explode("\n", $chunk . $carry);
                // The first piece may be a cut line: kept for the next block.
                $carry = array_shift($lines);
                for ($i = count($lines) - 1; $i >= 0 && count($entries) < $limit; $i--) {
                    $this->addJournalLine($lines[$i], $type, $entries, $malformed);
                }
            }

            // Start of the file reached: the first line is complete.
            if ($pos === 0 && count($entries) < $limit) {
                $this->addJournalLine($carry, $type, $entries, $malformed);
            }
        } finally {
            @fclose($handle);
        }
    }

    /** Parses a journal line and adds it if it matches the filter. */
    private function addJournalLine(string $line, ?string $type, array &$entries, int &$malformed): void
    {
        if (trim($line) === '') {
            return;
        }
        $entry = $this->parseJournalLine($line);
        if ($entry === null) {
            $malformed++;

            return;
        }
        if ($type === null || $entry['type'] === $type) {
            $entries[] = $entry;
        }
    }

    /**
     * A journal line (`<date> <type> <message> <JSON context>`, see
     * CollisionJournal) split up, or null if it's malformed (unknown date or
     * type). Context: JSON decoded then re-encoded readably (null if absent;
     * if it doesn't decode, the whole line is the message). Text made valid
     * UTF-8; escaped by the view.
     *
     * @return array{date: string, type: string, message: string, context: ?string}|null
     */
    private function parseJournalLine(string $line): ?array
    {
        $line = mb_scrub(rtrim($line, "\r"), 'UTF-8');
        if (preg_match(self::JOURNAL_LINE, $line, $m) !== 1 || ! in_array($m[2], CollisionJournal::TYPES, true)) {
            return null;
        }

        $rest = $m[3];
        $message = $rest;
        $context = null;
        $pos = strpos($rest, '{');
        for ($attempt = 0; $pos !== false && $attempt < self::JOURNAL_JSON_ATTEMPTS; $attempt++) {
            if ($pos === 0 || $rest[$pos - 1] === ' ') {
                $decoded = json_decode(substr($rest, $pos), true);
                if (is_array($decoded)) {
                    $message = substr($rest, 0, $pos);
                    $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                    $context = is_string($encoded) ? $encoded : null;
                    break;
                }
            }
            $pos = strpos($rest, '{', $pos + 1);
        }

        if ($context !== null && mb_strlen($context, 'UTF-8') > self::JOURNAL_CONTEXT_MAX) {
            $context = mb_substr($context, 0, self::JOURNAL_CONTEXT_MAX, 'UTF-8') . "\n…";
        }

        return ['date' => $m[1], 'type' => $m[2], 'message' => trim($message), 'context' => $context];
    }

    /** Addon log that never throws. */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            AddonBrowserLogger::{$level}($message, $context);
        } catch (\Throwable $ex) {
            // Nothing more to do.
        }
    }
}
