# Addon Browser — technical reference

This is the detailed "how it currently works" reference. For the pitch, see [`README.md`](README.md); for *why* things are built this way, see [`architecture-addonbrowser-addon.md`](architecture-addonbrowser-addon.md); for known gaps, see [`TODO.md`](TODO.md).

## Developing & testing

There's no PHPUnit suite. Three ways to verify a change:

1. **Backend, on a dedicated development panel** — never on a production one: the addon writes to the database, to Redis and to servers' files. Install the addon there (`blueprint -i addonbrowser`), then exercise the endpoints from the UI or from `php artisan tinker`. To deploy a change, package the folder into a `.blueprint` (a plain zip of the repository, without `.git/`), then `blueprint -remove addonbrowser` and `blueprint -i addonbrowser.blueprint`: the removal runs `private/remove.sh`, the install runs `private/install.sh` (which deploys `config/addonbrowser.php`), and Blueprint rebuilds the assets and caches itself.
2. **Frontend.** Type-check with the panel's own `tsconfig.json` against a copy of `resources/scripts/components/server/addon` (the panel's `node_modules` needs to be reachable, e.g. via a symlink). The only pre-existing error to tolerate is `TS7016` on `lodash-es/debounce`.
3. **In the real world**, after deploying. Use a disposable test server for anything that writes files or triggers a modpack reinstall — never a server you actually care about.

## What's in the codebase

| What | File(s) |
|---|---|
| Game ↔ nest resolution, per-game config (platforms, folders, extensions) | `app/Services/AddonGameMapService.php` + `resources/scripts/components/server/addon/gameConfig.json` |
| Modrinth client | `app/Services/ModrinthService.php` |
| CurseForge client | `app/Services/CurseForgeService.php` |
| HTTP controller | `app/AddonBrowserController.php` |
| Addon logs (adjustable verbosity) | `app/Support/AddonBrowserLogger.php` |
| Addon-owned config (log level, CurseForge key, Modrinth token) | `private/config/addonbrowser.php` → deployed to `config/addonbrowser.php` on every install |
| External API calls (timeouts, retries, User-Agent, Modrinth token) | `app/Support/ExternalApi.php` |
| Routes | `routes/web.php` |
| Client UI (search, filters, versions, download, modpack install) | `resources/scripts/components/server/addon/AddonBrowserContainer.tsx` + `gameConfig.ts` |
| Game-version / loader detection from what's actually installed | `app/Services/GameVersionService.php` |
| (game, platform) → companion `eggswitch` egg for modpacks | `app/Support/ModpackEggs.php` |
| Translating a chosen modpack into an `eggswitch` call | `app/Services/ModpackInstallService.php` |
| Installed-mods referential: tables, models | `database/migrations/` + `app/Models/Mod.php`, `app/Models/ModFile.php` |
| Writing to the referential (linking, merging, conflicts) | `app/Services/ModReferenceService.php` + `ModReferenceResult.php` |
| Mod filename normalization | `app/Services/FilenameNormalizer.php` |
| Text journal of collisions, merges and conflicts | `app/Support/CollisionJournal.php` → `storage/logs/addonbrowser-collisions.log` |
| Feeding the referential in the background (after a download, after a CurseForge search) | `app/Jobs/RecordDownloadedModJob.php`, `app/Jobs/IndexCurseForgeSearchJob.php` |
| Feeding the referential after a modpack install (watch, capture, process the manifest) | `app/Jobs/WatchModpackInstallJob.php`, `app/Jobs/ProcessModpackManifestJob.php` (+ `ModpackInstallService::installState()`, `ModpackEggs::manifestsFor()`) |
| Installed-mods detection on a server (`GET /installed`, name/cache steps, launching a hash-verification batch) | `app/Services/InstalledModsService.php` |
| Per-server verification cache (Redis hash: keys, reads, cleanup, writes; set of files currently being verified) | `app/Support/VerificationCache.php` |
| Hash-based verification (job, bounded streaming read, CurseForge fingerprint) | `app/Jobs/VerifyInstalledModsJob.php`, `app/Support/WingsFileReader.php`, `app/Support/CurseForgeFingerprint.php` |
| Usage statistics (table, counters) | `app/Models/Stat.php`, `app/Support/AddonStats.php` |
| Read-only admin screen (collisions, journal, stats, current state) | `admin/controller.php` (declared in `conf.yml`, `admin.controller`) + `app/Services/AdminOverviewService.php` + `resources/views/view.blade.php` |

