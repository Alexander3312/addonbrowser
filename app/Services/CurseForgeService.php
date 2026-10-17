<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\ExternalApi;

class CurseForgeService
{
    /**
     * CurseForge's modLoaderType enum — Minecraft-specific, not usable for other games.
     * Without `cauldron` (2): Cauldron is a hybrid Forge + Bukkit server, tied
     * to plugins, which the addon doesn't handle.
     */
    private const MOD_LOADER_TYPES = [
        'forge' => 1,
        'liteloader' => 3,
        'fabric' => 4,
        'quilt' => 5,
        'neoforge' => 6,
    ];

    /**
     * CurseForge classes that contain **mods**, for a game
     * (`curseforge.mod_class_ids` in gameConfig.json): addonbrowser only
     * offers mods (and modpacks, below), never the other classes (plugins,
     * worlds, resource packs, prefabs, translations…). The class ids of a game
     * are listed by GET /v1/categories?gameId=… (`isClass: true`); for
     * reference:
     * - Minecraft (432): Mods = 6; the other classes (Bukkit Plugins 5,
     *   Worlds 17, Resource Packs 12, Customization 4546, Data Packs 6945,
     *   Addons 4559, Shaders 6552) are excluded, Modpacks 4471 separately;
     * - Hytale (70216): Mods = 9137; Prefabs, Worlds, Bootstrap and
     *   Translations excluded;
     * - Palworld (85196): its five classes are all mods (Patch Pak,
     *   Blueprint Code, Lua Code, C++ Code, Miscellaneous).
     * Always enforced server-side, never accepted as is from the client (see
     * AddonBrowserController::resolveCurseForgeClassId()). A game without
     * mod classes gets no mods at all.
     *
     * @return int[]
     */
    public function modClassIds(string $game): array
    {
        return $this->gameMapService->curseForgeModClassIds($game);
    }

    /**
     * CurseForge modpack class of a game (`curseforge.modpack_class_id` in
     * gameConfig.json; only Minecraft has one, being the only game with a
     * modpack egg, see ModpackEggs). A game without one has neither modpack
     * search nor a verified, hence installable, modpack.
     */
    public function modpackClassId(string $game): ?int
    {
        return $this->gameMapService->curseForgeModpackClassId($game);
    }

    private string $apiBaseUrl = 'https://api.curseforge.com/v1';
    private ?string $apiKey = null;

    /**
     * Timeouts of the grouped calls made by the referential jobs
     * (getFilesByIds(), getModsByIds(), getFingerprintMatches()): short, a job
     * has to stay brief.
     */
    private const BATCH_TIMEOUT = 10;
    private const BATCH_CONNECT_TIMEOUT = 5;

    /** Most ids per grouped call (the jobs send much smaller batches). */
    private const BATCH_MAX_IDS = 500;

    /** SHA1 algorithm in a CurseForge file's `hashes[].algo`. */
    private const HASH_ALGO_SHA1 = 1;

    private AddonGameMapService $gameMapService;

    /**
     * This service is injected into the controller's constructor: it must only
     * depend on things that can always be resolved. AddonGameMapService has no
     * dependency; it stays optional so that a plain `new CurseForgeService()`
     * keeps working.
     */
    public function __construct(?AddonGameMapService $gameMapService = null)
    {
        $this->gameMapService = $gameMapService ?? new AddonGameMapService();
        $this->apiKey = $this->resolveApiKey();
    }

    /**
     * Reads the key through config('addonbrowser.curseforge_api_key') — this
     * addon's OWN config file (config/addonbrowser.php, deployed by
     * private/install.sh), never config('services.curseforge.api_key')
     * (config/services.php, a CORE panel file with no Blueprint mechanism to
     * survive an update, which silently overwrites it). Staying on the addon's
     * own config file makes this setting last without any core patch. As for
     * ADDONBROWSER_LOG_LEVEL, after changing CURSEFORGE_API_KEY in the .env,
     * run `php artisan config:cache` again (or `config:clear`) for it to take
     * effect.
     */
    private function resolveApiKey(): string
    {
        $apiKey = config('addonbrowser.curseforge_api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new \RuntimeException('CurseForge API key is not configured (CURSEFORGE_API_KEY in .env).');
        }

        return trim($apiKey);
    }

