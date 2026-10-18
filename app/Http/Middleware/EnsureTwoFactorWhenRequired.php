<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Exceptions\Http\TwoFactorAuthRequiredException;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the panel's "require two-factor authentication" setting to the addon's
 * routes, the way the panel's own RequireTwoFactorAuthentication does for its
 * pages and API: an account that must use 2FA (everyone, or only admins,
 * depending on the setting) and hasn't set it up is refused.
 *
 * The panel's middleware isn't reused as is: for a request that isn't JSON
 * (the GET calls of the Addons tab carry no JSON body) it redirects to /account
 * instead of answering, which would hand the tab an HTML page. These routes are
 * only ever called by the tab, so the refusal is always the same JSON error the
 * panel renders for its API.
 */
class EnsureTwoFactorWhenRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $level = (int) config('pterodactyl.auth.2fa_required');
        $required = $level === RequireTwoFactorAuthentication::LEVEL_ALL
            || ($level === RequireTwoFactorAuthentication::LEVEL_ADMIN && $user->root_admin);

        if ($required && ! $user->use_totp) {
            throw new TwoFactorAuthRequiredException();
        }

        return $next($request);
    }
}
