<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\BuyerActivityLog;
use App\Models\BuyerPropertyView;
use App\Models\Contact;
use App\Models\Property;
use App\Services\PropertyMatchScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuyerPortalController extends Controller
{
    public function show(string $token)
    {
        $link = DB::table('buyer_portal_links')->where('token', $token)->first();
        if (!$link || $link->revoked_at) {
            return response()->view('buyer-portal.revoked', [], 410);
        }

        // Record access
        DB::table('buyer_portal_links')->where('id', $link->id)->update([
            'access_count' => DB::raw('access_count + 1'),
            'last_accessed_at' => now(),
        ]);

        $contact = Contact::withoutGlobalScopes()->find($link->contact_id);
        $agency = Agency::withoutGlobalScopes()->find($contact->agency_id ?? 1);
        // Build a legacy buyer_preferences-shape shim so the existing buyer-portal
        // view (resources/views/buyer-portal/show.blade.php) continues to render
        // without changes. Source of truth is now the primary ContactMatch + the
        // Contact preapproval block. Remove this shim when the buyer-portal view
        // is refreshed (out of scope for the unification spec).
        $prefs = $this->buildLegacyPrefsShim($contact);

        // Get matches by tier
        $service = app(PropertyMatchScoringService::class);
        $matches = $service->getMatchesForBuyer($contact->id);
        $propertyIds = $matches->pluck('property_id')->toArray();
        $properties = Property::withoutGlobalScopes()->whereIn('id', $propertyIds)->get()->keyBy('id');

        // Get existing responses
        $responses = DB::table('buyer_property_responses')
            ->where('contact_id', $contact->id)
            ->pluck('response', 'property_id');

        // Viewed properties
        $viewed = BuyerPropertyView::where('contact_id', $contact->id)->with('property')->get();

        return view('buyer-portal.show', [
            'buyer' => $contact,
            'agency' => $agency,
            'prefs' => $prefs,
            'matches' => $matches,
            'properties' => $properties,
            'responses' => $responses,
            'viewed' => $viewed,
            'token' => $token,
        ]);
    }

    public function respond(Request $request, string $token)
    {
        $link = DB::table('buyer_portal_links')->where('token', $token)->first();
        if (!$link || $link->revoked_at) abort(403);

        $data = $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'response' => 'required|in:interested,not_interested,viewing_requested',
            'reason' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::table('buyer_property_responses')->insert([
            'contact_id' => $link->contact_id,
            'property_id' => $data['property_id'],
            'response' => $data['response'],
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'source' => 'buyer_portal',
            'responded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Log buyer activity
        BuyerActivityLog::create([
            'contact_id' => $link->contact_id,
            'agency_id' => Contact::withoutGlobalScopes()->where('id', $link->contact_id)->value('agency_id') ?? 1,
            'activity_type' => 'manual',
            'activity_date' => now(),
            'related_property_id' => $data['property_id'],
            'metadata' => ['portal_response' => $data['response'], 'reason' => $data['reason']],
            'logged_by_user_id' => null,
        ]);

        // Update last_activity_at
        Contact::withoutGlobalScopes()->where('id', $link->contact_id)->update(['last_activity_at' => now()]);

        return back()->with('success', 'Response recorded. Thank you!');
    }

    public function demo()
    {
        return view('buyer-portal.demo');
    }

    /**
     * Build a stdClass mirroring the deprecated buyer_preferences row shape
     * from the contact's primary ContactMatch + Contact preapproval block.
     * Keeps the existing buyer-portal Blade view working until it is itself
     * refreshed (separate future spec). Returns null when there is nothing
     * to show.
     */
    private function buildLegacyPrefsShim(Contact $contact): ?object
    {
        $match = $contact->matches()->primary()->first()
              ?? $contact->matches()->orderByDesc('updated_at')->first();
        if (!$match && !$contact->preapproval_amount && !$contact->preapproval_institution) {
            return null;
        }
        $shim = new \stdClass();
        $shim->budget_min               = $match?->price_min;
        $shim->budget_max               = $match?->price_max;
        $shim->bedrooms_min             = $match?->beds_min;
        $shim->bedrooms_max             = $match?->bedrooms_max;
        $shim->preferred_areas          = json_encode($match?->suburbs ?? []);
        $shim->preferred_property_types = json_encode($match?->propertyTypeList() ?? []);
        $shim->must_have_features       = json_encode($match?->must_have_features ?? []);
        $shim->nice_to_have_features    = json_encode($match?->nice_to_have_features ?? []);
        $shim->deal_breakers            = json_encode($match?->deal_breakers ?? []);
        $shim->notes                    = $match?->notes;
        $shim->preapproval_amount       = $contact->preapproval_amount;
        $shim->preapproval_expires_at   = $contact->preapproval_expires_at?->toDateString();
        $shim->preapproval_institution  = $contact->preapproval_institution;
        return $shim;
    }
}