## HTTP API (`/extensions/addonbrowser`)

Auth: logged-in session (`auth`), plus `EnsureHasServerAccess` (must own or be a subuser on at least one server, or be an admin — closes off the search endpoints as an open relay to Modrinth/CurseForge for an account with no server at all). Each throttle has an explicit prefix (`throttle:N,M,<prefix>`) — without one, Laravel's rate limiter keys on the user alone, regardless of route, so a permissive route and a strict one would share the same counter.

| Route | Throttle | Auth | Role |
|---|---|---|---|
| `GET /search` | `60,1` | `auth` | Search Modrinth or CurseForge (Minecraft: either, via `platform`; other games: CurseForge only) |
| `GET /categories` | `60,1` | `auth` | Mod/modpack categories |
| `GET /loaders` | `60,1` | `auth` | Loaders for a game |
| `GET /files` | `60,1` | `auth` | Versions/files of a mod |
| `GET /resolve-game` | `60,1` | `file.create` on the target server | Resolves the game from the server's nest |
| `GET /game-versions` | `60,1` | `auth` | Game versions for the "Game Version" filter |
| `GET /server-environment` | `60,1` | `file.create` on the target server | Detected game version + loader on this server |
| `GET /modpack-install-available` | `60,1` | `auth` | `{available: bool}` for a (game, platform) |
| `GET /installed` | `30,1` | `file.create`, then `file.read` on the target server | Mods already present on this server (the "Installed" badge) |
| `POST /download` | `20,1` | `file.create` on the target server | Downloads a file from Modrinth/CurseForge and writes it to the server |
| `POST /install-modpack` | `5,60` | `file.create` on the target server | Installs a whole modpack via `eggswitch` (wipe + reinstall) |

Pure read endpoints (search/categories/loaders/files/game-versions/modpack-install-available) touch no server data, so they only need `auth`. Every server-scoped route re-checks the permission through Pterodactyl's own `ServerPolicy` on every call — never cached, never assumed from a previous call. `download`, `installed`, `server-environment` and `install-modpack` also call `$server->validateCurrentState()` right after `authorize()`: a suspended server, a node under maintenance, or a server mid-install, mid-restore or mid-transfer gets a 409, the same as the panel's own client API.

`POST /download` only takes `downloadUrl` and `serverUuid`. The target folder comes from the game's config, never from the client, and there's no `filename` parameter — the name is taken from the last path segment of `downloadUrl` (see "Download security" below).

## Game detection

`AddonGameMapService::resolveGameFromNestName()` maps the server's **nest name** (case-insensitive) to an entry in `gameConfig.json` via its `nest_names`. An unrecognized nest isn't handled at all: `resolve-game` returns `game: null, supported: false`, the tab shows a "game not recognized or not supported" message instead of the browser, and `download`/`install-modpack` reply with 422. There's no fallback game and no game read from client input anywhere.

One fragility worth knowing: `AddonGameMapService::config()` loads `gameConfig.json` from a hardcoded absolute path pointing at the *deployed* copy, not a path relative to the PHP file itself. Editing `gameConfig.json` in this repository has no effect until the addon is redeployed.

**CurseForge game and classes**, declared per game in `gameConfig.json` (`curseforge`: `game_id`, `mod_class_ids`, optional `modpack_class_id`) and forced server-side, never taken from the client (`resolveCurseForgeClassId()`): Minecraft Mods (6) / Modpacks (4471), Hytale Mods (9137), Palworld's five classes (all of them mods — it has no separate modpack class). A game's class ids are listed by CurseForge's `GET /v1/categories?gameId=…` (entries with `isClass: true`). Modrinth's loader filter also excludes every plugin-platform loader it happens to also tag as mod-compatible (`bukkit`, `paper`, `spigot`, `purpur`, `folia`, `velocity`, `waterfall`, `bungeecord`, `sponge`, `geyser`), plus `datapack` and `java-agent` — addonbrowser only ever deals in mods and modpacks, for every game.

