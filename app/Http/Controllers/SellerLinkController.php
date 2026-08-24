<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Property;
use App\Models\PropertyMarketingActivity;
use App\Models\PropertySellerLink;
use App\Services\Leads\SharedLinkReengagementService;
use App\Services\PropertyIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerLinkController extends Controller
{
    /**
     * Public endpoint: render seller live page for a valid token.
     */
    public function show(string $token)
    {
        $link = PropertySellerLink::where('token', $token)->first();

        if (!$link || $link->revoked_at) {
            return response()->view('seller-link.revoked', [], 410);
        }

        $property = $link->property;

        // 2026-08-24 (Johan) — a soft-deleted property used to crash here:
        // getFeedbackRollup(int $propertyId, ...) is strictly typed and
        // $property->id on a null $property throws, uncaught, 500. And a
        // CONCLUDED property (sold/transferred/rented/let_out — the single
        // source of truth is Property::isConcluded(), never a raw status
        // string compare) used to render as if still live: no error, but a
        // wrong page — a seller or buyer holding the link sees a listing
        // that looks current for a property that is gone. Worse than a
        // crash, because nothing tells them. Both now get the same
        // courteous treatment SharedMatchController::showExpired() and
        // PublicPresentationController::renderUnavailable() already use —
        // copy that pattern, not a third dialect of it.
        if (!$property) {
            return $this->showUnavailable($link, null, 'deleted');
        }
        if ($property->isConcluded()) {
            return $this->showUnavailable($link, $property, 'sold');
        }

        // Record access. This is a PUBLIC page — a failure to log the visit must
        // never 500 the seller out of their own report. Stamp agency_id from the
        // link's pillar (property_seller_link_accesses.agency_id is NOT NULL and
        // this is a raw insert, so BelongsToAgency's auto-stamp does not apply).
        try {
            $accessAgencyId = $link->agency_id ?: ($property?->agency_id);
            $link->increment('access_count');
            $link->update(['last_accessed_at' => now()]);
            if ($accessAgencyId) {
                DB::table('property_seller_link_accesses')->insert([
                    'link_id' => $link->id,
                    'agency_id' => $accessAgencyId,
                    'accessed_at' => now(),
                    'ip_address' => request()->ip(),
                    'user_agent' => substr(request()->userAgent() ?? '', 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $contact = $link->contact;
        $agency = Agency::withoutGlobalScopes()->find($property->agency_id);
        $intel = app(PropertyIntelligenceService::class);

        // Anonymise buyer names (stable hash per property)
        $feedbackRollup = $intel->getFeedbackRollup($property->id, excludeInternalOnly: true);
        $compliance = $intel->getComplianceStatus($property->id);
        $presentations = $intel->getPresentations($property->id, sellerView: true);
        $marketPosition = $intel->getLatestMarketPosition($property->id);
        $comparables = $intel->getComparableListings($property->id);
        $recommendations = DB::table('property_recommendations')
            ->where('property_id', $property->id)
            ->where('seller_visible', true)
            ->whereNull('dismissed_at')
            ->whereNull('actioned_at')
            ->whereNotNull('seller_facing_title')
            ->orderByDesc('generated_at')
            ->get();
        $marketing = PropertyMarketingActivity::where('property_id', $property->id)
            ->sellerVisible()
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get();

        return view('seller-link.live', [
            'property' => $property,
            'seller' => $contact,
            'agency' => $agency,
            'feedbackRollup' => $feedbackRollup,
            'compliance' => $compliance,
            'presentations' => $presentations,
            'marketPosition' => $marketPosition,
            'comparables' => $comparables,
            'recommendations' => $recommendations,
            'marketing' => $marketing,
            'link' => $link,
        ]);
    }

    /**
     * VALID token, resource dead — either the property was soft-deleted
     * ($property is null) or it's CONCLUDED (sold/transferred/rented/let_out).
     * Same shape as SharedMatchController::showExpired(): resolve the agency
     * from whatever's still resolvable, show the seller's current agent only
     * if they're actually still live (is_active + not deleted), otherwise
     * fall through to the agency's own contact details via the SAME shared
     * fallback service every other public link in this codebase already
     * uses — not a new resolver.
     *
     * A sold property is, commercially, a live buyer lead standing on the
     * page — not just an error case — so the 'sold' reason gets its own
     * "similar properties" call to action the 'deleted' reason doesn't
     * (there's no agency-marketable property left to point at).
     */
    private function showUnavailable(PropertySellerLink $link, ?Property $property, string $reason)
    {
        $agencyId = $property?->agency_id ?? $link->agency_id;
        $agency = $agencyId ? Agency::withoutGlobalScopes()->find($agencyId) : null;

        $contact = $link->contact;
        $agentService = app(SharedLinkReengagementService::class);
        $currentAgent = $contact?->agent;
        $showAgent = $currentAgent && $currentAgent->is_active && $currentAgent->deleted_at === null;
        $fallbackContact = $agency ? $agentService->agencyFallbackContact($agency) : ['phone' => null, 'email' => null];

        return response()->view('seller-link.unavailable', [
            'reason'        => $reason,
            'property'      => $property,
            'agency'        => $agency,
            'agent'         => $showAgent ? $currentAgent : null,
            'fallbackPhone' => $fallbackContact['phone'],
            'fallbackEmail' => $fallbackContact['email'],
        ], 410);
    }

    /**
     * Agent: generate a new seller link for a property + contact.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'contact_id' => 'required|integer|exists:contacts,id',
        ]);

        // Revoke any existing active link for this property + contact
        PropertySellerLink::where('property_id', $request->property_id)
            ->where('contact_id', $request->contact_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => auth()->id()]);

        $link = PropertySellerLink::create([
            'property_id' => $request->property_id,
            'contact_id' => $request->contact_id,
            'token' => PropertySellerLink::generateToken(),
            'generated_by_user_id' => auth()->id(),
            'generated_at' => now(),
        ]);

        $url = url('/property/live/' . $link->token);

        if ($request->wantsJson()) {
            return response()->json(['url' => $url, 'link_id' => $link->id]);
        }

        return back()->with('success', 'Seller link generated.')->with('seller_link_url', $url);
    }

    /**
     * Agent: revoke a seller link.
     */
    public function revoke(Request $request, PropertySellerLink $link)
    {
        $link->update(['revoked_at' => now(), 'revoked_by_user_id' => auth()->id()]);

        return back()->with('success', 'Seller link revoked.');
    }

    /**
     * Demo endpoint: public, shows sample live page.
     */
    public function demo()
    {
        return view('seller-link.demo');
    }
}
