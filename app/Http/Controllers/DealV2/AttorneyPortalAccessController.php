<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\AttorneyPortalAccess;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/attorney-portal.md §10 - the agent's "Send portal link" / "Resend" / "Revoke". Route middleware
 * permission:deals_v2.invite_attorney; route-model binding runs through AgencyScope, so another agency's contact or access is a 404.
 */
class AttorneyPortalAccessController extends Controller
{
    public function __construct(private AttorneyPortalAccessService $service) {}

    public function invite(Request $request, AgencyServiceProviderContact $contact): RedirectResponse
    {
        try {
            $r = $this->service->invite($contact, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['portal' => $e->getMessage()]);
        }

        $name = $r['access']->displayName();

        return back()->with('success', ($r['resent'] ? 'New portal link sent to ' : 'Portal link sent to ') . $name . ' (' . $r['access']->email . '). Any earlier link no longer works.');
    }

    public function revoke(Request $request, AttorneyPortalAccess $access): RedirectResponse
    {
        try {
            $this->service->revoke($access, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['portal' => $e->getMessage()]);
        }

        return back()->with('success', 'Portal access removed. Their link and any open page stop working straight away.');
    }

    /**
     * Choose the attorney's contact person for a deal whose firm is already chosen (the Portal access panel's "Choose the attorney
     * contact for this deal"). Sets the same deal-register column the portal scopes on. The person must be an active contact of THAT firm.
     */
    public function chooseContact(Request $request, \App\Models\Deal $deal): RedirectResponse
    {
        $data = $request->validate([
            'role'       => ['required', 'in:transfer_attorney,bond_attorney'],
            'contact_id' => ['required', 'integer'],
        ]);
        if (app(\App\Services\Deal\DealPipelineLockService::class)->isLocked($deal)) {
            return back()->withErrors(['portal' => 'This deal is not proceeding, so its attorney cannot be changed.']);
        }

        $transfer = $data['role'] === 'transfer_attorney';
        $firmId   = (int) ($transfer ? $deal->attorney_provider_id : $deal->bond_attorney_provider_id);
        $contact  = AgencyServiceProviderContact::withoutGlobalScopes()
            ->where('agency_id', $deal->agency_id)->where('service_provider_id', $firmId)->where('is_active', true)->whereNull('deleted_at')
            ->find((int) $data['contact_id']);
        if (! $firmId || ! $contact) {
            return back()->withErrors(['portal' => 'Please pick one of the contacts listed for that firm.']);
        }

        $deal->forceFill([$transfer ? 'attorney_contact_id' : 'bond_attorney_contact_id' => $contact->id])->save();

        return back()->with('success', ($contact->attorney_name ?: $contact->contact_person ?: 'The contact') . ' is now the ' . ($transfer ? 'transferring attorney' : 'bond attorney') . ' contact on this deal. You can send them the portal link now.');
    }
}
