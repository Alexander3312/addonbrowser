# Architecture — Addon Browser (`addonbrowser`)

Why the addon is built the way it is: the decisions that needed making, and the reasons behind them. The current implementation details live in `DEVELOPMENT.md`, known limitations in `TODO.md`. This document describes the code as it actually is, not a plan to follow.

---

## 1. Goal

Let a server's owner (or an authorized subuser) browse and install mods and modpacks from Modrinth or CurseForge straight from the panel, without SFTP. The server's game is detected automatically from its nest.

---

## 2. Security model

Two authorization levels:

**Level 1, every route**: `auth` + `auth.session` + `EnsureTwoFactorWhenRequired` + `EnsureHasServerAccess`. `auth.session` refuses a session the panel has invalidated (a password change that logs out other devices), like the panel's own routes. `EnsureTwoFactorWhenRequired` applies the panel's mandatory two-factor setting: the panel's own middleware redirects non-JSON requests to /account instead of answering, which would hand the tab an HTML page for its `GET` calls, so the addon answers with the panel's JSON error instead. `EnsureHasServerAccess` — the user must have access to at least one server (owner or subuser), or be an admin. Browsing routes (`/search`, `/categories`, `/loaders`, `/files`…) touch no server data, they just relay a search to a public third-party API; without this level, an account with no server at all could use them as a relay to Modrinth and CurseForge, with the panel's CurseForge key. Throttled at `60,1` so abuse can't burn through the API quota shared by the whole panel.

**Level 2, routes tied to a specific server** (`resolve-game`, `server-environment`, `installed`, `download`, `install-modpack`): `$this->authorize('file.create', $server)` on every call — never cached, never inferred from a previous call. Routes that read from or write to the server then call `$server->validateCurrentState()`: a suspended server, a node under maintenance, or an install, restore or transfer in progress gets a 409, just like the panel's client API. Extension routes don't go through the native middleware that performs this check: without it, the owner of a suspended server could still write mods to it or reinstall it through a modpack.

Unlike `eggswitch` (which implements its own `EggSwitchScopeService::assertOwnsServer`, deliberately restricted to the owner), `addonbrowser` reuses the panel's native policy (`app/Policies/ServerPolicy.php`): root_admin, owner, or any subuser whose permissions include `file.create`. That's consistent with what the action is (writing a file = the same permission as a real file manager), whereas `eggswitch` changes the egg / fully reinstalls the server, a much heavier action an admin might want to reserve for the owner.

`POST /download` (the only route that actually writes to the server's disk, through the Wings daemon) has a stricter throttle (`20,1`) than browsing, and stacks several validation layers that don't depend on the frontend:

1. **`https` only + CDN host allow-list** (`ALLOWED_DOWNLOAD_HOSTS`, e.g. `cdn.modrinth.com`, `edge.forgecdn.net`): only a host explicitly listed in this constant is accepted as the file's origin — the list will evolve with supported platforms, check the code for the exact current state rather than this doc.
2. **Anti-SSRF on redirects**: CurseForge always redirects (`edge.forgecdn.net` → `mediafilez.forgecdn.net`), so redirects can't just be blocked. They're followed **manually** (`allow_redirects: false` on the HTTP client), re-validating the destination host against the same allow-list, and the `https` scheme, at **every hop**, up to 5 hops. An allowed host redirecting outside the allow-list is rejected (502) rather than blindly followed — otherwise a compromised (or just misconfigured) allowed host could be used as a bounce to any internal/arbitrary URL.
3. **File name taken from the URL**: the last path segment of `downloadUrl`, decoded, never a parameter sent by the browser — the frontend runs on the user's side, and a free `filename` unrelated to the URL would allow writing one mod's content under another's name. The allow-list guarantees where the bytes come from; taking the name from the URL guarantees it matches those bytes. Then explicit rejection of invalid UTF-8, separators (even once decoded, `%2F`), control characters / null bytes, and empty names, dot-only names (`.`, `..`) or names over 255 bytes.
4. **Maximum size**: 50MB, read as a stream and cut off mid-transfer (`Content-Length` header first, then the actual byte count), with a clean error. The file goes through memory before being sent to Wings, and PHP-FPM is limited to 128MB.
5. **Allowed extension**: re-checked against `AddonGameMapService::allowedExtensions($game)` — never trusting what the frontend shows as a filter; a game with no declared list allows nothing.
6. **Target folder**: `AddonGameMapService::targetFolder($game)`, a single folder per game (mods only), never sent by the client.

**What `download` does NOT do** (unlike `install-modpack`): no in-app confirmation à la `confirm_server_name` — the action (downloading a file into a folder) is reversible (the file can be deleted afterwards), unlike a full wipe, so the friction is deliberately lower.

---

## 3. Game detection: by nest, not by egg

`AddonGameMapService::resolveGameFromNestName(string $nestName): ?string` compares the nest's name (case-insensitive) with the `nest_names` in `gameConfig.json`. **An unrecognized nest isn't handled**: `resolve-game` returns `game: null, supported: false`, the frontend shows a "game not recognized or not supported" message instead of the browser, and `download`/`install-modpack` reply 422.

**No fallback, no default game, no game sent by the client**: the game comes only from the server's nest, for every route. A silent fallback (to `minecraft`, or to the request's `game`) would offer Minecraft mods to a Discord bot or Rust server.

