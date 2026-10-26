<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesMobileDataScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/mobile/visibility
 *
 * Tells the mobile app what data-visibility options the logged-in user has
 * for Contacts and Properties, so it can decide whether to render the
 * "Mine / All / pick an agent" filter chips — exactly like the web sidebar
 * agent picker. When a module's `can_pick_agent` is false the app must show
 * ONLY the user's own records and hide the chips entirely.
 */
class MobileVisibilityController extends Controller
{
    use ResolvesMobileDataScope;

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'contacts'   => $this->moduleVisibility($user, 'contacts'),
            'properties' => $this->moduleVisibility($user, 'properties'),
            // AT-508: which rental screens this person may show, and how wide their records reach (own | branch | all)
            'rentals'    => $this->rentalScreens($user),
        ]);
    }

    /**
     * @return array<string, array{can_view:bool, can_act:bool, scope:?string}>
     */
    private function rentalScreens(User $user): array
    {
        // screen => [data-scope module, view permission, action permission]
        $screens = [
            'waiting'       => ['rental_command_centre', 'rental_command_centre.view', 'rental_command_centre.view'],
            'fault_reports' => ['rental_fault_reports', 'rental_fault_reports.view', 'rental_fault_reports.create'],
            'work_orders'   => ['rental_work_orders', 'rental_work_orders.view', 'rental_work_orders.create'],
            'leases'        => ['leases', 'leases.view', 'leases.renew'],
            'notices'       => ['rental_notices', 'rental_notices.review', 'rental_notices.review'],
            'inspections'   => ['rental_inspections', 'rental_inspections.view', 'rental_inspections.create'],
            'inventories'   => ['rental_inventories', 'rental_inventories.view', 'rental_inventories.create'],
        ];

        $out = [];
        foreach ($screens as $screen => [$module, $view, $act]) {
            $canView = $user->hasPermission($view);
            $out[$screen] = [
                'can_view' => $canView,
                'can_act'  => $canView && $user->hasPermission($act),
                'scope'    => $canView ? (\App\Services\PermissionService::getDataScope($user, $module) ?? 'own') : null,
            ];
        }

        return $out;
    }
}
