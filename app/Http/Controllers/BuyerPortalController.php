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
        // Primary ContactMatch (or null). The view reads its fields directly —
        // shim retired in Prompt 11.
        $primaryMatch = $contact->matches()->primary()->first()
                     ?? $contact->matches()->orderByDesc('updated_at')->first();

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
            'buyer'       => $contact,
            'agency'      => $agency,
            'primaryMatch' => $primaryMatch,
            'matches'     => $matches,
            'properties'  => $properties,
            'responses'   => $responses,
            'viewed'      => $viewed,
            'token'       => $token,
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

        // agency_id is NOT NULL (no DB default) on buyer_property_responses. A raw
        // DB::table() insert gets NO BelongsToAgency auto-stamp, so omitting it
        // 500s every buyer response — the buyer-loop's heartbeat (AT-204; the
        // AT-202 hotfix stamped the link-generation insert but missed this one).
        // Source of truth: the link's agency_id (NOT NULL), contact as fallback.
        $agencyId = (int) ($link->agency_id
            ?? Contact::withoutGlobalScopes()->where('id', $link->contact_id)->value('agency_id')
            ?? 1);

        // Idempotent: one row per (contact, property) — a buyer changing their
        // mind updates the same row instead of stacking duplicates (BUILD_STANDARD
        // idempotency rule). Handlers preserved exactly (interested /
        // not_interested / viewing_requested).
        DB::table('buyer_property_responses')->updateOrInsert(
            [
                'contact_id'  => $link->contact_id,
                'property_id' => $data['property_id'],
            ],
            [
                'agency_id'    => $agencyId,
                'response'     => $data['response'],
                'reason'       => $data['reason'] ?? null,
                'notes'        => $data['notes'] ?? null,
                'source'       => 'buyer_portal',
                'responded_at' => now(),
                'updated_at'   => now(),
                'created_at'   => now(),
            ]
        );

        // Log buyer activity
        BuyerActivityLog::create([
            'contact_id' => $link->contact_id,
            'agency_id' => $agencyId,
            'activity_type' => 'manual',
            'activity_date' => now(),
            'related_property_id' => $data['property_id'],
            'metadata' => ['portal_response' => $data['response'], 'reason' => $data['reason'] ?? null],
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
}