**By nest, not by egg**: every egg in a nest is treated the same. That's enough for browsing mods (the `/mods` folder doesn't change with the exact egg within a Minecraft nest), but falls short when a nest contains an egg that isn't meant to receive mods one by one — typically a "full modpack" egg such as a CurseForge modpack egg, which installs every mod itself through its own `script_install`. That case isn't handled specifically today: a user on that egg would still see the Addons tab, with behavior that doesn't necessarily match how the egg manages its own files. Not blocking (mods are added to `/mods`, which stays harmless); noted in `TODO.md`.

---

## 4. Configuration: never in a panel core file

### Why

Reading `config('services.curseforge.api_key')` would depend on an entry that doesn't exist in Pterodactyl's `config/services.php`: added there by hand, it gets wiped by the next panel update (`composer update` / `git pull` overwrites that core file, versioned by Pterodactyl itself). `CurseForgeService` would then throw a `RuntimeException` as soon as it's constructed — and since it's injected into `AddonBrowserController`'s constructor, **every** endpoint of the addon would return a 500, including a Minecraft/Modrinth search that has nothing to do with CurseForge.

### The general rule

**Never make a Blueprint addon depend on an entry added by hand to a panel CORE config file** (`config/services.php`, `config/app.php`, etc.) — those files are managed/overwritten by the panel's normal lifecycle (updates), and Blueprint has no mechanism whatsoever to make such a patch stick (no marker, no merge, nothing). Here, the result would be a total outage of the addon.

