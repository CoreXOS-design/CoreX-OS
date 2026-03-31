<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DepositTrustInterest;
use Illuminate\Http\Request;

class DepositTrustInterestController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->hasPermission('access_trust_interest'), 403);

        $records = DepositTrustInterest::defaultOrder()->paginate(24);

        return view('admin.deposit-trust-interest.index', compact('records'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('access_trust_interest'), 403);

        $data = $request->validate([
            'interest_date' => ['required', 'date', 'unique:deposit_trust_interest,interest_date'],
            'total_invested_funds' => ['required', 'numeric', 'min:0'],
            'interest_earned' => ['required', 'numeric', 'min:0'],
        ]);

        DepositTrustInterest::create($data);

        return back()->with('status', 'Trust interest record added successfully.');
    }

    public function update(Request $request, DepositTrustInterest $record)
    {
        abort_unless(auth()->user()?->hasPermission('access_trust_interest'), 403);

        $data = $request->validate([
            'interest_date' => ['required', 'date', 'unique:deposit_trust_interest,interest_date,' . $record->id],
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