**Game version and loader detection** (`GameVersionService::detectServerEnvironment()`, `GET /server-environment`) reads what's *actually installed*, never the egg's variables (often just `"latest"`): first a modpack manifest if one exists (`modpack.json`'s `dependencies`, or `manifest.json`/`client.manifest.json`'s `minecraft.version`/`minecraft.modLoaders`), then, failing that, version strings baked into installed library paths (e.g. `libraries/net/minecraftforge/forge/<version>-…`, `libraries/net/fabricmc/intermediary/<version>`) and the loader guessed from which loader-specific library folder exists at all. Result is cached per server (1 day if both were found, 10 minutes otherwise) and called separately from `resolve-game` so a slow node never blocks the rest of the tab.

## Download security

See [architecture §2](architecture-addonbrowser-addon.md#2-security-model) for the reasoning behind each layer. One behavior not covered there: on any error, the response only ever contains a clean, generic message — the actual exception detail goes to the logs, never to the client. An unreachable node or an unreachable download host both reply 503 ("Service unavailable, please try again later."); anything else replies with a generic 500. There's no automatic retry.

## Installed-mods referential

Two tables, written through a single service (`ModReferenceService`), never through raw queries elsewhere.

**`addonbrowser_mods`** — one row per mod, independent of platform: `game` (indexed), `name`, `modrinth_project_id` and `curseforge_project_id` (nullable, **unique across all games**, at least one set). On MySQL/MariaDB, the Modrinth id column uses `utf8mb4_bin` collation rather than the panel's default — two ids differing only by case must never be treated as equal.

**`addonbrowser_mod_files`** — one row per known *file* of a mod (not one row per mod): `game`, `mod_id` (foreign key, cascades on delete), `filename` (exact official name), `filename_key` (normalized name), `size`, `sha1` (char 40), `cf_fingerprint`, `modrinth_version_id`, `curseforge_file_id`, `source` (`download`, `modpack_index`, `hash_lookup`, `search_result`). Indexes: `(game, filename_key)`, unique `(game, sha1)`, `mod_id`. Same binary collation on `filename_key`.

**Filename normalization** (`FilenameNormalizer`): turns `sodium-fabric-0.5.8+mc1.20.1.jar` into the stable key `sodium`, plus a `.disabled` flag. Token categories (version patterns, loaders, qualifiers) are grouped per normalizer and selected per game via `installed_detection.normalizer` in `gameConfig.json` (`AddonGameMapService::normalizer()`) — a game with no normalizer declared simply can't write to the referential at all (`has()` lets code check this first), rather than falling back to generic rules that could produce a confidently-wrong key. The algorithm is aligned with a reference Python prototype on valid UTF-8 input (case-folding, Unicode-aware digit detection), with one known, inconsequential gap: 20 digit characters from very recent, obscure Unicode scripts aren't recognized by this PHP runtime's regex engine. Verified against the Python prototype on tens of thousands of real filenames with zero divergence, and against real `/mods` directories: no version ever survives into a key, and a Fabric/NeoForge pair of the same mod (e.g. `lithium-fabric-…` / `lithium-neoforge-…`) correctly produces the same key — that's the point, it's exactly the duplicate that needs catching.

**`ModReferenceService::recordFile($game, $project, $file, $source)`** returns one of `created`, `updated`, `merged`, `unchanged`, `conflict`, `rejected`. Only accepts official API data (name, size, hash) — the normalized key is computed here from the official name, never trusted from a caller. An id that's already set is never overwritten; a project's name is only required to *create* a mod (per-file API responses don't include it). Idempotent on `(game, sha1)`: a known file is never duplicated, only its missing details get filled in. Runs in a transaction with row locks, up to 3 attempts (MariaDB can deadlock two concurrent creations under `REPEATABLE-READ`), plus a second pass on a unique-constraint violation from concurrent writers. Logging and the collision journal never fail or cancel a write.

## Background jobs feeding the referential

Two jobs, queue `low`, `$tries = 1` (Pterodactyl's standard queue worker, `pteroq`, runs `--tries=3`; retrying a job here would only delay real server tasks for data that's just a convenience) — every exception is caught and logged, never rethrown.

**`RecordDownloadedModJob`**, dispatched by `download()` after a successful write, with only the game, a query-stripped URL, the SHA1 and the size — never the file's bytes. For Modrinth: a batched `version_files` lookup by SHA1. For CurseForge: the file id is read straight out of the download URL (`/files/<a>/<b>/<name>` → `a × 1000 + b`), then `mods/files`, requiring the official SHA1 and size to match the downloaded bytes exactly before anything is recorded. Either way, if the file turns out to also exist on the other platform with the same SHA1 and size, that platform's id gets attached too.

**`IndexCurseForgeSearchJob`**, dispatched after a CurseForge search in mod mode, for a game with detection enabled. Builds its payload only from *our own* API response (never trusts anything else), capped at 300 files, deduplicated. Dispatch is deduplicated per page for 10 minutes and rate limited to 10 dispatches per minute per user. Runs in short batches (100 files, ~20s, up to 5 runs), re-querying Modrinth in one batched call per run for any SHA1 still unlinked. Negative results (SHA1 unknown to Modrinth, a Modrinth project that isn't a mod, or a rejected write) are cached for a day, keyed by (game, SHA1, reason), so a replayed search costs no API calls for files already resolved one way or the other.

## `GET /installed` — detection without a hash

(`InstalledModsService`) Recomputed from the real directory listing on every call — never stored, so a deleted file disappears from the badge without anything to reconcile.

1. Resolve the game, require `file.create` then `file.read` on the target server (a subuser with only `file.create` gets `{"enabled": false}` without anything being read, since the response reveals exact filenames the panel wouldn't otherwise show them).
2. List the target folder. Only ordinary files (never directories or symlinks) with a detected extension are kept. The folder's absence is only ever reported as `compatible: false` if it's *proven* by listing the parent folder — Wings returns the same 500 for a missing folder as for several unrelated errors, so any other doubt returns a 503 instead of a false "incompatible".
3. **Name match**: one query (chunked at 500 distinct keys) checks every file's normalized key against the referential. A key matching exactly one mod's known files → installed. A key matching several mods' files → no conclusion, logged as a collision (deduplicated to once a day per server and key) — never a guess.
4. **Cache match**: a per-server Redis hash records the outcome of past hash-based verifications (see below), so a renamed-but-known file, or a file already confirmed unrelated to any known mod, doesn't need re-reading.
5. If neither concludes for a file, it may enter a hash-verification batch (next section).

## Hash-based verification

(`VerifyInstalledModsJob`, `WingsFileReader`, `CurseForgeFingerprint`) The fallback for files the name alone can't resolve — most notably real cross-platform naming collisions: *Fabric API* and *Forgified Fabric API* both normalize to `fabric-api`, and *Lithium* / *Lithium Energy* both normalize to `lithium`. A file's SHA1 settles it; the cache then remembers the outcome so the file is never re-read for it.

- Up to 30 unresolved files per batch, smallest first, capped at 50MB each, one batch at a time per server (an atomic Redis reservation prevents two concurrent requests from launching two batches).
- Each file is read once for its SHA1 (checked against the referential, then a single batched Modrinth lookup). Files still unresolved get a second streaming read for the CurseForge fingerprint (32-bit, real collisions, trivial to forge — it's only ever used as a search key: a fingerprint match only gets written to the shared referential if the official SHA1 and size also match; a fingerprint match with only a plausible name gets a badge for *this server only*, nothing shared).
- Reads are bounded streams (`WingsFileReader`), cut off past 50MB or past the time budget, with the exact byte count cross-checked against the directory listing — a file that changed mid-read is simply abandoned, not half-hashed.
- Runs stay short (~20s) and redispatch with their accumulated state, up to 10 minutes and 40 runs per batch, under a global byte-read budget shared across the whole panel (300MB/minute) and a per-server lock. A batch's `retryUntil` is always set an hour past its real deadline — Laravel fails a job picked up after `retryUntil` *before* even running it, landing it in `failed_jobs`; the real 10-minute limit is enforced inside the job itself.
- Abandoned files (unreachable node/API, an interrupted read) are excluded from new batches for 30 minutes, so a client polling every 10 seconds doesn't keep re-attempting the same doomed read.

**Cache format** (`VerificationCache`, a Redis hash per server): field key is `name|size|modified-time`, value is `{status, sha1, fingerprint?, curseforge_project_id?, checked_at}`. The cache key is prefixed with the addon's own installed version, read fresh on every call — a version bump invalidates everything at once rather than mixing two versions' worth of assumptions. Capped at 2,000 fields per server; past that, the whole key is dropped and rebuilt rather than let an unbounded number of fields accumulate.

## Installing a modpack

Minecraft only for now: it's the only game with a modpack egg setting (`modpack_eggs` in `private/config/addonbrowser.php`, from the `.env`) and a CurseForge modpack class (`curseforge.modpack_class_id` in `gameConfig.json`). For any other game, `modpack-install-available` answers `false` and the "Modpacks" tab stays hidden.

`POST /install-modpack` delegates the actual egg switch + reinstall to the companion `eggswitch` extension (`ModpackInstallService::install()` → `EggSwitchRequestService::requestReinstallForAddon()`), but not before `assertModpackVersionExists()` checks the chosen (`projectId`, `versionNumber`) pair — both its format and, with one API call, that it's really a modpack (of the right game, for CurseForge) and that the version actually belongs to it. This matters because the modpack egg's install script **wipes the server first**: an invalid pair reaching eggswitch would leave the server empty instead of just failing cleanly. A rejected pair replies 422; an unreachable platform API replies 503 — never an unverified install.

**A trap worth knowing**: `mrpack-install` (the tool the Modrinth modpack egg uses) expects Modrinth's **`version_number`** (e.g. `"13.5.0"`), not the internal version **id** (e.g. `"jmTldfTf"`) — the two look similar but aren't interchangeable, and the install would otherwise silently fail to resolve the pack. `installModpack()` always sends the `version_number` field from the platform's own `/files` response, never its `id`.

## Modpack installs feeding the referential

After a modpack install via `eggswitch`, its mods get recorded from the **manifest the egg's install script leaves on disk** — never by re-downloading the pack.

- `ModpackEggs::manifestsFor()`: `modpack.json` for Modrinth (`mrpack-install`), `manifest.json`/`client.manifest.json` for CurseForge.
- `WatchModpackInstallJob` polls `ModpackInstallService::installState()` (the only place that reads eggswitch's own status) every 30 seconds, for up to 45 minutes, until the server finishes installing (`status` empty and `installed_at` past the request — eggswitch's own "done" only means "reinstall launched", not finished). Once installed, it captures the manifest (≤2MB, read as a stream) and extracts only the entries it needs (name + SHA1 for Modrinth, project/file id pairs for CurseForge — a few KB, never the raw manifest) into `ProcessModpackManifestJob`, delayed by a minute.
- That job re-verifies every entry against the official API **and** against the real directory listing (exact name and size) before recording anything — a manifest is writable by the server's owner, so nothing in it is trusted at face value. Mods the manifest lists but that aren't actually on disk (common for client-only mods a dedicated server never installs) are simply skipped, not an error.
- CurseForge entries are hashed through `IndexCurseForgeSearchJob` with `source = modpack_index`, reusing the exact same Modrinth-linking and type-checking logic as a search result.

## Admin screen & stats

Read-only, `/admin/extensions/addonbrowser` (`admin/controller.php` + `AdminOverviewService`). Every method re-checks `root_admin`; `PATCH`/`PUT`/`DELETE` reply 404.

- **Collisions**: the same "which key matches more than one mod" query used for the badge, capped at 100 keys / 5,000 file rows.
- **Journal**: last 200 lines of `storage/logs/addonbrowser-collisions.log`, read from the end in 64KB blocks (capped at 256KB per file) so memory use stays flat regardless of file size.
- **Stats**: totals per game and per metric over 7 days, 30 days, and all-time, from the `addonbrowser_stats` table (`day`, `game`, `metric`, `total`, unique on the three; upserted `total = total + n`, a failed write is swallowed, never breaks the caller). Every metric is counted exactly once, at the point the underlying event is final (after a transaction commits, or when a file is actually removed from a verification batch) — never inside `GET /installed` itself. Metrics: `mod_created`, `mod_linked`, `mod_merged`, `conflict_refused`, `file_added_<source>` (one per `source` value) from `ModReferenceService`; `verify_sha1_db`, `verify_modrinth`, `verify_cf_fingerprint`, `verify_cf_match`, `verify_unknown`, `verify_abandoned`, `verify_too_large` from the hash-verification job.
- **Current state** ("Compute" button, a `POST` with CSRF): walks every server on a game with detection enabled, does one directory listing per server, and classifies files the same way the badge does, with no side effects (nothing written to the cache, the journal, or a job queue). Bounded to 50 servers and a 20-second budget; each listing itself is capped at a few seconds and a 4MB stream, and a lock allows only one computation at a time for the whole panel.

## External API calls

All requests to Modrinth, CurseForge and the CDNs go through `Support\ExternalApi` — no direct HTTP client calls anywhere else in the addon.

- **Interactive calls** (search, versions, categories, a modpack pre-install check): 8s timeout, one retry on a connection failure or timeout only (never on an HTTP error response, which is passed through as-is).
- **Batched job calls** (`version_files`, `projects`, `mods/files`, `fingerprints`): their own timeouts (10s), no retry (jobs have their own time budget).
- **User-Agent**: `pterodactyl-addonbrowser/<version> (<panel host>)`, the format Modrinth's API docs ask for.
- **Modrinth token** (optional, `MODRINTH_API_KEY`): sent only to `api.modrinth.com`. It doesn't raise the rate limit, it just identifies the traffic — give it no write scopes.

## Theme hooks

`AddonBrowserContainer.tsx` is plain native HTML/Tailwind, with stable `addonbrowser-*` classNames on every meaningful element, in addition to the Tailwind utility classes, so a theme addon can restyle it. See [architecture §8](architecture-addonbrowser-addon.md#8-frontend-plain-markup-and-theme-classes) for why they exist and why they're deliberately generic. Current hooks:

`addonbrowser-page`, `addonbrowser-panel`, `addonbrowser-card`, `addonbrowser-tab`, `addonbrowser-input`, `addonbrowser-select`, `addonbrowser-modal`, `addonbrowser-modal-backdrop`, `addonbrowser-modal__section`, `addonbrowser-btn-neutral`, `addonbrowser-heading`, `addonbrowser-text`, `addonbrowser-muted`, `addonbrowser-tag`, `addonbrowser-error`, `addonbrowser-checkbox`, `addonbrowser-installed-badge` (+ `--disabled`), `addonbrowser-version-installed`, `addonbrowser-mods-incompatible`, `addonbrowser-installed-pending`.

This repository contains no CSS for any of these — only the hooks themselves. Renaming or removing one silently breaks the reskin of any theme addon that targets it: treat it as a public API, not an internal detail.

## Known gotchas worth remembering

- **The panel's error format isn't `{"message": ...}`.** It's `{"errors": [{"code", "status", "detail"}]}` (`app/Exceptions/Handler.php::convertExceptionToArray` on the panel core side). Code that reads `e.response?.data?.message` gets `undefined` and falls back to a generic Axios message instead of the real, useful one. Any new HTTP call added to the frontend should go through the shared `extractErrorMessage()` helper, which reads `errors[0].detail` first.
- **`private/` only ships because `conf.yml` declares it** (`data.directory: 'private'`). Without that line, Blueprint never copies the folder, so `install.sh` and `remove.sh` silently never run and `config/addonbrowser.php` is never deployed — the install still reports success.
- **A missing throttle prefix shares its rate-limit counter with every other unprefixed route for the same user** (`throttle:N,M` vs. `throttle:N,M,<prefix>` — Laravel's default key is the user alone). Always give every `throttle:` middleware its own prefix, or a permissive route and a strict one end up sharing one counter.
