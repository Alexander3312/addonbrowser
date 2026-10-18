<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\AddonBrowserController;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Http\Middleware\EnsureHasServerAccess;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Http\Middleware\EnsureTwoFactorWhenRequired;

// Every route of the addon requires a logged-in user: the "blueprint" middleware
// group (see app/Http/Kernel.php) has no authentication check by default, so
// without this middleware these endpoints would be reachable anonymously (open
// proxy to CurseForge/Modrinth, server info leak).
// "auth.session" (like the panel's own routes): a session invalidated by a
// password change ("log out other devices") is refused here too.
// EnsureTwoFactorWhenRequired applies the panel's mandatory-2FA setting.
// EnsureHasServerAccess also requires the user to have access to at least one
// server (or to be an admin).
Route::middleware(['auth', 'auth.session', EnsureTwoFactorWhenRequired::class, EnsureHasServerAccess::class])->group(function () {
    // 3rd parameter = explicit throttle prefix: without it, Laravel's rate limiter
    // key depends ONLY on the authenticated user, never on the route (see
    // vendor/laravel/framework/.../ThrottleRequests::resolveRequestSignature()) —
    // so the throttle:N,M groups below would all share one counter per user, and
    // using up the (lenient) search limit would trigger a 429 on the (strict)
    // install-modpack, unrelated to its own use.

    // Browsing/search endpoints: per-user throttle so that abuse can't eat up
    // the CurseForge/Modrinth API quota.
    Route::middleware('throttle:60,1,addonbrowser-read')->group(function () {
        Route::get('/search', [AddonBrowserController::class, 'search'])->name('extension.addonbrowser.search');
        Route::get('/categories', [AddonBrowserController::class, 'categories'])->name('extension.addonbrowser.categories');
        Route::get('/resolve-game', [AddonBrowserController::class, 'resolveGame'])->name('extension.addonbrowser.resolve-game');
        Route::get('/loaders', [AddonBrowserController::class, 'loaders'])->name('extension.addonbrowser.loaders');
        Route::get('/files', [AddonBrowserController::class, 'files'])->name('extension.addonbrowser.files');
        Route::get('/modpack-install-available', [AddonBrowserController::class, 'modpackInstallAvailable'])->name('extension.addonbrowser.modpack-install-available');
        Route::get('/game-versions', [AddonBrowserController::class, 'gameVersions'])->name('extension.addonbrowser.game-versions');
        Route::get('/server-environment', [AddonBrowserController::class, 'serverEnvironment'])->name('extension.addonbrowser.server-environment');
    });

    // download() actually writes a file to the node's disk through the daemon:
    // stricter limit than plain browsing/search.
    Route::middleware('throttle:20,1,addonbrowser-download')
        ->post('/download', [AddonBrowserController::class, 'download'])
        ->name('extension.addonbrowser.download');

    // installed(): mods already on the server ("Installed" badge). Dedicated
    // counter: the tab calls it on open, after each download, and every 10 s
    // during a hash-based verification.
    Route::middleware('throttle:30,1,addonbrowser-installed')
        ->get('/installed', [AddonBrowserController::class, 'installed'])
        ->name('extension.addonbrowser.installed');

    // install-modpack() triggers a full reinstall through eggswitch (wipe +
    // reinstall) — 5 times per hour, not the same limit as download(): it's a
    // much heavier action than a single download.
    Route::middleware('throttle:5,60,addonbrowser-install-modpack')
        ->post('/install-modpack', [AddonBrowserController::class, 'installModpack'])
        ->name('extension.addonbrowser.install-modpack');
});

