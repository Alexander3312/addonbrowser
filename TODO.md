# TODO / Known limitations

Things that are known to be missing, incomplete, or worth revisiting. Not a promise of when (or whether) any of these will happen — just a place to keep track instead of losing them in commit messages.

## No automated test suite

There's no PHPUnit suite. Every feature has been verified by hand (real API calls on real servers, one-off scripts), none of which is kept in the repository: nothing re-runs automatically after a change. See [`DEVELOPMENT.md`](DEVELOPMENT.md) for how to verify a change.

## Server plugins (Paper, Spigot, Bukkit…) aren't supported

The addon currently only handles mods and modpacks, and plugins are filtered out along the way. **I don't plan to work on this myself, but a pull request adding it is very welcome.** Some pointers for whoever picks it up:

- Modrinth: searches are currently restricted to `project_type:mod`/`modpack`, and `ModrinthService::getLoaders()` drops every loader that supports `plugin` (Paper, Spigot, Purpur, Velocity…).
- CurseForge: `curseforge.mod_class_ids` in `gameConfig.json` currently only lists the mods classes (Minecraft's "Bukkit Plugins" class, 5, is excluded), and `MOD_LOADER_TYPES` leaves out `cauldron`.
- Downloads: each game currently has a single `target_folder` in `gameConfig.json` (`mods` for Minecraft), while plugins usually go to `plugins/`.
- Installed-mods detection currently only knows mod filenames; plugins would need their own rules (a normalizer in `FilenameNormalizer::TOKENS`).

## CI only checks PHP syntax

The GitHub Actions workflow (`.github/workflows/check.yml`) only runs `php -l` on every PHP file. Worth adding:

- **TypeScript type-check**: the component imports panel modules (`@/state/server`, `@/api/http`, `ServerContentBlock`…), so it can't compile on its own. The job would check out `pterodactyl/panel` at a pinned version, install its dependencies (`yarn`), copy `resources/scripts/components/server/addon` into it and run `tsc` with the panel's `tsconfig.json` (tolerating the known `TS7016` on `lodash-es/debounce`).
- **Shell scripts**: `bash -n` on `private/install.sh` and `private/remove.sh`.
- **JSON**: validate `gameConfig.json` (well-formed, and each game has the expected keys).

## `gameConfig.json` has no admin UI

Adding or tweaking a game means editing `gameConfig.json` and redeploying — there's no screen for it. The admin screen that does exist (read-only) only covers the installed-mods referential (collisions, journal, stats, current state).

## Hytale and Palworld are configured but untested end-to-end

Minecraft is the only game that's actually been exercised through the whole flow (search, download, installed-mods detection) on real servers. The per-game filtering logic is the same for every game, so Hytale and Palworld *should* work, but nobody has confirmed it on a real server for either.

## Loader mismatch isn't flagged

The installed-mods detection recognizes a mod by content/name, not by loader. A Forge build of a mod copied onto a Fabric server is recognized as "installed" with no warning that it's the wrong loader. Detecting the mod and detecting whether it's the *right* variant for this server are two different problems; only the first one is solved.

## Modpacks installed before or outside the addon aren't indexed

Indexing a modpack's mods (from the manifest the modpack egg leaves on disk) only happens right after an install made through the addon, so a modpack installed earlier, or outside the addon, isn't indexed. Its mods only get recognized as they're found some other way (a direct download, a CurseForge search result, or the hash-based verification pass reading the files). No retroactive indexing command exists.

## Modpack eggs aren't shipped

The eggs modpack installs rely on (a Modrinth modpack egg running `mrpack-install`, and a CurseForge modpack egg prepared for `eggswitch`) aren't part of this repository yet: full modpack installs only work after creating equivalent eggs and setting their ids in the `.env` (see the README). The addon also doesn't check that a configured egg really is a modpack egg (e.g. that it declares the expected variables): a wrong id, whitelisted in eggswitch, would reinstall the server with the wrong egg.

## Java recommendation thresholds are hardcoded

`MinecraftJavaImages::THRESHOLDS` (which Minecraft versions need Java 8, 16, 17, 21, and `FALLBACK_LABEL` = Java 25 beyond that) is a hand-maintained list. When a new Minecraft release requires a newer Java, it has to be edited in code. It also relies on the target egg's Docker image labels starting with `Java <n>`: an egg labelled differently gets no recommendation.

## Modrinth categories are hardcoded

`ModrinthService::getCategories()` returns two lists copied by hand from `GET /v2/tag/category` (19 mod categories, 10 modpack categories). If Modrinth adds or renames a category, the "Category" filter won't show it until the code is updated. Reading the list from the API and caching it for a few hours, the way loaders and game versions already are, would keep it current.

## Modpacks are Minecraft-only

Other games get mods but no "Modpacks" tab. Supporting modpacks for another game needs a modpack egg for it (its id in the `.env`, read through `modpack_eggs` in `private/config/addonbrowser.php`), its CurseForge modpack class (`curseforge.modpack_class_id` in `gameConfig.json`), the environment variables that egg expects (`ModpackInstallService::buildEnvironment()`), and, for the installed-mods badge, the manifest that egg leaves on disk (`ModpackEggs::manifestsFor()`). Only the modpack class is in `gameConfig.json` so far.

## Game detection is per nest, not per egg

Every egg in a recognized nest gets the Addons tab the same way. That includes eggs that manage their own mods (a full CurseForge modpack egg installs every mod through its own install script): users on such an egg still see the tab and can add mods to `/mods`. Harmless today, but a per-egg exception would be needed for a different behavior on those eggs.

## Re-downloading a known mod still calls the platform API

After every download, `RecordDownloadedModJob` asks the platform about the file (Modrinth `version_files`, or CurseForge `mods/files` plus a Modrinth lookup to link both platforms) before writing to the referential — even when that exact file is already there. Nothing gets duplicated (`recordFile()` returns `unchanged`), but it costs one or two pointless API calls per re-download. A cheap shortcut: look the SHA1 up in the referential first (`findFilesBySha1()`) and stop if the file is already known *with the id of the platform it was downloaded from*. The API call is still needed when the file is only known from the other platform: that's how a mod gets its second id.