    /**
     * Checks, before any modpack install, that $projectId really is a
     * CurseForge modpack of game $game and that $fileId is one of its files.
     * Returns the file, or null otherwise.
     *
     * Unlike this service's other methods, an unreachable or failing API
     * (other than 404) is NOT swallowed: it throws (ConnectionException /
     * RequestException), so the caller refuses the install instead of letting
     * it go through unchecked.
     */
    public function findModpackFile(string $game, int $projectId, int $fileId): ?array
    {
        $gameId = $this->getGameId($game);
        $modpackClassId = $this->modpackClassId($game);
        if ($gameId === null || $modpackClassId === null) {
            return null;
        }

        $headers = ['x-api-key' => $this->apiKey, 'Accept' => 'application/json'];

        $mod = ExternalApi::interactive($headers)->get("{$this->apiBaseUrl}/mods/{$projectId}");
        if ($mod->status() === 404) {
            return null;
        }
        $mod->throw();

        if ((int) $mod->json('data.gameId') !== $gameId || (int) $mod->json('data.classId') !== $modpackClassId) {
            return null;
        }

        $file = ExternalApi::interactive($headers)->get("{$this->apiBaseUrl}/mods/{$projectId}/files/{$fileId}");
        if ($file->status() === 404) {
            return null;
        }
        $file->throw();

        return (int) $file->json('data.modId') === $projectId ? $file->json('data') : null;
    }

    /**
     * Get the CurseForge gameId of a game (`curseforge.game_id` in
     * gameConfig.json), or null if it has none.
     */
    public function getGameId(string $game): ?int
    {
        return $this->gameMapService->curseForgeGameId($game);
    }

    /**
     * Return CurseForge's built-in modLoaderType enum.
     * Only meaningful for Minecraft — CurseForge has no generic loader concept for other games.
     */
    public function getModLoaderTypes(): array
    {
        return array_keys(self::MOD_LOADER_TYPES);
    }

