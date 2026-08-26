<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositTrustInterest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepositTrustInterestController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->hasPermission('access_trust_interest'), 403);

        // 2026-08-15 (Johan, HFC tenant-isolation fix) — scoped "for free"
        // by DepositTrustInterest's new BelongsToAgency global scope; no
        // explicit ->where('agency_id', ...) needed here.
        $records = DepositTrustInterest::defaultOrder()->paginate(24);

        return view('admin.deposit-trust-interest.index', compact('records'));
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_trust_interest'), 403);

        // 2026-08-15 — the DB unique is now (agency_id, interest_date), not
        // a bare global interest_date — the validation rule must match, or
        // it would reject a date another agency already used even though
        // the DB itself now allows it.
        $agencyId = method_exists($user, 'effectiveAgencyId') ? $user->effectiveAgencyId() : $user->agency_id;
        $data = $request->validate([
            'interest_date' => [
                'required', 'date',
                Rule::unique('deposit_trust_interest', 'interest_date')->where('agency_id', $agencyId),
            ],
            'total_invested_funds' => ['required', 'numeric', 'min:0'],
            'interest_earned' => ['required', 'numeric', 'min:0'],
        ]);

        DepositTrustInterest::create($data);

        return back()->with('status', 'Trust interest record added successfully.');
    }

    public function update(Request $request, DepositTrustInterest $record)
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('access_trust_interest'), 403);

        $agencyId = method_exists($user, 'effectiveAgencyId') ? $user->effectiveAgencyId() : $user->agency_id;
        $data = $request->validate([
            'interest_date' => [
                'required', 'date',
                Rule::unique('deposit_trust_interest', 'interest_date')->where('agency_id', $agencyId)->ignore($record->id),
            ],
            'total_invested_funds' => ['required', 'numeric', 'min:0'],
            'interest_earned' => ['required', 'numeric', 'min:0'],
        ]);

        $record->update($data);

        return back()->with('status', 'Trust interest record updated successfully.');
    }

    public function destroy(DepositTrustInterest $record)
    {
        abort_unless(auth()->user()?->hasPermission('access_trust_interest'), 403);

        $record->delete();

        return back()->with('status', 'Trust interest record deleted successfully.');
    }
}
