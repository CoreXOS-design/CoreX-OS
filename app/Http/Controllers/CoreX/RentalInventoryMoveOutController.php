<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\RentalInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Audit 2026-10-19 B5 - the MOVE-OUT inventory check had findings but no end: nothing completed it, nobody signed it off, and nothing read it.
 * Completing it needs a finding on every line ("present" counts); signing it off is one row per party (tenants, landlord, agent) - signed with
 * a typed name, or refused with a reason. No money anywhere: the deposit is settled in the agency's accounting system.
 *
 * Scoping: the bound inventory through the shared own/branch/agency guard on every action; the parties are read from the inventory's own lease,
 * never trusted from the request.
 */
class RentalInventoryMoveOutController extends Controller
{
    use EnforcesRecordVisibility;

    public function complete(Request $request, RentalInventory $rentalInventory): RedirectResponse
    {
        $this->assertVisible($request, $rentalInventory);

        try {
            $rentalInventory->completeMoveOut($request->user());
        } catch (\LogicException $e) {
            return redirect()->route('corex.rental-inventories.comparison', $rentalInventory)->withErrors(['move_out' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-inventories.comparison', $rentalInventory)->with('success', 'Move-out check completed. Now have it signed off.');
    }

    public function signOff(Request $request, RentalInventory $rentalInventory): RedirectResponse
    {
        $this->assertVisible($request, $rentalInventory);

        $validated = $request->validate([
            'party_role' => ['required', 'in:tenant,landlord,agent'],
            'party_contact_id' => ['nullable', 'integer'],
            'disposition' => ['required', 'in:signed,refused'],
            'signed_name' => ['required', 'string', 'max:191'],
            'refusal_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $rentalInventory->recordMoveOutSignOff(
                $validated['party_role'],
                isset($validated['party_contact_id']) ? (int) $validated['party_contact_id'] : null,
                $validated['disposition'],
                $validated['signed_name'],
                $validated['refusal_reason'] ?? null,
                $request->user(),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (\LogicException|\InvalidArgumentException $e) {
            return redirect()->route('corex.rental-inventories.comparison', $rentalInventory)->withErrors(['move_out' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-inventories.comparison', $rentalInventory)->with('success', 'Sign-off recorded.');
    }
}