**Good practice**: any Blueprint addon that needs a `.env`/config value should go through **its own config file** (`config/<identifier>.php`, deployed by the addon's own `install.sh` — see `private/config/addonbrowser.php` → `config/addonbrowser.php`), never through a shared core file. An addon-owned config file is never touched by a panel update, since it doesn't exist in Pterodactyl's core repository.

### What the addon does

- `private/config/addonbrowser.php` (the addon's source) exposes `curseforge_api_key => env('CURSEFORGE_API_KEY')` (then `modrinth_api_key` and the modpack egg ids, `modpack_eggs`), alongside `log_level`, and `CurseForgeService` reads `config('addonbrowser.curseforge_api_key')`.
- **`private/install.sh` rewrites this file on every install** (keeping a `.bak` copy of the old one if it had been edited by hand). Never overwriting an existing file (to avoid clobbering a customized config) would mean a new key never reaches an existing installation. The file only contains `env()` calls, no secret and no installation-specific setting: overwriting it loses nothing, the values stay in `.env`. `private/remove.sh` deletes it when the addon is removed.
- **These scripts only run because `conf.yml` declares `data.directory: 'private'`**: that's what makes Blueprint copy the folder into the installed extension. Without it, the install silently skips both scripts and still reports success.

---

## 5. External services

- **Modrinth** (`ModrinthService`): public API, no key required. Used for Minecraft only (mods and modpacks, no other content type). Available loaders are cached for 12 hours (`Cache::remember`) to avoid a network call on every filter change.
- **CurseForge** (`CurseForgeService`): requires an API key (`CURSEFORGE_API_KEY`, see section 4). The only platform for non-Minecraft games, and Minecraft's second platform (`"platforms": ["modrinth", "curseforge"]` in `gameConfig.json`, chosen per tab on the frontend, re-checked by `resolvePlatform()`). The CurseForge `classId` is forced server-side for every game (`resolveCurseForgeClassId()`, details in `DEVELOPMENT.md`), never the client's: without it, a search could bring back plugins, worlds or resource packs. Games are mapped to their CurseForge `gameId` and classes in `gameConfig.json` (`curseforge` block: minecraft=432, hytale=70216, palworld=85196), so a new CurseForge game only needs a config entry.
- **Short timeouts on interactive calls** (`Support\ExternalApi`): 8s and one retry on connection errors only, so ~16s at worst, under the 20s after which the browser (axios) gives up. With the default 30s timeout, the browser would give up first and the user would only see a "timeout" (e.g. during a Modrinth API outage, with calls hanging with no response). Batched job calls have no retry: they have their own time budget.

---

## 6. Modpack installs through an egg-whitelisting addon

Minecraft only for now (the only game with a modpack egg and a CurseForge modpack class, see `TODO.md`). Lets the user pick a whole modpack (rather than mod by mod) and trigger a reinstall to a dedicated egg, with environment variables matching the chosen modpack. See `DEVELOPMENT.md` for the current technical details.

**A deliberately generic dependency**: `addonbrowser` doesn't do any egg switching or whitelisting itself — it fully delegates to a third-party addon that provides that mechanism. `eggswitch` is the only provider today, but the boundary is drawn as if it were just *one of several possible* providers: `ModpackInstallService` is the only place in the code that knows the name `eggswitch` and its minimum version (`MIN_EGGSWITCH_VERSION`), checked through `BlueprintAdminLibrary::extension()` + `extensionConfig()` (not a `class_exists()`, which would break with a different autoloader, nor an assumption that it's there). The rest of the addon (game/platform mapping in `gameConfig.json`, HTTP controller, frontend) never references `eggswitch` directly — only an availability boolean (`GET /modpack-install-available`). The design in short:

- **`ModpackEggs::eggIdFor(game, platform)`** — a (game, platform) → egg id mapping rather than a single id, built from the start for several platforms per game: `minecraft` + `modrinth` → a Modrinth modpack egg (`mrpack-install`), `minecraft` + `curseforge` → a CurseForge modpack egg prepared for eggswitch. **The ids come from the panel's `.env`** (`ADDONBROWSER_MINECRAFT_MODPACK_EGG_*`, through the addon's own config file, see section 4): they're panel-specific, so they're neither in the code nor in `gameConfig.json`, which an addon update overwrites. These eggs aren't shipped with the addon: see `TODO.md`.
- **`ModpackInstallService`** — thin controller → dedicated service, a pure translation to `EggSwitchRequestService::requestReinstallForAddon()`, resolved directly through the container (both addons run in the same Laravel process) — no internal HTTP call, no duplicated security rule.
- **Availability check**: `GET /modpack-install-available` only returns a boolean, never the egg id or a variable name — the frontend doesn't and never will need to know these eggswitch internals. Checked through `EggWhitelist::isAllowedForAddon()` on the eggswitch side (not `Route::has()`, which would prove nothing for this specific path), and only if eggswitch is installed in a recent enough version.
- **Java version recommended by the backend** (`ModpackInstallService::recommendedDockerImage()`, `Support\MinecraftJavaImages`): the frontend passes the game versions as received from the platform, never a decision. And it's addonbrowser that picks the image, not eggswitch: eggswitch is a generic egg-switching mechanism and must know nothing about Minecraft; it only receives an image that's already been decided, which it re-checks against the target egg's images.
- **Frontend**: a "Modpacks" mode separate from the existing "Mods" mode (the target folder in `gameConfig.json` only applies to downloading a single mod, not to a modpack that triggers a full reinstall). A dedicated destructive-action confirmation dialog.
- **Modpack checked before calling eggswitch**: the egg's script starts by wiping the server, so an invalid project/version pair would leave the server empty. Details, and the `mrpack-install` trap (`version_number`, not the `id`), in `DEVELOPMENT.md`.

Modrinth has modpack-specific categories (a dedicated taxonomy, separate from the mods one), checked against the API.

