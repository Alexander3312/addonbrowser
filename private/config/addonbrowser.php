<?php

// Deployed automatically to <panel root>/config/addonbrowser.php by
// private/install.sh on install, and deleted by private/remove.sh on removal.
// This copy, in the addon's repository, is only the source: Laravel only loads
// the one written at the panel root.
//
// Sets the addon's log verbosity (AddonBrowserLogger) independently of the
// panel's global LOG_LEVEL, through this variable in the panel's .env:
//   ADDONBROWSER_LOG_LEVEL=debug|info|warning|error|none
//
// curseforge_api_key: read here on purpose rather than through
// config('services.curseforge.api_key') (config/services.php) — that one is a
// CORE panel file, with no Blueprint mechanism to survive an update
// (`composer update`/`git pull` silently overwrites it).
// config/addonbrowser.php, on the other hand, belongs entirely to this addon
// (deployed by private/install.sh) and is never touched by a panel update — so
// it lasts without any core patch.
//
// modrinth_api_key: personal Modrinth token (MODRINTH_API_KEY), optional. The
// endpoints used are public and the limit (300 requests/min per IP) is the same
// with or without a token: it only identifies the panel's traffic. Sent only to
// api.modrinth.com (Support\ExternalApi::modrinthHeaders()), never to the CDN
// or to CurseForge. Give it as few permissions as possible (no write scope).
//
// modpack_eggs: ids of the panel's modpack eggs, per game and source platform
// (Support\ModpackEggs). Panel-specific, hence in the .env:
//   ADDONBROWSER_MINECRAFT_MODPACK_EGG_MODRINTH=<egg id>
//   ADDONBROWSER_MINECRAFT_MODPACK_EGG_CURSEFORGE=<egg id>
// Unset → modpack installs are off for that platform.
return [
    'log_level' => env('ADDONBROWSER_LOG_LEVEL', 'error'),
    'curseforge_api_key' => env('CURSEFORGE_API_KEY'),
    'modrinth_api_key' => env('MODRINTH_API_KEY'),
    'modpack_eggs' => [
        'minecraft' => [
            'modrinth' => env('ADDONBROWSER_MINECRAFT_MODPACK_EGG_MODRINTH'),
            'curseforge' => env('ADDONBROWSER_MINECRAFT_MODPACK_EGG_CURSEFORGE'),
        ],
    ],
];
