<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services;

use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\AddonBrowserLogger;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\ExternalApi;

class ModrinthService
{
    private string $apiBaseUrl = 'https://api.modrinth.com/v2';

    /**
     * Timeouts of the grouped calls made by the installed-mods referential
     * jobs (getVersionsByHashes(), getProjectsByIds()): short, a job has to
     * stay brief.
     */
    private const BATCH_TIMEOUT = 10;
    private const BATCH_CONNECT_TIMEOUT = 5;

    /** Most items per grouped call (the jobs send much smaller batches). */
    private const BATCH_MAX = 500;

    private const SHA1 = '/^[0-9a-f]{40}$/';

    /** Modrinth project id (base 62). */
    private const PROJECT_ID = '/^[A-Za-z0-9]{1,64}$/';

    /**
     * Search for addons on Modrinth (Minecraft only).
     */
    public function search(
        ?string $query = null,
        ?string $projectType = null,
        ?int $offset = 0,
        ?int $limit = 12,
        ?string $sortField = 'relevance',
        ?string $loader = null,
        ?string $category = null,
        ?string $gameVersion = null
    ): array {
        $facets = [];

        // Never `project_type:plugin`: Minecraft plugins aren't supported.
        // `project_type:mod` already excludes pure plugins (checked: EssentialsX
        // doesn't show up in a search for "essentials").
        $facets[] = ['project_type:' . ($projectType === 'modpack' ? 'modpack' : 'mod')];

        // Each sub-array of $facets is an AND condition; a loader (e.g. "neoforge")
        // translates on Modrinth into a separate "categories:<loader>" facet.
        if ($loader) {
            $facets[] = ["categories:{$loader}"];
        }

        // Category: same `categories:` facet as the loader; the caller only
        // passes an identifier from getCategories().
        if ($category) {
            $facets[] = ["categories:{$category}"];
        }

        // Game version, format already validated by the controller.
        if ($gameVersion) {
            $facets[] = ["versions:{$gameVersion}"];
        }

        $params = [
            'facets' => json_encode($facets),
            'limit' => $limit,
            'offset' => $offset,
            'index' => $sortField,
        ];

        if ($query) {
            $params['query'] = $query;
        }

        try {
            $response = ExternalApi::interactive(ExternalApi::modrinthHeaders())->get("{$this->apiBaseUrl}/search", $params);

            if ($response->successful()) {
                $json = $response->json();
                AddonBrowserLogger::debug('Modrinth API response', ['response' => $json]);
                return $json;
            }

            AddonBrowserLogger::warning('Modrinth API failed', ['status' => $response->status()]);
            return ['hits' => [], 'total_hits' => 0];
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('Modrinth API error: ' . $ex->getMessage());
            return ['hits' => [], 'total_hits' => 0];
        }
    }

    /**
     * Get categories for Modrinth (not as structured as CurseForge).
     *
     * The "modpack" taxonomy is separate from the mods one — checked with a
     * real call to GET /v2/tag/category: 10 categories for
     * project_type=modpack, against 19 for project_type=mod. Hardcoded here
     * (same choice as for mods just below, Modrinth having no per-type
     * categories endpoint) rather than one more network call on every load.
     */
    public function getCategories(?string $projectType = null): array
    {
        if ($projectType === 'modpack') {
            return [
                'data' => [
                    ['id' => 'adventure', 'name' => 'Adventure'],
                    ['id' => 'challenging', 'name' => 'Challenging'],
                    ['id' => 'combat', 'name' => 'Combat'],
                    ['id' => 'kitchen-sink', 'name' => 'Kitchen Sink'],
                    ['id' => 'lightweight', 'name' => 'Lightweight'],
                    ['id' => 'magic', 'name' => 'Magic'],
                    ['id' => 'multiplayer', 'name' => 'Multiplayer'],
                    ['id' => 'optimization', 'name' => 'Optimization'],
                    ['id' => 'quests', 'name' => 'Quests'],
                    ['id' => 'technology', 'name' => 'Technology'],
                ],
            ];
        }

        // Modrinth's 19 mod categories ("categories" header of
        // GET /v2/tag/category, checked). None is specific to plugins:
        // economy, management, minigame, social are mod categories too.
        return [
            'data' => [
                ['id' => 'adventure', 'name' => 'Adventure'],
                ['id' => 'cursed', 'name' => 'Cursed'],
                ['id' => 'decoration', 'name' => 'Decoration'],
                ['id' => 'economy', 'name' => 'Economy'],
                ['id' => 'equipment', 'name' => 'Equipment'],
                ['id' => 'food', 'name' => 'Food'],
                ['id' => 'game-mechanics', 'name' => 'Game Mechanics'],
                ['id' => 'library', 'name' => 'Library'],
                ['id' => 'magic', 'name' => 'Magic'],
                ['id' => 'management', 'name' => 'Management'],
                ['id' => 'minigame', 'name' => 'Minigame'],
                ['id' => 'mobs', 'name' => 'Mobs'],
                ['id' => 'optimization', 'name' => 'Optimization'],
                ['id' => 'social', 'name' => 'Social'],
                ['id' => 'storage', 'name' => 'Storage'],
                ['id' => 'technology', 'name' => 'Technology'],
                ['id' => 'transportation', 'name' => 'Transportation'],
                ['id' => 'utility', 'name' => 'Utility'],
                ['id' => 'worldgen', 'name' => 'World Generation'],
            ]
        ];
    }

