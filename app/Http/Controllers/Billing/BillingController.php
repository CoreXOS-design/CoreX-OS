<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Services\Billing\SubscriptionPricingService;
use App\Services\Billing\SubscriptionReconciler;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The agency-facing billing page — READ ONLY.
 *
 * Spec: .ai/specs/agency-billing.md §8.1  (AT-11)
 *
 * An agency admin sees exactly what they owe CoreX and — crucially — the
 * arithmetic behind it, itemised, so they can check our maths. They cannot
 * change anything: plan, custom amount and discount are commercial terms set by
 * CoreX, not settings an agency configures. (Per STANDARDS "No Silent Locks",
 * the page SAYS so and offers a way to reach us, rather than rendering dead
 * controls.)
 *
 * Gated `permission:billing.view` at the route AND re-checked here.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionPricingService $pricing,
        private readonly SubscriptionReconciler $reconciler,
    ) {
        // Middleware is applied at the route level (see routes/web.php):
        // `auth` via the wrapping group, `permission:billing.view` per route.
        // Laravel 11/12 controllers no longer expose $this->middleware().
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user?->hasPermission('billing.view'), 403);

        // Rule 17: an owner with no agency switched in has NO tenant. Rather
        // than 500 on a null agency, tell them plainly what to do.
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);

        if ($agencyId <= 0) {
            return view('billing.no-agency');
        }

        $agency = Agency::findOrFail($agencyId);

        // Reconcile BEFORE render, so the page can never show a plan that
        // disagrees with its own seat count. Idempotent and a no-op in the
        // common case (see SubscriptionReconciler).
        $this->reconciler->reconcile($agency);

        return view('billing.index', [
            'agency' => $agency,
            'quote'  => $this->pricing->quoteFor($agency),

            // The "Show details" panel. A seat count an agency cannot reconcile
            // against actual names and branches is a support ticket waiting to
            // happen — "why am I paying for 21 people?" has one good answer, and
            // it is a list. Excluded counts are shown too, so they can SEE that
            // the people they deactivated really did drop off the bill.
            'billableUsers'  => $this->pricing->billableUserRows($agency),
            'excludedUsers'  => $this->pricing->excludedUserCounts($agency),
            'branchRows'     => $this->pricing->branchRows($agency),
        ]);
    }
}
