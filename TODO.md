# TODO / Known limitations

Things that are known to be missing, incomplete, or worth revisiting. Not a promise of when (or whether) any of these will happen — just a place to keep track instead of losing them in commit messages.

## No automated test suite

There's no PHPUnit suite. Every feature has been verified by hand (real API calls on real servers, one-off scripts), none of which is kept in the repository: nothing re-runs automatically after a change. See [`DEVELOPMENT.md`](DEVELOPMENT.md) for how to verify a change.

## `gameConfig.json` has no admin UI

Adding or tweaking a game means editing `gameConfig.json` and redeploying — there's no screen for it. The admin screen that does exist (read-only) only covers the installed-mods referential (collisions, journal, stats, current state).

## Hytale and Palworld are configured but untested end-to-end

Minecraft is the only game that's actually been exercised through the whole flow (search, download, installed-mods detection) on real servers. The per-game filtering logic is the same for every game, so Hytale and Palworld *should* work, but nobody has confirmed it on a real server for either.

## Loader mismatch isn't flagged

The installed-mods detection recognizes a mod by content/name, not by loader. A Forge build of a mod copied onto a Fabric server is recognized as "installed" with no warning that it's the wrong loader. Detecting the mod and detecting whether it's the *right* variant for this server are two different problems; only the first one is solved.

## Modpacks installed before or outside the addon aren't indexed

Indexing a modpack's mods (from the manifest the modpack egg leaves on disk) only happens right after an install made through the addon, so a modpack installed earlier, or outside the addon, isn't indexed. Its mods only get recognized as they're found some other way (a direct download, a CurseForge search result, or the hash-based verification pass reading the files). No retroactive indexing command exists.

## Modpack egg ids are hardcoded, and the eggs aren't shipped

`ModpackEggs` maps (game, platform) to hardcoded egg ids — placeholders that must be replaced with your own panel's ids (see the README) — and the eggs themselves (a Modrinth modpack egg running `mrpack-install`, and a CurseForge modpack egg prepared for `eggswitch`) aren't part of this repository. Until the mapping is configurable (e.g. in `gameConfig.json` or the admin screen) and the eggs are published alongside the addon, full modpack installs only work after editing `ModpackEggs` by hand and creating equivalent eggs.

## Java recommendation thresholds are hardcoded

`MinecraftJavaImages::THRESHOLDS` (which Minecraft versions need Java 8, 16, 17, 21, and `FALLBACK_LABEL` = Java 25 beyond that) is a hand-maintained list. When a new Minecraft release requires a newer Java, it has to be edited in code. It also relies on the target egg's Docker image labels starting with `Java <n>`: an egg labelled differently gets no recommendation.

## Modpacks are Minecraft-only

Other games get mods but no "Modpacks" tab. Supporting modpacks for another game needs a modpack egg for it (mapped in `ModpackEggs::MAP`), its CurseForge modpack class (`CurseForgeService::MODPACK_CLASS_ID_MAP`), the environment variables that egg expects (`ModpackInstallService::buildEnvironment()`), and, for the installed-mods badge, the manifest that egg leaves on disk (`ModpackEggs::manifestsFor()`). None of that is in `gameConfig.json` yet.

## Game detection is per nest, not per egg

Every egg in a recognized nest gets the Addons tab the same way. That includes eggs that manage their own mods (a full-modpack egg like CurseForge Generic installs every mod through its own install script): users on such an egg still see the tab and can add mods to `/mods`. Harmless today, but a per-egg exception would be needed for a different behavior on those eggs.

## CurseForge-only games have a hardcoded `GAME_ID_MAP`

`CurseForgeService::GAME_ID_MAP` (Minecraft, Hytale, Palworld → CurseForge's own game ids) is a hand-maintained constant, not read from `gameConfig.json`. Adding a new CurseForge-only game means editing this map in code, not just the config file — inconsistent with the "add a game via config only" story for everything else.

## Re-downloading a known mod still calls the platform API

After every download, `RecordDownloadedModJob` asks the platform about the file (Modrinth `version_files`, or CurseForge `mods/files` plus a Modrinth lookup to link both platforms) before writing to the referential — even when that exact file is already there. Nothing gets duplicated (`recordFile()` returns `unchanged`), but it costs one or two pointless API calls per re-download. A cheap shortcut: look the SHA1 up in the referential first (`findFilesBySha1()`) and stop if the file is already known *with the id of the platform it was downloaded from*. The API call is still needed when the file is only known from the other platform: that's how a mod gets its second id.
