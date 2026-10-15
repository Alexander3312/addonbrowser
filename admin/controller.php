<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\addonbrowser;

use Illuminate\Http\Request;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Services\AdminOverviewService;
use Pterodactyl\BlueprintFramework\Extensions\addonbrowser\Support\CollisionJournal;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Addon Browser's admin screen, **read-only**: collisions in the mod
 * referential, journal (`addonbrowser-collisions.log`), event statistics, and
 * the servers' current state computed on demand. Routes declared by Blueprint
 * under /admin/extensions/addonbrowser (GET → index(), POST → post(), PATCH →
 * update(), PUT → put(), DELETE /<target>/<id> → delete()).
 *
 * Every query is made by Services\AdminOverviewService, never by the view
 * (resources/views/view.blade.php), which only displays.
 *
 * The /admin area is already restricted to admins (panel middleware); each
 * method checks it again anyway (root_admin, otherwise 403).
 *
 * - index(): the page, without computing the current state;
 * - post(): the same page, with the current state computed ("Compute"
 *   button: POST form with a CSRF token, can't be triggered by a plain link;
 *   it only reads);
 * - update(), put(), delete(): no write action in this screen (read-only by
 *   design) → 404.
 */
class addonbrowserExtensionController extends Controller
{
    public function __construct(
        private BlueprintExtensionLibrary $blueprint,
        private ViewFactory $view,
        private AdminOverviewService $overview,
    ) {
    }

    /** GET /admin/extensions/addonbrowser — the page, without computing the current state. */
    public function index(Request $request): View
    {
        $this->assertRoot($request);

        return $this->render($request, null);
    }

    /** POST /admin/extensions/addonbrowser — the page, with the current state computed (read-only). */
    public function post(Request $request): View
    {
        $this->assertRoot($request);

        return $this->render($request, $this->overview->currentState());
    }

    /** PATCH: no write action in this screen. */
    public function update(Request $request): never
    {
        $this->assertRoot($request);
        abort(404);
    }

    /** PUT: no write action in this screen. */
    public function put(Request $request): never
    {
        $this->assertRoot($request);
        abort(404);
    }

    /** DELETE /<target>/<id>: no write action in this screen. */
    public function delete(Request $request, string $target = '', string $id = ''): never
    {
        $this->assertRoot($request);
        abort(404);
    }

    /**
     * The page's data. Journal filter (`type`, in GET or in the form) checked
     * against the known types; invalid → no filter.
     */
    private function render(Request $request, ?array $state): View
    {
        $type = $request->input('type');
        $type = is_string($type) && in_array($type, CollisionJournal::TYPES, true) ? $type : null;

        return $this->view->make('admin.extensions.addonbrowser.index', [
            // Required by Blueprint's shared layout ($blueprint->extensionMetadata(...)):
            // without it, "Undefined variable $blueprint".
            'blueprint' => $this->blueprint,
            'root' => '/admin/extensions/addonbrowser',
            'collisions' => $this->overview->collisions(),
            'journal' => $this->overview->journal($type),
            'journalTypes' => CollisionJournal::TYPES,
            'stats' => $this->overview->stats(),
            'state' => $state,
            'limits' => [
                'collision_keys' => AdminOverviewService::MAX_COLLISION_KEYS,
                'journal_lines' => AdminOverviewService::JOURNAL_LIMIT,
                'max_servers' => AdminOverviewService::MAX_SERVERS,
                'time_budget' => AdminOverviewService::TIME_BUDGET,
            ],
        ]);
    }

    private function assertRoot(Request $request): void
    {
        abort_unless($request->user() && $request->user()->root_admin, 403);
    }
}
