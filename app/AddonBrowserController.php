<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Eloquent\BackupRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\AddonGameMapService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModrinthService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\CurseForgeService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModpackInstallService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\GameVersionService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\ModReferenceService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\InstalledModsService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Jobs\RecordDownloadedModJob;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Jobs\IndexCurseForgeSearchJob;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\ExternalApi;

class AddonBrowserController extends Controller
{
    /**
     * Domains a file may be downloaded from (download()).
     * ⬇️ Add here the hostname (no scheme, no path) of any new CDN to support.
     */
    private const ALLOWED_DOWNLOAD_HOSTS = [
        // Modrinth CDN hosts (cdn.modrinth.com):
        'cdn.modrinth.com',
        // CurseForge CDN hosts (forgecdn.net):
        'edge.forgecdn.net',
        // edge.forgecdn.net redirects (302) to mediafilez.forgecdn.net for the actual
        // file — that's the real CurseForge CDN hostname; media.forgecdn.net is kept
        // for older URLs but is no longer used for file downloads.
        'media.forgecdn.net',
        'mediafilez.forgecdn.net',
    ];

    /**
     * Largest file downloaded by download(). The file is kept in memory before
     * being sent to the daemon, and PHP-FPM's memory_limit is commonly 128 MB:
     * without a limit, a large .zip would end in a 500 error. 50 MB leaves a wide
     * margin for mods (22.2 MB at most over the whole history of Biomes
     * O'Plenty).
     */
    private const MAX_DOWNLOAD_BYTES = 50 * 1024 * 1024;

    private const DOWNLOAD_TOO_LARGE_MESSAGE = 'File too large (50 MB maximum).';

    /**
     * Requested sort → `index` parameter of the Modrinth search. The keys are
     * also the list of accepted values for `sort`.
     */
    private const SORT_MODRINTH = [
        'relevance' => 'relevance',
        'popularity' => 'follows',
        'follows' => 'follows',
        'downloads' => 'downloads',
        'newest' => 'newest',
        'updated' => 'updated',
    ];

    /**
     * Requested sort → `sortField` of the CurseForge search (descending).
     * CurseForge has no relevance sort: `relevance` and `follows` fall back on
     * popularity (2). 6 = total downloads, 11 = release date, 3 = update date.
     */
    private const SORT_CURSEFORGE = [
        'relevance' => 2,
        'popularity' => 2,
        'follows' => 2,
        'downloads' => 6,
        'newest' => 11,
        'updated' => 3,
    ];

    /**
     * Accepted format for a game version ("Game Version" filters): letters,
     * digits, dots and dashes, 32 characters at most. Enough for Minecraft
     * versions (1.21.1, 26.2) and prevents injecting anything else into a
     * Modrinth facet.
     */
    private const GAME_VERSION_RULE = 'nullable|string|max:32|regex:/^[0-9A-Za-z][0-9A-Za-z.\-]*$/';

    private const UNSUPPORTED_GAME_MESSAGE = "This server's game isn't recognized or isn't supported by the Addon Browser.";

    private DaemonFileRepository $fileRepository;
    private AddonGameMapService $gameMapService;
    private ModrinthService $modrinthService;
    private CurseForgeService $curseForgeService;
    private ModpackInstallService $modpackInstallService;
    private BackupRepository $backupRepository;
    private GameVersionService $gameVersionService;

    public function __construct(
        DaemonFileRepository $fileRepository,
        AddonGameMapService $gameMapService,
        ModrinthService $modrinthService,
        CurseForgeService $curseForgeService,
        ModpackInstallService $modpackInstallService,
        BackupRepository $backupRepository,
        GameVersionService $gameVersionService
    ) {
        $this->fileRepository = $fileRepository;
        $this->gameMapService = $gameMapService;
        $this->modrinthService = $modrinthService;
        $this->curseForgeService = $curseForgeService;
        $this->modpackInstallService = $modpackInstallService;
        $this->backupRepository = $backupRepository;
        $this->gameVersionService = $gameVersionService;
    }

