<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\DealV2\DealV2;
use App\Services\DealV2\DealDistributionService;
use Illuminate\Http\Request;

/**
 * AT-158 DR2 · WS4 (§8.3) — the "Distribute documents" action on a deal.
 */
class DealDistributionController extends Controller
{
    public function __construct(private DealDistributionService $distribution)
    {
    }

    /** The modal plan: who-gets-what-by-which-mode, resolved from the matrix + the deal's parties. */
    public function plan(DealV2 $deal)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.distribute_documents'), 403);

        return response()->json([
            'deal'  => ['id' => $deal->id, 'reference' => $deal->reference],
            'plan'  => $this->distribution->resolvePlan($deal, auth()->user()),
        ]);
    }

    /** Send the confirmed rules. */
    public function send(Request $request, DealV2 $deal)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.distribute_documents'), 403);

        $data = $request->validate([
            'rule_ids'   => ['required', 'array', 'min:1'],
            'rule_ids.*' => ['integer'],
        ]);

        $created = $this->distribution->distributeRules($deal, $data['rule_ids'], auth()->user());

        $n = count($created);
        return redirect()->route('deals-v2.show', $deal->id)
            ->with('status', $n > 0
                ? "Sent {$n} document" . ($n === 1 ? '' : 's') . '.'
                : 'Nothing to send — no eligible recipient or document for the selected rules.');
    }

    /** Revoke a secure-link distribution. */
    public function revoke(Request $request, DealDocumentDistribution $distribution)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.distribute_documents'), 403);

        $this->distribution->revoke($distribution, auth()->user(), $request->ip(), $request->userAgent());

        return redirect()->route('deals-v2.show', $distribution->deal_id)
            ->with('status', 'Secure link revoked.');
    }
}
