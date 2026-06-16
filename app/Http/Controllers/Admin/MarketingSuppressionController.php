<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingSuppression;
use App\Services\SellerOutreach\MarketingConsentService;
use Illuminate\Http\Request;

/**
 * AT-49 — Admin Marketing Suppression register.
 *
 * Lists the identifier-level "suppressed everywhere" rows for the agency and
 * lets an admin lift one (an opt-in — the row is never hard-deleted, lifted_at
 * is stamped). Tenant isolation comes from MarketingSuppression's
 * BelongsToAgency global scope. Route-gated by marketing_suppressions.view /
 * .manage; controller re-checks for defence in depth.
 */
final class MarketingSuppressionController extends Controller
{
    public function __construct(
        private readonly MarketingConsentService $consent,
    ) {}

    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('marketing_suppressions.view'), 403);

        $search = trim((string) $request->input('q', ''));
        $status = $request->input('status', 'active'); // active | lifted | all

        $suppressions = MarketingSuppression::query()
            ->with(['contact:id,first_name,last_name', 'recordedBy:id,name', 'liftedBy:id,name'])
            ->when($search !== '', fn ($q) => $q->where('identifier', 'like', '%' . $search . '%'))
            ->when($status === 'active', fn ($q) => $q->whereNull('lifted_at'))
            ->when($status === 'lifted', fn ($q) => $q->whereNotNull('lifted_at'))
            ->orderByDesc('suppressed_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.marketing-suppressions.index', [
            'suppressions' => $suppressions,
            'search'       => $search,
            'status'       => $status,
        ]);
    }

    public function lift(Request $request, MarketingSuppression $suppression)
    {
        abort_unless(auth()->user()?->hasPermission('marketing_suppressions.manage'), 403);

        $this->consent->liftSuppression($suppression, auth()->id());

        return redirect()
            ->route('admin.marketing-suppressions.index', $request->only('q', 'status'))
            ->with('success', 'Suppression lifted — this identifier can receive marketing again.');
    }
}