    /**
     * Search for addons across Modrinth (Minecraft) or CurseForge (other games).
     */
    public function search(Request $request)
    {
        $request->validate([
            'game' => 'required|string|max:64',
            'query' => 'nullable|string|max:256',
            'classId' => 'nullable|integer',
            // Text on Modrinth (e.g. "adventure"), integer on CurseForge: checked
            // afterwards depending on the platform (see below). An `integer` rule
            // here would reject every Modrinth category, and the search would then
            // return zero results.
            'categoryId' => 'nullable|string|max:64',
            'offset' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:50',
            'loader' => 'nullable|string|max:64',
            // Sort ("Sort By" menu), translated per platform, see
            // SORT_MODRINTH / SORT_CURSEFORGE.
            'sort' => 'nullable|string|in:' . implode(',', array_keys(self::SORT_MODRINTH)),
            // Game version, in mods mode as in modpacks mode.
            'gameVersion' => self::GAME_VERSION_RULE,
            // Mode: `mod` (default) or `modpack`. Addonbrowser only offers mods and
            // modpacks (never plugins). Modrinth uses it as a facet; CurseForge, to
            // pick the enforced class (see resolveCurseForgeClassId()).
            'project_type' => 'nullable|string|in:mod,modpack',
            // Minecraft offers 2 platforms (see
            // AddonGameMapService::availablePlatforms()); the other games only have
            // one, so this parameter stays optional to break nothing for them.
            'platform' => 'nullable|string|in:modrinth,curseforge',
        ]);

        $game = strtolower((string) $request->input('game', 'minecraft'));
        $query = (string) $request->input('query', '');
        $classId = $request->input('classId');
        $categoryId = $request->input('categoryId');
        $offset = (int) $request->input('offset', 0);
        $limit = (int) $request->input('limit', 12);
        // Loader kept only if it's one of the game's loaders (never free text
        // passed on to the API).
        $loader = in_array($request->input('loader'), $this->gameMapService->loaders($game), true)
            ? $request->input('loader')
            : null;
        $sort = $request->input('sort') ?: 'relevance';
        $gameVersion = $request->input('gameVersion') ?: null;
        $projectType = $request->input('project_type') === 'modpack' ? 'modpack' : 'mod';
        $platform = $this->resolvePlatform($game, $request->input('platform'));

        AddonBrowserLogger::debug('Addon search request', ['game' => $game, 'platform' => $platform, 'query' => $query, 'offset' => $offset, 'loader' => $loader, 'project_type' => $projectType]);

        try {
            if ($platform === 'modrinth') {
                // Category kept only if it's in the known list for this mode
                // (never free text injected into the facets).
                $knownCategories = array_column($this->modrinthService->getCategories($projectType)['data'] ?? [], 'id');
                $modrinthCategory = in_array($categoryId, $knownCategories, true) ? $categoryId : null;

                $result = $this->modrinthService->search(
                    $query ?: null,
                    $projectType,
                    $offset,
                    $limit,
                    self::SORT_MODRINTH[$sort],
                    $loader,
                    $modrinthCategory,
                    $gameVersion
                );
                AddonBrowserLogger::debug('Modrinth search result', ['result' => $result]);
                return response()->json($result);
            } else {
                // Class enforced server-side (the game's mods or modpacks), never
                // the client's as is — see resolveCurseForgeClassId().
                [$allowed, $effectiveClassId] = $this->resolveCurseForgeClassId($game, $projectType, $classId);
                if (! $allowed) {
                    return response()->json(['data' => [], 'pagination' => ['index' => $offset, 'pageSize' => $limit, 'totalCount' => 0]]);
                }

                $result = $this->curseForgeService->search(
                    $game,
                    $query ?: null,
                    $effectiveClassId,
                    is_string($categoryId) && ctype_digit($categoryId) ? (int) $categoryId : null,
                    $offset,
                    $limit,
                    self::SORT_CURSEFORGE[$sort],
                    $loader,
                    $gameVersion
                );
                AddonBrowserLogger::debug('CurseForge search result', ['result' => $result]);
                // Indexing of the recent files into the installed-mods
                // referential, in the background: never changes the response.
                $this->queueSearchIndexing($game, $projectType, $result, $request->user()?->getAuthIdentifier());
                return response()->json($result);
            }
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('Addon search error: ' . $ex->getMessage(), ['exception' => $ex]);
            return response()->json([
                'data' => [],
                'pagination' => ['index' => $offset, 'pageSize' => $limit, 'totalCount' => 0]
            ]);
        }
    }

    /**
     * Get available categories/classes for a game.
     */
    public function categories(Request $request)
    {
        $request->validate([
            'game' => 'required|string|max:64',
            'classId' => 'nullable|integer',
            'project_type' => 'nullable|string|in:mod,modpack',
            'platform' => 'nullable|string|in:modrinth,curseforge',
        ]);

        $game = strtolower((string) $request->input('game', 'minecraft'));
        $classId = $request->input('classId');
        $projectType = $request->input('project_type') === 'modpack' ? 'modpack' : 'mod';
        $platform = $this->resolvePlatform($game, $request->input('platform'));

        try {
            if ($platform === 'modrinth') {
                return response()->json($this->modrinthService->getCategories($projectType));
            }

            // Enforced class (see resolveCurseForgeClassId()). A game with a
            // single mods class (Minecraft, Hytale) gets its subcategories
            // directly, without a list of classes: no "Browse by". A game with
            // several mods classes (Palworld) gets its classes and their
            // subcategories, filtered to keep only mods.
            [$allowed, $effectiveClassId] = $this->resolveCurseForgeClassId($game, $projectType, $classId);
            if (! $allowed) {
                return response()->json(['data' => []]);
            }

            $allowedClassIds = $projectType === 'modpack'
                ? [$this->curseForgeService->modpackClassId($game)]
                : $this->curseForgeService->modClassIds($game);
            $data = array_values(array_filter(
                $this->curseForgeService->getCategories($game, $effectiveClassId)['data'] ?? [],
                fn ($c) => in_array((int) (! empty($c['isClass']) ? ($c['id'] ?? 0) : ($c['classId'] ?? 0)), $allowedClassIds, true)
            ));

            return response()->json(['data' => $data]);
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('Categories fetch error: ' . $ex->getMessage());
            return response()->json(['data' => []]);
        }
    }