    /**
     * Search for addons on CurseForge.
     *
     * $loader: loader name (e.g. "forge", "fabric") converted here into the
     * numeric modLoaderType through MOD_LOADER_TYPES. Only sent if it's one of
     * the loaders declared for the game in gameConfig.json (`loaders`,
     * AddonGameMapService::loaders()) and in MOD_LOADER_TYPES; otherwise
     * silently ignored (no parameter sent). A game without declared loaders
     * therefore never sends one.
     */
    public function search(
        string $game,
        ?string $query = null,
        ?int $classId = null,
        ?int $categoryId = null,
        int $offset = 0,
        int $limit = 12,
        int $sortField = 2,
        ?string $loader = null,
        ?string $gameVersion = null
    ): array {
        $gameId = $this->getGameId($game);

        if (!$gameId) {
            return ['data' => [], 'pagination' => ['index' => $offset, 'pageSize' => $limit, 'totalCount' => 0]];
        }

        $params = [
            'gameId' => $gameId,
            'index' => $offset,
            'pageSize' => $limit,
            'sortField' => $sortField,
            'sortOrder' => 'desc',
        ];

        if ($query) {
            $params['searchFilter'] = $query;
        }

        if ($classId !== null) {
            $params['classId'] = $classId;
        }

        if ($categoryId !== null) {
            $params['categoryId'] = $categoryId;
        }

        // Game version, format already validated by the controller.
        if ($gameVersion !== null) {
            $params['gameVersion'] = $gameVersion;
        }

        if ($loader !== null
            && in_array(strtolower($loader), $this->gameMapService->loaders($game), true)
            && array_key_exists(strtolower($loader), self::MOD_LOADER_TYPES)) {
            $params['modLoaderType'] = self::MOD_LOADER_TYPES[strtolower($loader)];
        }

        try {
            $response = ExternalApi::interactive([
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get("{$this->apiBaseUrl}/mods/search", $params);

            if ($response->successful()) {
                return $response->json();
            }

            return ['data' => [], 'pagination' => ['index' => $offset, 'pageSize' => $limit, 'totalCount' => 0]];
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('CurseForge API error: ' . $ex->getMessage());
            return ['data' => [], 'pagination' => ['index' => $offset, 'pageSize' => $limit, 'totalCount' => 0]];
        }
    }

    /**
     * Get the categories (classes and subcategories) for a game.
     */
    public function getCategories(string $game, ?int $classId = null): array
    {
        $gameId = $this->getGameId($game);

        if (!$gameId) {
            return ['data' => []];
        }

        $params = ['gameId' => $gameId];

        if ($classId !== null) {
            $params['classId'] = $classId;
        }

        try {
            $response = ExternalApi::interactive([
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get("{$this->apiBaseUrl}/categories", $params);

            if ($response->successful()) {
                return $response->json();
            }

            return ['data' => []];
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('CurseForge API error: ' . $ex->getMessage());
            return ['data' => []];
        }
    }

    /**
     * A mod's files. The API returns them in pages of 50 at most: without a
     * game version, only the first page (the 50 most recent); with
     * $gameVersion, every page for that version, up to 10 pages (500 files),
     * so older versions aren't lost (the first page alone only gave 50 files
     * out of 237 for Sodium). `pagination.totalCount` stays the API's;
     * `truncated` says some are missing.
     */
    public function getModFiles(int $modId, ?string $gameVersion = null): array
    {
        $params = ['pageSize' => 50];
        if ($gameVersion) {
            $params['gameVersion'] = $gameVersion;
        }
        $maxPages = $gameVersion ? 10 : 1;

        $files = [];
        $total = 0;

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $response = ExternalApi::interactive([
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                ])->get("{$this->apiBaseUrl}/mods/{$modId}/files", $params + ['index' => $page * 50]);

                if (! $response->successful()) {
                    break;
                }

                $batch = $response->json('data') ?? [];
                $total = (int) ($response->json('pagination.totalCount') ?? count($batch));
                $files = array_merge($files, $batch);

                if ($batch === [] || count($files) >= $total) {
                    break;
                }
            }
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('CurseForge API error: ' . $ex->getMessage());
        }

        return [
            'data' => $files,
            'pagination' => ['totalCount' => $total, 'truncated' => count($files) < $total],
        ];
    }

    /**
     * CurseForge files by id, in one grouped call (`POST /v1/mods/files`,
     * body `{"fileIds": [...]}`), for the installed-mods referential jobs.
     * Returns the official file objects (`id`, `modId`, `gameId`, `fileName`,
     * `fileLength`, `fileFingerprint`, `releaseType`, `hashes`…) keyed by id;
     * an unknown id is simply absent.
     *
     * Like the other read methods: unreachable API, error or unexpected
     * response → empty array and one log line, never an exception. Only
     * positive integer ids are sent (nothing else in the body).
     *
     * @param int[] $fileIds
     * @return array<int, array>
     */
    public function getFilesByIds(array $fileIds): array
    {
        return $this->postBatch('/mods/files', 'fileIds', $fileIds);
    }

    /**
     * CurseForge mods by id, in one grouped call (`POST /v1/mods`, body
     * `{"modIds": [...]}`): name, `gameId`, `classId`… Used to get a mod's
     * name to create it in the referential (the per-file responses don't give
     * it). Keyed by id; same failure rules as getFilesByIds().
     *
     * @param int[] $modIds
     * @return array<int, array>
     */
    public function getModsByIds(array $modIds): array
    {
        return $this->postBatch('/mods', 'modIds', $modIds);
    }

    /**
     * Exact fingerprint matches (step 5 of the hash-based verification), in
     * one grouped call (`POST /v1/fingerprints/{gameId}`, body
     * `{"fingerprints": [...]}`). Returns, keyed by fingerprint, the
     * `data.exactMatches[]` entries whose file (`file`) has the expected shape
     * and a requested `fileFingerprint`: `['mod_id' => mod id, 'file' =>
     * official file object]`. A fingerprint can match several files
     * (re-uploads): one list per fingerprint. A fingerprint without a match is
     * absent.
     *
     * Short timeouts; unreachable API, error, unexpected response or
     * `isCacheBuilt` other than true → empty array, one log line, never an
     * exception. `$succeeded` is true only if the API answered normally (an
     * empty array then means "no match", a result that can be cached). Only
     * unsigned 32-bit integers are sent.
     *
     * @param int[] $fingerprints
     * @return array<int, array<int, array{mod_id: int, file: array}>>
     */
    public function getFingerprintMatches(int $gameId, array $fingerprints, ?bool &$succeeded = null): array
    {
        $succeeded = false;
        $fingerprints = array_values(array_unique(array_filter(
            $fingerprints,
            fn ($fp) => is_int($fp) && $fp >= 0 && $fp <= 4294967295
        )));
        if ($gameId <= 0) {
            return [];
        }
        if ($fingerprints === []) {
            $succeeded = true;

            return [];
        }
        $fingerprints = array_slice($fingerprints, 0, self::BATCH_MAX_IDS);

        try {
            $response = ExternalApi::batch(self::BATCH_TIMEOUT, self::BATCH_CONNECT_TIMEOUT, [
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])
                ->post("{$this->apiBaseUrl}/fingerprints/{$gameId}", ['fingerprints' => $fingerprints]);

            if (! $response->successful()) {
                AddonBrowserLogger::warning('CurseForge /fingerprints: error response.', ['status' => $response->status()]);

                return [];
            }

            $matches = $response->json('data.exactMatches');
            if (! is_array($response->json('data')) || ! is_array($matches) || ! array_is_list($matches)) {
                AddonBrowserLogger::warning('CurseForge /fingerprints: unexpected response.');

                return [];
            }
            // CurseForge's index isn't ready: "no match" would prove nothing,
            // treated as a failure (nothing cached).
            if ($response->json('data.isCacheBuilt') !== true) {
                AddonBrowserLogger::warning('CurseForge /fingerprints: index not built (isCacheBuilt), response ignored.');

                return [];
            }
        } catch (\Exception $ex) {
            AddonBrowserLogger::warning('CurseForge /fingerprints: API unreachable.', ['error' => $ex->getMessage()]);

            return [];
        }

        $succeeded = true;
        $byFingerprint = [];
        foreach ($matches as $match) {
            $file = is_array($match) && is_array($match['file'] ?? null) ? $match['file'] : null;
            $modId = $match['id'] ?? null;
            $fp = $file['fileFingerprint'] ?? null;
            if ($file === null || ! is_int($modId) || $modId <= 0
                || ! is_int($fp) || ! in_array($fp, $fingerprints, true)
                || ! is_int($file['id'] ?? null) || ($file['modId'] ?? null) !== $modId) {
                continue;
            }
            $byFingerprint[$fp][] = ['mod_id' => $modId, 'file' => $file];
        }

        return $byFingerprint;
    }

    /**
     * Official SHA1 of a CurseForge file object (`hashes[]`, algo 1),
     * lowercase, or null if missing or malformed.
     */
    public static function sha1Of(array $file): ?string
    {
        foreach (is_array($file['hashes'] ?? null) ? $file['hashes'] : [] as $hash) {
            if (is_array($hash) && (int) ($hash['algo'] ?? 0) === self::HASH_ALGO_SHA1 && is_string($hash['value'] ?? null)) {
                $sha1 = strtolower($hash['value']);

                return preg_match('/^[0-9a-f]{40}$/', $sha1) === 1 ? $sha1 : null;
            }
        }

        return null;
    }

    /**
     * Shared grouped call: deduplicated positive integer ids, short timeouts,
     * `data[]` response keyed by `id` (only the objects whose id was
     * requested).
     *
     * @param int[] $ids
     * @return array<int, array>
     */
    private function postBatch(string $path, string $field, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0, $ids),
            fn (int $id) => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        if (count($ids) > self::BATCH_MAX_IDS) {
            AddonBrowserLogger::warning("CurseForge {$path}: too many ids requested, batch truncated.", ['count' => count($ids)]);
            $ids = array_slice($ids, 0, self::BATCH_MAX_IDS);
        }

        try {
            $response = ExternalApi::batch(self::BATCH_TIMEOUT, self::BATCH_CONNECT_TIMEOUT, [
                'x-api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])
                ->post("{$this->apiBaseUrl}{$path}", [$field => $ids]);

            if (! $response->successful()) {
                AddonBrowserLogger::warning("CurseForge {$path}: error response.", ['status' => $response->status()]);

                return [];
            }

            $data = $response->json('data');
            if (! is_array($data) || ! array_is_list($data)) {
                AddonBrowserLogger::warning("CurseForge {$path}: unexpected response.");

                return [];
            }

            $byId = [];
            foreach ($data as $item) {
                $id = is_array($item) && is_int($item['id'] ?? null) ? $item['id'] : null;
                if ($id !== null && in_array($id, $ids, true)) {
                    $byId[$id] = $item;
                }
            }

            return $byId;
        } catch (\Exception $ex) {
            AddonBrowserLogger::warning("CurseForge {$path}: API unreachable.", ['error' => $ex->getMessage()]);

            return [];
        }
    }
}