**CurseForge modpacks**: same flow, through the CurseForge modpack egg, allowed for addon use in eggswitch's whitelist. The differences from Modrinth all live in `ModpackInstallService::buildEnvironment()`: `PROJECT_ID` / `VERSION_ID` variables, where `VERSION_ID` is the numeric CurseForge **fileId** (the frontend puts it in `version_number`, CurseForge having no other notion of a version number), never `displayName`/`fileName`. The API key (`API_KEY`) isn't sent: it's an admin-only variable of the egg, whose `default_value` eggswitch applies. CurseForge mixes a file's actual game versions, its loader and tags (`Client`, `Server`) into `gameVersions`: the frontend separates them so the filters mean the same thing as on Modrinth, then passes the versions on to the backend for the Java recommendation.

---

## 7. Installed-mods detection

Full implementation in `DEVELOPMENT.md` (referential, lookup steps, feeding, cache, hash verification, modpacks, admin screen) — here, only the decisions that needed making, and why.

- **No normalizer, no write** to the referential: a game with no declared normalizer gets no generic fallback, so a key is never computed with the wrong rules (a false "installed" is worse than no badge).
- **Binary collation** (`utf8mb4_bin`) on Modrinth ids and the normalized key, instead of the panel's: equality between two mods must be strict, case and accents included.
- **Two mods are merged only if no id conflicts**, for every data source (search results included). A merge can't be undone; when in doubt, refuse rather than guess — it only costs a missing badge on one platform.
- **A mod is created with both ids right away** as soon as Modrinth confirms the same file (same SHA1, same size) as a CurseForge result, rather than waiting for one of the two mods to already exist. Otherwise, the order in which mods are discovered (search before download, or the other way round) could leave the same mod on two unlinked rows — seen for real on Sodium and Lithium.
- **The CurseForge fingerprint never writes to the referential on its own** (32 bits, real collisions, easy to forge): it also needs the same official SHA1 and size. On its own, with a matching name, it's only worth a badge for that one server, never a shared entry.
- **`file.create` then `file.read`** for `/installed`: the response reveals the exact names of the server's files, which the panel already only shows with `file.read` — an addition to the §2 security model, which only covered `file.create`.
- **`compatible: false` only if the folder's absence is proven** (Wings returns the same 500 error for a missing folder as for many other failures): any other doubt gives a 503; better to show no badge than to wrongly claim a server is incompatible.
- **A single worker for the whole panel** (`pteroq`) dictated most of the jobs' pacing rules: short runs redispatched with their state, a global read-bytes budget shared across all servers, a single verification batch at a time per server, and a lifetime (`retryUntil`) always set an hour past the job's real limit — without that margin, a job picked up just after its own limit ends up in `failed_jobs` instead of stopping cleanly (verified with the real `Worker::process()`).
- **Modpacks are indexed from the manifest the egg leaves on disk**, never by re-downloading the pack: each entry is still re-checked against the official API and against the disk before being recorded — a tampered manifest can only skew its own server's badge, never the shared referential.
- **Eggswitch stays confined to `ModpackInstallService`**: watching an install only sees three states (pending, launched, abandoned), never eggswitch's raw statuses.
- **The admin screen is entirely read-only** and only shares the badge's side-effect-free steps (never the journal, the servers' cache or the queue); its compute button strictly bounds the load it can put on nodes (number of servers, timeouts, size read), one computation at a time for the whole panel.

---

## 8. Frontend: plain markup and theme classes

`AddonBrowserContainer.tsx` deliberately avoids a home-made design system (the addon it started from had one, "dark glass" components unrelated to the rest of the panel): it's *plain* native HTML (`<select>`, `<div>`, `<button>`) + literal Tailwind classes.

**A structural reason, not just an aesthetic one**: an addon with its own isolated design system can only be styled by an external theme through a full, fragile reskin (`styled-components`/`twin.macro` selectors aren't stable from one build to the next). With plain markup, `addonbrowser` simply puts stable classes prefixed `addonbrowser-` on every meaningful element (exact list and usage rules in `DEVELOPMENT.md`), with no CSS rule included in this repository.

These classes are deliberately **generic**, just like the dependency on an egg-whitelisting addon (§6): nothing in `addonbrowser` assumes a specific theme addon exists or is installed. The `addonbrowser-*` classNames are always set, whether a theme uses them or not — the addon works exactly the same (just with the panel's default styling) without any reskin addon.
