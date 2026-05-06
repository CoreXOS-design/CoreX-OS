<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\PropertyMarketingActivity;
use App\Models\PropertySellerLink;
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

        // Record access
        $link->increment('access_count');
        $link->update(['last_accessed_at' => now()]);
        DB::table('property_seller_link_accesses')->insert([
            'link_id' => $link->id,
            'accessed_at' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->userAgent() ?? '', 0, 500),
        ]);

        $property = $link->property;
        $contact = $link->contact;
        $agency = Agency::withoutGlobalScopes()->find($property->agency_id);
        $intel = app(PropertyIntelligenceService::class);

        // Anonymise buyer names (stable hash per property)
        $feedbackRollup = $intel->getFeedbackRollup($property->id);
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
