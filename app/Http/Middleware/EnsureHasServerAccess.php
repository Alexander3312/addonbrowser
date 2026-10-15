<?php

namespace Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Level 1 of 2: the user is logged in and has access to at least one server —
 * as owner or subuser (the Addons tab is also for subusers), or is an admin.
 * Closes the browsing routes (search, versions…) to accounts without a server,
 * which could otherwise use them as a relay to Modrinth and CurseForge (with
 * the panel's CurseForge key).
 *
 * Authorizes NOTHING on a specific server: every route that targets a server
 * keeps its own `authorize()` and state check in the controller (level 2).
 */
class EnsureHasServerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        if (! $user->root_admin && ! $user->accessibleServers()->exists()) {
            abort(403, "You don't have access to any server.");
        }

        return $next($request);
    }
}
