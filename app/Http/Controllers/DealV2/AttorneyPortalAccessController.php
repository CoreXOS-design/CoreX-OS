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
}