    /**
     * A project's versions (files). With $gameVersion, only those for that
     * game version (the API's `game_versions` filter): much lighter than the
     * full list (1,204 versions for Fabric API).
     */
    public function getProjectVersions(string $projectId, ?string $gameVersion = null): array
    {
        try {
            $params = $gameVersion ? ['game_versions' => json_encode([$gameVersion])] : [];
            $response = ExternalApi::interactive(ExternalApi::modrinthHeaders())->get("{$this->apiBaseUrl}/project/" . rawurlencode($projectId) . '/version', $params);

            if ($response->successful()) {
                return $response->json();
            }

            return [];
        } catch (\Exception $ex) {
            AddonBrowserLogger::error('Modrinth API error: ' . $ex->getMessage());
            return [];
        }
    }

    /**
     * Checks, before any modpack install, that $projectId really is a Modrinth
     * modpack and that $versionNumber is one of its versions. Returns the
     * version, or null if the project doesn't exist, isn't a modpack, or
     * doesn't have that version.
     *
     * Unlike this service's other methods, an unreachable or failing API
     * (other than 404) is NOT swallowed: it throws (ConnectionException /
     * RequestException), so the caller refuses the install instead of letting
     * it go through unchecked.
     */
    public function findModpackVersion(string $projectId, string $versionNumber): ?array
    {
        $project = ExternalApi::interactive(ExternalApi::modrinthHeaders())->get("{$this->apiBaseUrl}/project/" . rawurlencode($projectId));
        if ($project->status() === 404) {
            return null;
        }
        $project->throw();

        if (($project->json('project_type') ?? null) !== 'modpack') {
            return null;
        }

        // "Version by number or by id" endpoint: also check that the returned
        // version really belongs to this project.
        $version = ExternalApi::interactive(ExternalApi::modrinthHeaders())->get("{$this->apiBaseUrl}/project/" . rawurlencode($projectId) . '/version/' . rawurlencode($versionNumber));
        if ($version->status() === 404) {
            return null;
        }
        $version->throw();

        return ($version->json('project_id') ?? null) === $project->json('id')
            ? $version->json()
            : null;
    }

