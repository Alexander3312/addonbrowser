# Addon Browser for Pterodactyl

**Addon Browser** is a [Blueprint](https://blueprint.zip/) extension that adds an **Addons** tab to the Pterodactyl client panel: search, install and update mods and modpacks from [Modrinth](https://modrinth.com) and [CurseForge](https://www.curseforge.com) without ever touching SFTP.

## Features

- **Search & one-click install** for mods on Modrinth and CurseForge, written straight into the server's mod folder.
- **Automatic game detection** from the server's nest — nothing to pick manually, the tab adapts itself.
- **"Installed" badges** on search results: the addon recognizes mods already on the server, even the same mod published on both platforms, or renamed by hand — so you don't install the same mod twice under two different filenames (a common cause of a server crashing on boot).
- **Full modpack installs** (Minecraft only for now), through the companion `eggswitch` extension if it's installed: egg switch + reinstall, with a destructive-action confirmation and an optional backup first.
- **Read-only admin screen**: known-mods overview, naming collisions, usage stats.

## Folder Structure

**Quick overview:**

- `conf.yml` — extension metadata & config.
- `app/AddonBrowserController.php` — HTTP routes: search, download, install.
- `app/Services/` — one service per external platform (`ModrinthService`, `CurseForgeService`), plus the game config (`AddonGameMapService`) and the modpack install flow (`ModpackInstallService`).
- `resources/scripts/components/server/addon/gameConfig.json` — **the per-game config**: platforms, loaders, allowed extensions, nest names, installed-mod detection settings, CurseForge game and class ids. This is the file to edit to add a new game, or to tweak an existing one.
- `app/Jobs/` — background work: installed-mod detection, modpack manifest indexing. Never run on the request path.
- `app/Models/` — the small referential used for installed-mod detection (`Mod`, `ModFile`).
- `resources/scripts/components/server/addon/AddonBrowserContainer.tsx` — UI code (React + Tailwind).
- `admin/controller.php` — the read-only admin screen.
- `routes/web.php` — Blueprint routes.

## Supported games

Minecraft (Modrinth + CurseForge), and CurseForge-only games such as Hytale and Palworld. Only Minecraft has been tested end-to-end so far — the per-game filtering itself is the same for every game, so the right mods should still show up for the others. Adding another game only needs a new entry in `gameConfig.json` (see [Folder Structure](#folder-structure)), no code change.

Modpacks are Minecraft-only for now: other games get mods, but no "Modpacks" tab.

## Requirements

- A Pterodactyl Panel with Blueprint (built against `beta-2026-08`).
- PHP 8+, and a running queue worker (`pteroq`) — installed-mod detection and modpack indexing happen in background jobs, not on the request path.
- Outbound HTTPS to `api.modrinth.com`, `cdn.modrinth.com`, `api.curseforge.com` and `edge.forgecdn.net`.
- A free [CurseForge API key](https://console.curseforge.com/) to search CurseForge.
- Optional: a personal Modrinth token (not required, but recommended).
- Optional, for full modpack installs: the `eggswitch` extension and dedicated modpack eggs on your panel — see [Setting up modpack installs](#setting-up-modpack-installs-optional).

## Installation

1. Download the latest `.blueprint` release, or build one from this repository.
2. Upload it to your Pterodactyl root.
3. Run `blueprint -i addonbrowser.blueprint`.
4. Set `CURSEFORGE_API_KEY` (and optionally `MODRINTH_API_KEY`) in your panel's `.env`, then `php artisan config:cache`.
5. Make sure your queue worker is running — the installed-mods badge won't appear without it.

### Setting up modpack installs (optional)

Full modpack installs (Minecraft only) need extra setup, because the eggs they rely on aren't shipped with the addon:

1. Install the `eggswitch` extension (1.1.0 or later). It isn't public yet: its repository should be published soon.
2. Create (or reuse) one modpack egg per platform on your panel. Its install script must wipe the server first, then install the pack from these variables:
   - **Modrinth**: `MODRINTH_PROJECT_ID` and `MODRINTH_VERSION_ID` (the version *number*, e.g. `13.5.0`, not the internal version id), typically with [`mrpack-install`](https://github.com/nothub/mrpack-install). `SERVER_JARFILE` gets the egg's default value.
   - **CurseForge**: `PROJECT_ID` and `VERSION_ID` (the numeric CurseForge file id). Keep the CurseForge API key as an admin-only variable of the egg (`API_KEY`): the addon never sends it.

     The easy way: import the community [CurseForge Generic egg](https://github.com/pelican-eggs/minecraft/tree/main/java/curseforge) from pelican-eggs. It already uses these variables, keeps `API_KEY` hidden from server owners, and leaves the manifest the addon reads. The only change it needs is the wipe, added at the very top of its install script, right after `#!/bin/bash`:

     ```bash
     : "${SERVER_DIR:=/mnt/server}"
     cd "${SERVER_DIR:?SERVER_DIR is empty, refusing to continue}" || exit 1
     rm -rf -- ..?* .[!.]* * 2>/dev/null
     ```

     Then set your CurseForge API key in the egg's `API_KEY` variable.
3. Allow both eggs for addon use in eggswitch's admin screen.
4. Put their ids in your panel's `.env`, then run `php artisan config:cache`:

   ```
   ADDONBROWSER_MINECRAFT_MODPACK_EGG_MODRINTH=26
   ADDONBROWSER_MINECRAFT_MODPACK_EGG_CURSEFORGE=18
   ```

   (`26` and `18` are examples: use your own eggs' ids, shown in the admin panel under Nests. A platform without an id simply has no modpack installs.)

When a modpack is installed, the addon pre-selects the right Java version for it. This works if the egg's Docker image labels start with `Java <n>` (e.g. `Java 21 (MC 1.20.5 - 1.21.x)`); otherwise there's simply no pre-selection. Which Minecraft versions need which Java is hardcoded in `app/Support/MinecraftJavaImages.php` (`THRESHOLDS` and `FALLBACK_LABEL`): when a new Minecraft release moves to a newer Java, that list has to be updated by hand.

For the installed-mods badge to recognize a modpack's mods right after the install, the egg should leave the pack's manifest at the server root: `modpack.json` (what `mrpack-install` leaves) for Modrinth, `manifest.json` or `client.manifest.json` for CurseForge. Without it, the mods are still recognized later, just more slowly.

Without this setup, the "Modpacks" tab simply stays hidden.

## Uninstalling

`blueprint -remove addonbrowser`. The known-mods tables are kept (Blueprint doesn't roll back migrations on removal) — nothing is lost if you reinstall later. The addon's `config/addonbrowser.php` is removed, but its values live in your `.env`, which isn't touched.

## Updating

Install the new `.blueprint` the same way. The install redeploys `config/addonbrowser.php` from the new version (your settings stay in `.env`), and Blueprint's own install step already runs `php artisan queue:restart`, so the queue worker picks up the new job code without anything extra to do.

## How it Works

### Frontend

`AddonBrowserContainer.tsx` (React + Tailwind): search, filters (game version, loader, sort), a version picker that highlights the one already installed, and the modpack install flow.

### Backend

`AddonBrowserController` dispatches to a service per platform (`ModrinthService`, `CurseForgeService`), with `AddonGameMapService` reading the per-game configuration and `ModpackInstallService` handling the egg switch. Installed-mod detection has its own small referential (two tables, fed only by background jobs and official API data — never by anything a client submits).

### Security

- `file.create` is re-checked by Pterodactyl's own policy on every server-scoped route, never cached or assumed.
- Downloads are restricted to an allow-list of CDN hosts, with redirects followed manually and re-validated at every hop (no SSRF via a compromised or misconfigured redirect).
- A downloaded file's name always comes from the official CDN URL, never from anything the browser sends.

## Developing & Local Testing

There's no automated test suite yet. See [`DEVELOPMENT.md`](DEVELOPMENT.md) for how to verify a change (on a dedicated development panel), [`architecture-addonbrowser-addon.md`](architecture-addonbrowser-addon.md) for the reasoning behind every non-obvious decision, and [`TODO.md`](TODO.md) for known gaps. Most of the code was written with heavy AI assistance (Claude).

## Contributing

Issues and pull requests are welcome — I'm still fairly new at this, so bear with me. Read `DEVELOPMENT.md` and `architecture-addonbrowser-addon.md` first if you're touching the installed-mods detection or the modpack flow — both carry non-obvious safety rules.

## License

Copyright (C) 2026 Alexander3312. Based on [BluePrint-Free-Plugins-Extension](https://github.com/fernsehheft/BluePrint-Free-Plugins-Extension) by fernsehheft. Licensed under the GNU GPLv3 — see [`LICENSE`](LICENSE).

## Acknowledgements

Based on [BluePrint-Free-Plugins-Extension](https://github.com/fernsehheft/BluePrint-Free-Plugins-Extension/tree/main) by fernsehheft, heavily modified.

- Modrinth API & team
- The Pterodactyl contributors
- Blueprint Framework maintainers
- Everyone else who’s pitched in along the way!