    public function download(Request $request)
    {
        $request->validate([
            // 2048 characters at most: a CDN URL is much shorter; also bounds
            // what's passed on to the referential job.
            'downloadUrl' => 'required|url|max:2048',
            'serverUuid' => 'required|string|exists:servers,uuid',
        ]);

        $server = Server::where('uuid', $request->input('serverUuid'))
            ->with('egg.nest')
            ->firstOrFail();

        $this->authorize('file.create', $server);
        // Server state: suspended, node under maintenance, installing,
        // restoring or transferring → the panel's 409, like its client API (the
        // addon's routes don't go through AuthenticateServerAccess).
        $server->validateCurrentState();

        // Game resolved only from the server's nest, never from the request:
        // a server whose game isn't recognized gets nothing.
        $game = $this->resolveGameForServer($server);
        if ($game === null) {
            return response()->json(['success' => false, 'message' => self::UNSUPPORTED_GAME_MESSAGE], 422);
        }

        $url = $request->input('downloadUrl');

        // Filename derived from the URL, never from a request parameter: a
        // `filename` sent by the browser would be free, unrelated to the file
        // actually downloaded (Sodium's content written as `jei-1.20.1.jar`).
        // Both CDNs end the URL with the official name, encoded:
        // cdn.modrinth.com/data/<project>/versions/<version>/<name> and
        // edge.forgecdn.net/files/<id÷1000>/<id mod 1000>/<name>. (Angle brackets,
        // not braces: on install, Blueprint replaces its brace placeholders —
        // version, identifier, name… — in every file of the extension, comments
        // included.) rawurldecode and not urldecode: a literal `+` in the path
        // stays a `+`.
        // basename() on the raw path isolates the last segment; a separator that
        // would only appear once decoded (%2F, %5C) is rejected below rather
        // than silently truncated.
        $urlPath = parse_url($url, PHP_URL_PATH);
        $filename = is_string($urlPath) ? rawurldecode(basename($urlPath)) : '';

        // Only rejects the truly dangerous characters: leftover path
        // separators, null byte, control characters.
        // `!== 0` and not a plain boolean test: on an invalid UTF-8 name
        // (possible once the URL is decoded, e.g. %FF%00), preg_match with /u
        // returns false instead of 1, which would let the null byte through.
        if (!mb_check_encoding($filename, 'UTF-8') || preg_match('/[\/\\\\\x00-\x1F]/u', $filename) !== 0) {
            return response()->json(['success' => false, 'message' => 'Invalid filename.'], 422);
        }

        // Rejects a name that's empty or made only of dots (., ..)
        $trimmed = trim($filename, '.');
        if ($trimmed === '' || $filename === '.' || $filename === '..') {
            return response()->json(['success' => false, 'message' => 'Invalid filename.'], 422);
        }

        // 255 bytes: the filename limit of common file systems.
        if ($filename === '' || strlen($filename) > 255) {
            return response()->json(['success' => false, 'message' => 'Invalid filename.'], 422);
        }

        // A single target folder per game (gameConfig.json), never chosen by
        // the client: addonbrowser only downloads mods.
        $targetFolder = $this->gameMapService->targetFolder($game);

        $host = parse_url($url, PHP_URL_HOST);
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || !in_array($host, self::ALLOWED_DOWNLOAD_HOSTS, true)) {
            return response()->json(['success' => false, 'message' => 'Download domain not allowed.'], 422);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $allowedExtensions = $this->gameMapService->allowedExtensions($game);
        if (!in_array($extension, $allowedExtensions, true)) {
            return response()->json(['success' => false, 'message' => "Extension not allowed: .$extension"], 422);
        }

        try {
            // Redirects followed by hand (allow_redirects disabled on the HTTP
            // client): edge.forgecdn.net always redirects with a 302 to
            // mediafilez.forgecdn.net for the actual file, so blocking every
            // redirect would break CurseForge downloads.
            // A hop is only followed if its Location points to a whitelisted
            // host; otherwise it's blocked, which prevents an allowed host from
            // redirecting the server-side request to an arbitrary target (SSRF).
            // Bounded to 5 hops to avoid an infinite loop.
            $currentUrl = $url;
            $response = null;

            for ($hop = 0; $hop < 5; $hop++) {
                // stream: the body is only read on demand, in chunks (see
                // below), so it can be cut past MAX_DOWNLOAD_BYTES during the
                // transfer rather than after being fully loaded into memory.
                $response = ExternalApi::download()->withOptions(['allow_redirects' => false, 'stream' => true])->get($currentUrl);

                if (! $response->redirect()) {
                    break;
                }

                $location = $response->header('Location');
                $nextHost = $location ? parse_url($location, PHP_URL_HOST) : null;
                $nextScheme = $location ? strtolower((string) parse_url($location, PHP_URL_SCHEME)) : null;

                if (! $nextHost || $nextScheme !== 'https' || ! in_array($nextHost, self::ALLOWED_DOWNLOAD_HOSTS, true)) {
                    AddonBrowserLogger::warning('Addon download blocked: redirect to a non-whitelisted host', [
                        'url' => $currentUrl,
                        'status' => $response->status(),
                        'location' => $location,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'The download server returned an unexpected redirect.'
                    ], 502);
                }

                $currentUrl = $location;
            }

            if ($response->redirect()) {
                AddonBrowserLogger::warning('Addon download blocked: too many redirects', ['url' => $url]);
                return response()->json([
                    'success' => false,
                    'message' => 'Too many redirects during the download.'
                ], 502);
            }

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'The download from the platform (Modrinth/CurseForge) failed.'
                ], 502);
            }

            // Immediate refusal if the CDN already announces a size that's too
            // large, then an actual count while reading: Content-Length may be
            // missing or wrong, it's never the only check.
            $announced = $response->header('Content-Length');
            if ($announced !== '' && ctype_digit($announced) && (int) $announced > self::MAX_DOWNLOAD_BYTES) {
                return response()->json(['success' => false, 'message' => self::DOWNLOAD_TOO_LARGE_MESSAGE], 422);
            }

            $stream = $response->toPsrResponse()->getBody();
            $content = '';
            while (! $stream->eof()) {
                $content .= $stream->read(1024 * 1024);
                if (strlen($content) > self::MAX_DOWNLOAD_BYTES) {
                    $stream->close();
                    return response()->json(['success' => false, 'message' => self::DOWNLOAD_TOO_LARGE_MESSAGE], 422);
                }
            }

            $path = '/' . $targetFolder . '/' . $filename;
            $this->fileRepository->setServer($server)->putContent($path, $content);

            // Recorded in the installed-mods referential, in the background:
            // never changes the download's response.
            $this->queueDownloadRecord($game, $url, $content);

            return response()->json([
                'success' => true,
                'path' => $path,
                'game' => $game,
                'target_folder' => $targetFolder,
            ]);
        } catch (DaemonConnectionException $ex) {
            // Clean messages only: the exception's detail goes to the logs,
            // never into the response (it could contain internal information:
            // cURL message, URL…). Node or platform unreachable: no retry, one
            // message and that's it.
            AddonBrowserLogger::error('Addon download: daemon unreachable', ['server' => $server->uuid, 'exception' => $ex]);
            return response()->json([
                'success' => false,
                'message' => 'Service unavailable, please try again later.',
            ], 503);
        } catch (ConnectionException $ex) {
            AddonBrowserLogger::error('Addon download: download host unreachable', ['url' => $url, 'exception' => $ex]);
            return response()->json([
                'success' => false,
                'message' => 'The download platform isn\'t responding, please try again later.',
            ], 503);
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('Addon download: unexpected error', ['server' => $server->uuid, 'url' => $url, 'exception' => $ex]);
            return response()->json([
                'success' => false,
                'message' => 'Unexpected error during the download.',
            ], 500);
        }
    }

    /**
     * After a successful download: dispatch (`low` queue) of the job that
     * records the file in the installed-mods referential, with only the game,
     * the URL reduced to `https://<host><path>` (no query nor fragment: only
     * the host and path are used by the job), the SHA1 and the size (never the
     * bytes, which would go through Redis). Nothing for a game without
     * installed-mods detection (`installed_detection`), nor for a file whose
     * extension isn't part of it (a `.zip`). Any error (computation, Redis
     * unavailable…) is logged and swallowed: the file is already written, the
     * download stays successful. The download itself always uses the URL as
     * received.
     */
    private function queueDownloadRecord(string $game, string $url, string $content): void
    {
        try {
            if (! app(ModReferenceService::class)->acceptsGame($game)) {
                return;
            }

            $host = parse_url($url, PHP_URL_HOST);
            $path = parse_url($url, PHP_URL_PATH);
            if (! is_string($host) || ! is_string($path)
                || ! $this->gameMapService->hasInstalledDetectionExtension($game, rawurldecode(basename($path)))) {
                return;
            }

            Bus::dispatch(new RecordDownloadedModJob($game, 'https://' . $host . $path, sha1($content), strlen($content)));
        } catch (\Throwable $ex) {
            $this->logQuietly('Mod referential: download recording job not dispatched.', ['game' => $game, 'url' => $url, 'error' => $ex->getMessage()]);
        }
    }

    /**
     * After a CurseForge search: dispatch (`low` queue) of the job indexing
     * the recent files, only in `mod` mode (a modpack isn't a file of `mods`)
     * and for a game with installed-mods detection. Minimal, bounded payload
     * (IndexCurseForgeSearchJob::payloadFromSearch()): only the mods whose
     * `gameId` is the game's, and whose extension is one of
     * `installed_detection.extensions`, never the raw response. The same page
     * is only indexed once every 10 minutes (claimDispatch()), and a user
     * doesn't trigger more than RATE_LIMIT_PER_MINUTE dispatches per minute
     * (RateLimiter): past that, the search answers normally, without indexing.
     * Any error is logged and swallowed: the search's response never changes.
     */
    private function queueSearchIndexing(string $game, string $projectType, array $result, int|string|null $userId): void
    {
        try {
            if ($projectType !== 'mod' || $userId === null || ! app(ModReferenceService::class)->acceptsGame($game)) {
                return;
            }

            $gameId = $this->curseForgeService->getGameId($game);
            if ($gameId === null) {
                return;
            }

            $files = IndexCurseForgeSearchJob::payloadFromSearch(
                $result,
                $gameId,
                $this->curseForgeService->modClassIds($game),
                $this->gameMapService->installedDetectionExtensions($game)
            );
            if ($files === []) {
                return;
            }

            $rateKey = IndexCurseForgeSearchJob::rateLimitKey($userId);
            if (RateLimiter::tooManyAttempts($rateKey, IndexCurseForgeSearchJob::RATE_LIMIT_PER_MINUTE)) {
                AddonBrowserLogger::debug('Mod referential: dispatch limit reached, search not indexed.', ['game' => $game]);
                return;
            }
            if (! IndexCurseForgeSearchJob::claimDispatch($game, $files)) {
                return;
            }
            RateLimiter::hit($rateKey, 60);

            Bus::dispatch(new IndexCurseForgeSearchJob($game, $gameId, $files));
        } catch (\Throwable $ex) {
            $this->logQuietly('Mod referential: search indexing job not dispatched.', ['game' => $game, 'error' => $ex->getMessage()]);
        }
    }

    /** Warning in the addon's logs, never throwing. */
    private function logQuietly(string $message, array $context): void
    {
        try {
            AddonBrowserLogger::warning($message, $context);
        } catch (\Throwable $ex) {
            // Nothing more to do.
        }
    }

    /**
     * Resolves a server's game key from its nest, or null if that nest isn't
     * declared for any game in gameConfig.json. No fallback: neither on
     * `minecraft`, nor on a value sent by the client, nor on a default entry —
     * an unrecognized game isn't supported, see architecture-addonbrowser-addon.md §3.
     */
    private function resolveGameForServer(Server $server): ?string
    {
        $nestName = $server->egg->nest->name ?? null;

        return $nestName !== null
            ? $this->gameMapService->resolveGameFromNestName($nestName)
            : null;
    }

    /**
     * Return the resolved game for a given server, so the frontend can align search/categories/download.
     */
    public function resolveGame(Request $request)
    {
        $request->validate([
            'serverUuid' => 'required|string|exists:servers,uuid',
        ]);

        $server = Server::where('uuid', $request->input('serverUuid'))
            ->with('egg.nest')
            ->firstOrFail();

        // Same check as download(): without it, any authenticated user could
        // query the game/nest of any serverUuid, including servers that aren't
        // theirs (IDOR).
        $this->authorize('file.create', $server);

        $game = $this->resolveGameForServer($server);

        // game = null and supported = false for an unrecognized nest: the
        // frontend then shows "game not recognized" instead of the Addon Browser.
        return response()->json([
            'game' => $game,
            'supported' => $game !== null,
            // For the "with backup" toggle of the modpack install flow
            // (hasBackupSlot = backup_limit > 0 && backup_used < backup_limit).
            // No extra network call: this endpoint is already loaded on mount
            // and already authorized on this specific server. Checked again
            // anyway on the backend at actual install time (in
            // EggSwitchRequestService), never trusted as is.
            'backup_limit' => $server->backup_limit,
            'backup_used' => $this->backupRepository->getNonFailedBackups($server)->count(),
        ]);
    }

    /**
     * GET /extensions/addonbrowser/modpack-install-available — read-only,
     * WITHOUT serverUuid: availability (an egg mapped for this game/platform,
     * and whitelisted in eggswitch) is a panel-wide fact, not per server. See
     * ModpackInstallService and architecture-addonbrowser-addon.md §6: the
     * frontend doesn't and never will need to know an egg id.
     *
     * Also returns the Docker images (Java versions) declared by the target
     * egg, and — if `gameVersions` is given — a recommendation among them (see
     * ModpackInstallService::recommendedDockerImage(), shared with
     * MinecraftJavaImages). The frontend passes the RAW game_versions array of
     * the chosen version (as received from Modrinth/CurseForge): it's here,
     * never on the client, that the Minecraft version to use is decided — the
     * frontend doesn't need to know how to interpret that format. Only to
     * fill/pre-fill a selector in the frontend: the chosen value is never
     * checked again here, only at actual installModpack() time
     * (EggSwitchRequestService::resolveDockerImage()).
     */
    public function modpackInstallAvailable(Request $request)
    {
        $request->validate([
            'game' => 'required|string|max:64',
            'platform' => 'required|string|max:64',
            'gameVersions' => 'nullable|array',
            'gameVersions.*' => 'string|max:32',
        ]);

        $game = (string) $request->input('game');
        $platform = (string) $request->input('platform');
        $gameVersions = (array) $request->input('gameVersions', []);

        $available = $this->modpackInstallService->isAvailable($game, $platform);

        $dockerImages = $available
            ? collect($this->modpackInstallService->dockerImages($game, $platform))
                ->map(fn ($image, $label) => ['id' => $image, 'label' => $label])
                ->values()
                ->all()
            : [];

        return response()->json([
            'available' => $available,
            'docker_images' => $dockerImages,
            'recommended_image' => $available
                ? $this->modpackInstallService->recommendedDockerImage($game, $platform, $gameVersions)
                : null,
        ]);
    }

    /**
     * POST /extensions/addonbrowser/install-modpack — the only route of this
     * group that triggers something destructive (a full reinstall through
     * eggswitch). Same rigor as download(): shape validation, server
     * resolution, `file.create` before anything — but ZERO security rule
     * re-implemented beyond that (whitelist, variable filtering, node,
     * backup, double-operation guard stay entirely up to
     * EggSwitchRequestService::requestReinstallForAddon(), called once through
     * ModpackInstallService::install()). See architecture-addonbrowser-addon.md
     * §6 for the full reasoning.
     */
    public function installModpack(Request $request)
    {
        $validated = $request->validate([
            'serverUuid' => 'required|string|exists:servers,uuid',
            'platform' => 'required|string|max:64',
            'projectId' => 'required|string|max:60',
            // The exact expected format depends on the platform — see
            // ModpackInstallService::buildEnvironment(): Modrinth version_number
            // (never the version id), or CurseForge fileId (an integer, as a string here).
            'versionNumber' => 'required|string|max:60',
            'with_backup' => 'sometimes|boolean',
            // Application-level confirmation on top of any frontend checkbox:
            // never trust a UI-only confirmation for a destructive action.
            'confirm_server_name' => 'required|string',
            // dockerImage: the user's explicit choice in the Java selector
            // (pre-filled by modpack-install-available, see above) — never
            // trusted as is, checked again against the target egg's images by
            // EggSwitchRequestService::resolveDockerImage().
            'dockerImage' => 'nullable|string|max:255',
            // gameVersions: RAW array (as received from Modrinth/CurseForge),
            // also passed so that ModpackInstallService can compute a
            // recommendation itself if dockerImage is missing (see
            // MinecraftJavaImages) — never used for anything else, and never
            // interpreted in the frontend.
            'gameVersions' => 'nullable|array',
            'gameVersions.*' => 'string|max:32',
        ]);

        $server = Server::where('uuid', $validated['serverUuid'])
            ->with('egg.nest')
            ->firstOrFail();
        $this->authorize('file.create', $server);
        // Server state: see download().
        $server->validateCurrentState();

        // Same rule as download(): the game comes from the server's nest, never
        // from the request, and an unrecognized game isn't supported.
        $game = $this->resolveGameForServer($server);
        if ($game === null) {
            abort(422, self::UNSUPPORTED_GAME_MESSAGE);
        }

        // The egg's install script starts by wiping the server: an invalid
        // modpack or version would leave it empty. So BEFORE calling eggswitch,
        // check that it really is a modpack of this game and that the version
        // belongs to it (see assertModpackVersionExists()).
        $this->assertModpackVersionExists($game, $validated['platform'], $validated['projectId'], $validated['versionNumber']);

        $pending = $this->modpackInstallService->install(
            $request->user(),
            $server,
            $game,
            $validated['platform'],
            $validated['projectId'],
            $validated['versionNumber'],
            $validated['confirm_server_name'],
            (bool) ($validated['with_backup'] ?? false),
            $validated['dockerImage'] ?? null,
            $validated['gameVersions'] ?? []
        );

        return response()->json(['data' => $pending], 202);
    }

    /**
     * Refuses (abort) an invalid modpack/version pair before any install.
     * Two levels:
     * 1. format, per platform: CurseForge = two integers (projectId and
     *    fileId), Modrinth = id/slug `[\w-]+` (same rule as the Modrinth
     *    modpack egg's variable) and a version number without separators or
     *    control characters;
     * 2. actual existence through the API: the project is a modpack (of the
     *    right game on CurseForge) and the version/file belongs to it.
     * API unreachable: refusal with a clean message, never an unchecked
     * install, never an automatic retry.
     */
    private function assertModpackVersionExists(string $game, string $platform, string $projectId, string $versionNumber): void
    {
        $platform = strtolower(trim($platform));

        if (! $this->modpackInstallService->isAvailable($game, $platform)) {
            abort(422, 'No modpack egg available for this game/platform.');
        }

        $notFound = 'Modpack or version not found on this platform.';

        $validFormat = match ($platform) {
            'curseforge' => ctype_digit($projectId) && ctype_digit($versionNumber),
            'modrinth' => preg_match('/^[\w-]+$/', $projectId) === 1
                && preg_match('/^[^\/\\\\\x00-\x1F]+$/u', $versionNumber) === 1,
            default => false,
        };
        if (! $validFormat) {
            abort(422, $notFound);
        }

        try {
            $found = $platform === 'curseforge'
                ? $this->curseForgeService->findModpackFile($game, (int) $projectId, (int) $versionNumber)
                : $this->modrinthService->findModpackVersion($projectId, $versionNumber);
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('Modpack verification: platform API unavailable', [
                'platform' => $platform,
                'projectId' => $projectId,
                'versionNumber' => $versionNumber,
                'exception' => $ex,
            ]);
            abort(503, 'Service unavailable, please try again later.');
        }

        if ($found === null) {
            abort(422, $notFound);
        }
    }

    public function loaders(Request $request)
    {
        $request->validate([
            'game' => 'required|string|max:64',
            'platform' => 'nullable|string|in:modrinth,curseforge',
        ]);

        $game = strtolower($request->input('game'));
        $platform = $this->resolvePlatform($game, $request->input('platform'));

        // The game's loaders (gameConfig.json), in their order, limited to those
        // the platform really knows. Game without declared loaders → empty list,
        // and the frontend hides the filter.
        $wanted = $this->gameMapService->loaders($game);
        if ($wanted === []) {
            return response()->json([]);
        }

        try {
            $supported = $platform === 'modrinth'
                ? $this->modrinthService->getLoaders('mod')
                : $this->curseForgeService->getModLoaderTypes();

            return response()->json(array_values(array_intersect($wanted, $supported)));
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('Loaders fetch error: ' . $ex->getMessage());
            return response()->json([]);
        }
    }

    /**
     * Resolves the effective platform for a request: the requested one if
     * it's one of those available for this game (see
     * AddonGameMapService::availablePlatforms()), otherwise the game's default
     * platform. Never trusts a client `platform` without checking it against
     * the server config — a game like Hytale/Palworld only has CurseForge,
     * even if the client sends "modrinth".
     */
    private function resolvePlatform(string $game, $requestedPlatform): string
    {
        $requested = is_string($requestedPlatform) ? strtolower($requestedPlatform) : null;
        $available = $this->gameMapService->availablePlatforms($game);

        if ($requested !== null && in_array($requested, $available, true)) {
            return $requested;
        }

        return $this->gameMapService->defaultPlatform($game);
    }

    /**
     * CurseForge class enforced for a browsing request (search, categories),
     * never the client's as is. Returns [allowed, classId]:
     * - `modpack` → the game's modpacks class (Minecraft only);
     * - `mod` → one of the game's "mods" classes (CurseForgeService::modClassIds()).
     *   The requested one if it's in the list; otherwise, if there's only one,
     *   that one; otherwise null = all of the game's classes, which only
     *   brings back mods because the list then covers all its classes
     *   (Palworld, checked).
     * Not allowed (game without a known class for this mode) → the caller
     * returns nothing, rather than plugins, worlds or resource packs.
     *
     * @return array{0: bool, 1: ?int}
     */
    private function resolveCurseForgeClassId(string $game, string $projectType, $requestedClassId): array
    {
        if ($projectType === 'modpack') {
            $modpackClassId = $this->curseForgeService->modpackClassId($game);

            return [$modpackClassId !== null, $modpackClassId];
        }

        $modClassIds = $this->curseForgeService->modClassIds($game);
        if ($modClassIds === []) {
            return [false, null];
        }

        $requested = is_numeric($requestedClassId) ? (int) $requestedClassId : null;
        if ($requested !== null && in_array($requested, $modClassIds, true)) {
            return [true, $requested];
        }

        return [true, count($modClassIds) === 1 ? $modClassIds[0] : null];
    }

    /**
     * GET /game-versions — versions offered in the "Game Version" filter for a
     * game, from newest to oldest (empty list: no version filter for this
     * game). Touches no server.
     */
    public function gameVersions(Request $request)
    {
        $request->validate(['game' => 'required|string|max:64']);

        return response()->json(['data' => $this->gameVersionService->listVersions(strtolower($request->input('game')))]);
    }

    /**
     * GET /server-environment — game version and loader installed on a server,
     * to pre-select the "Game Version" and "Loader" filters (never to force
     * them). Each null if it couldn't be determined; never an error for that,
     * even with the node unreachable.
     * Same authorization as resolve-game: reads files of the server.
     */
    public function serverEnvironment(Request $request)
    {
        $request->validate(['serverUuid' => 'required|string|exists:servers,uuid']);

        $server = Server::where('uuid', $request->input('serverUuid'))->with('egg.nest')->firstOrFail();
        $this->authorize('file.create', $server);
        // Server state: see download().
        $server->validateCurrentState();

        $game = $this->resolveGameForServer($server);
        if ($game === null) {
            return response()->json(['version' => null, 'version_source' => null, 'loader' => null, 'loader_source' => null]);
        }

        return response()->json($this->gameVersionService->detectServerEnvironment($server, $game));
    }

    /**
     * GET /installed — mods already on the server (installed-mods detection),
     * for the tab's "Installed" badge. Steps 1 (normalized name) and 2
     * (verification cache) of the cascade, then starting the hash-based
     * verification, see InstalledModsService. Same authorization as
     * resolve-game (`file.create`, 403 otherwise), plus `file.read` to get an
     * answer; the game comes from the server's nest, the folder from
     * gameConfig.json, never from the client.
     * - without `file.read`, unsupported game or without `installed_detection`
     *   → `{"enabled": false}`, without reading anything on the server;
     * - target folder missing → `{"enabled": true, "compatible": false}`;
     * - node unreachable, or unexpected answer from Wings → 503, no retry
     *   (the tab stays usable, without badges).
     */
    public function installed(Request $request)
    {
        $request->validate(['serverUuid' => 'required|string|exists:servers,uuid']);

        $server = Server::where('uuid', $request->input('serverUuid'))->with('egg.nest')->firstOrFail();
        $this->authorize('file.create', $server);
        // Server state: see download().
        $server->validateCurrentState();

        // The response gives the exact names of the files in `mods`: without
        // `file.read` (listing files), a subuser who only has `file.create`
        // must get nothing more than what the panel shows them. Same policy as
        // the panel (ServerPolicy); the tab stays usable, without badges, and
        // nothing is read on the server.
        if (! $request->user()?->can('file.read', $server)) {
            return response()->json(['enabled' => false]);
        }

        $detection = app(InstalledModsService::class);
        $game = $this->resolveGameForServer($server);
        if ($game === null || ! $detection->isEnabled($game)) {
            return response()->json(['enabled' => false]);
        }

        try {
            return response()->json($detection->detect($server, $game));
        } catch (DaemonConnectionException $ex) {
            AddonBrowserLogger::warning('Installed mods: daemon unreachable', ['server' => $server->uuid, 'error' => $ex->getMessage()]);
        } catch (\Throwable $ex) {
            // Unexpected answer from Wings (e.g. a 200 that isn't JSON:
            // getDirectory(), typed `array`, then throws a TypeError) or another
            // error: same response as an unreachable node, detail in the logs.
            AddonBrowserLogger::error('Installed mods: unexpected error', ['server' => $server->uuid, 'exception' => $ex]);
        }

        return response()->json(['success' => false, 'message' => 'Service unavailable, please try again later.'], 503);
    }

    public function files(Request $request)
    {
        $request->validate([
            // Modrinth ids are alphanumeric (e.g. "AANobbMI"), CurseForge ones
            // are numeric: modId can't be validated as an integer for both
            // platforms, hence the explicit platform parameter below.
            'modId' => 'required|string|max:64',
            'platform' => 'required|string|in:modrinth,curseforge',
            // Versions of this mod for this game version only, filtered by the
            // API itself.
            'gameVersion' => self::GAME_VERSION_RULE,
        ]);

        $modId = (string) $request->input('modId');
        $platform = $request->input('platform');
        $gameVersion = $request->input('gameVersion') ?: null;

        try {
            if ($platform === 'modrinth') {
                return response()->json($this->modrinthService->getProjectVersions($modId, $gameVersion));
            }

            return response()->json($this->curseForgeService->getModFiles((int) $modId, $gameVersion));
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('Addon files fetch error: ' . $ex->getMessage());
            return response()->json(['data' => []]);
        }
    }

}