    /**
     * Modrinth loaders offered in the filter, for a project type.
     *
     * Modrinth declares its plugin loaders (bukkit, paper, spigot, purpur,
     * folia, velocity, waterfall, bungeecord, sponge, geyser) as also
     * compatible with the `mod` type (checked on /v2/tag/loader): any loader
     * that supports `plugin` is therefore excluded, otherwise the mods'
     * "Loader" filter would offer Paper or Spigot. Also excluded: `datapack`
     * (a datapack doesn't go into `/mods`) and `java-agent` (not a mod loader).
     */
    public function getLoaders(string $projectType = 'mod'): array
    {
        return \Cache::remember("modrinth_loaders_modsonly_{$projectType}", now()->addHours(12), function () use ($projectType) {
            $response = ExternalApi::interactive(ExternalApi::modrinthHeaders())->get('https://api.modrinth.com/v2/tag/loader');

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json())
                ->filter(fn ($loader) => in_array($projectType, $loader['supported_project_types'] ?? [], true)
                    && ! in_array('plugin', $loader['supported_project_types'] ?? [], true)
                    && ! in_array($loader['name'] ?? '', ['datapack', 'java-agent'], true))
                ->pluck('name')
                ->values()
                ->all();
        });
    }

    /**
     * Modrinth versions by SHA1, in one grouped call (`POST /v2/version_files`,
     * body `{"hashes": [...], "algorithm": "sha1"}`), for the installed-mods
     * referential jobs. The response is keyed by hash (an unknown hash is
     * absent); each value is a version (`id`, `project_id`, `files[]`). A
     * version can have several files: find the right one with fileBySha1().
     *
     * Only returns the requested hashes, lowercase, whose version has the
     * expected shape. Unreachable API, error or unexpected response → empty
     * array and one log line, never an exception. Only valid SHA1s are sent.
     *
     * `$succeeded` (optional) is true only if the API answered normally: an
     * empty array then means "no known hash", not "API unreachable" (a
     * negative result is only cached in the first case).
     *
     * @param string[] $sha1s
     * @return array<string, array>
     */
    public function getVersionsByHashes(array $sha1s, ?bool &$succeeded = null): array
    {
        $succeeded = false;
        $sha1s = array_values(array_unique(array_filter(
            array_map(fn ($sha1) => is_string($sha1) ? strtolower($sha1) : '', $sha1s),
            fn (string $sha1) => preg_match(self::SHA1, $sha1) === 1
        )));
        if ($sha1s === []) {
            $succeeded = true;

            return [];
        }
        if (count($sha1s) > self::BATCH_MAX) {
            AddonBrowserLogger::warning('Modrinth version_files: too many hashes requested, batch truncated.', ['count' => count($sha1s)]);
            $sha1s = array_slice($sha1s, 0, self::BATCH_MAX);
        }

        $json = $this->batchRequest('version_files', fn () => ExternalApi::batch(self::BATCH_TIMEOUT, self::BATCH_CONNECT_TIMEOUT, ExternalApi::modrinthHeaders())
            ->post("{$this->apiBaseUrl}/version_files", ['hashes' => $sha1s, 'algorithm' => 'sha1']));
        // Object keyed by hash; an empty response arrives as [] (empty list).
        if ($json === null || ($json !== [] && array_is_list($json))) {
            if ($json !== null) {
                AddonBrowserLogger::warning('Modrinth version_files: unexpected response.');
            }

            return [];
        }

        $succeeded = true;
        $versions = [];
        foreach ($json as $hash => $version) {
            $hash = strtolower((string) $hash);
            if (in_array($hash, $sha1s, true)
                && is_array($version)
                && is_string($version['id'] ?? null) && preg_match(self::PROJECT_ID, $version['id']) === 1
                && is_string($version['project_id'] ?? null) && preg_match(self::PROJECT_ID, $version['project_id']) === 1
                && is_array($version['files'] ?? null)) {
                $versions[$hash] = $version;
            }
        }

        return $versions;
    }

    /**
     * Modrinth projects by id, in one grouped call
     * (`GET /v2/projects?ids=["…","…"]`): title (`title`), type
     * (`project_type`)… Used to get a project's name to create a mod in the
     * referential (version_files doesn't give it) and to check that it's of
     * type `mod` before linking it. Keyed by id; same failure rules as
     * getVersionsByHashes().
     *
     * @param string[] $projectIds
     * @return array<string, array>
     */
    public function getProjectsByIds(array $projectIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $projectIds,
            fn ($id) => is_string($id) && preg_match(self::PROJECT_ID, $id) === 1
        )));
        if ($ids === []) {
            return [];
        }
        $ids = array_slice($ids, 0, self::BATCH_MAX);

        $json = $this->batchRequest('projects', fn () => ExternalApi::batch(self::BATCH_TIMEOUT, self::BATCH_CONNECT_TIMEOUT, ExternalApi::modrinthHeaders())
            ->get("{$this->apiBaseUrl}/projects", ['ids' => json_encode($ids)]));
        if ($json === null || ! array_is_list($json)) {
            if ($json !== null) {
                AddonBrowserLogger::warning('Modrinth projects: unexpected response.');
            }

            return [];
        }

        $projects = [];
        foreach ($json as $project) {
            $id = is_array($project) && is_string($project['id'] ?? null) ? $project['id'] : null;
            if ($id !== null && in_array($id, $ids, true)) {
                $projects[$id] = $project;
            }
        }

        return $projects;
    }

    /**
     * File of a Modrinth version whose SHA1 (`files[].hashes.sha1`) is the
     * given one, or null. A version can contain several files (variants,
     * sources…): only the hash points to the right one.
     */
    public static function fileBySha1(array $version, string $sha1): ?array
    {
        $sha1 = strtolower($sha1);
        foreach (is_array($version['files'] ?? null) ? $version['files'] : [] as $file) {
            if (is_array($file) && is_string($file['hashes']['sha1'] ?? null) && strtolower($file['hashes']['sha1']) === $sha1) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Runs a grouped call and returns the decoded JSON (array), or null on
     * failure (logged). Never throws.
     */
    private function batchRequest(string $label, \Closure $request): ?array
    {
        try {
            $response = $request();
            if (! $response->successful()) {
                AddonBrowserLogger::warning("Modrinth {$label}: error response.", ['status' => $response->status()]);

                return null;
            }
            $json = $response->json();
            if (! is_array($json)) {
                AddonBrowserLogger::warning("Modrinth {$label}: unexpected response.");

                return null;
            }

            return $json;
        } catch (\Exception $ex) {
            AddonBrowserLogger::warning("Modrinth {$label}: API unreachable.", ['error' => $ex->getMessage()]);

            return null;
        }
    }
}
