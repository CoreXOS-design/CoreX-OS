<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Deal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-215 (DR2) — the shared Deal Register (DR2) shell.
 *
 * DR2 is an exact rebuild of DR1 on the SAME `deals` tables (spec
 * .ai/specs/deal-register-v2-rebuild-spec.md), coexisting with DR1 behind its own
 * nav + permission. This controller is the branchable SKELETON:
 *   • index()  — the DR2 register front page (same `deals` rows DR1 shows, agency-scoped).
 *   • create()/store()/edit() — the capture surface AT-217 (cc3) builds out (the 8 §2
 *     capture enhancements + DR1-parity write to deals / deal_user / deal_settlements).
 *
 * It NEVER touches the abandoned deals-v2 module (namespace App\Http\Controllers\DealV2,
 * URI `deals-v2/*`) — that sunsets under AT-219. Permissions reuse DR1's: view_deals
 * (register), create_deals (capture/edit) — spec §5.
 */
class DealRegisterController extends Controller
{
    /** DR2 register front page — the same `deals` rows DR1 shows (agency-scoped by BelongsToAgency). */
    public function index(): View
    {
        $deals = Deal::query()
            ->with('agents')
            ->orderByDesc('deal_date')
            ->limit(50)
            ->get();

        $branches = Branch::orderBy('name')->get();

        return view('dr2.index', compact('deals', 'branches'));
    }

    /**
     * DR2 capture screen. AT-217 (cc3) builds the DR1-parity form + the §2 enhancements
     * here. The shell renders a placeholder so the route resolves from day one (NN#2).
     */
    public function create(): View
    {
        return view('dr2.create', ['deal' => null]);
    }

    /**
     * DR2 capture persist. AT-217 (cc3) implements the DR1-parity write (deals + deal_user
     * + settlement rows) plus the §2 enhancements. Shell stub keeps the POST endpoint live.
     */
    public function store(Request $request): RedirectResponse
    {
        return redirect()->route('deals-dr2.index')
            ->with('info', 'DR2 capture is being built (AT-217).');
    }

    /** DR2 edit — AT-217 (cc3) builds the edit form (DR1 parity). */
    public function edit(Deal $deal): View
    {
        return view('dr2.create', compact('deal'));
    }
}
