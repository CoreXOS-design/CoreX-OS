<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\BuyerActivityLog;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Services\BuyerIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BuyerDetailController extends Controller
{
    public function show(Request $request, Contact $contact)
    {
        if (!$contact->is_buyer) {
            abort(404, 'This contact is not in the buyer pipeline.');
        }

        $service = app(BuyerIntelligenceService::class);
        $tab = $request->get('tab', 'wishlists');

        // Eager-load the contact's wishlists for the new tab. Sort primary
        // first so the card layout naturally puts the primary at the top.
        $contact->load('createdBy');
        $contact->setRelation(
            'matches',
            $contact->matches()->orderByDesc('is_primary')->orderByDesc('updated_at')->get()
        );

        // The match-form partial needs these collections to render its dropdowns
        // and chip options. Same source as the contact-page Core Matches tab.
        $matchCategories = \App\Models\PropertySettingItem::group('category')->get();
        $matchTypes      = \App\Models\PropertySettingItem::group('property_type')->where('active', true)->get();
        $featureOptions  = \App\Http\Controllers\CoreX\ContactMatchController::FEATURE_OPTIONS;

        return view('command-center.buyers.detail', [
            'buyer'            => $contact,
            'tab'              => $tab,
            'risk'             => $service->getLostRiskScore($contact->id),
            'propertiesViewed' => $service->getPropertiesViewed($contact->id),
            'matched'          => $service->getMatchedProperties($contact->id),
            'preferences'      => $service->getPreferencePatterns($contact->id),
            'timeline'         => $service->getActivityTimeline($contact->id),
            'playbook'         => $service->getRetentionPlaybook($contact->id),
            'matchCategories'  => $matchCategories,
            'matchTypes'       => $matchTypes,
            'featureOptions'   => $featureOptions,
        ]);
    }

    /**
     * Backward-compat alias for the legacy command-center.buyers.preferences
     * route. Now delegates to addWishlist() when the contact has no matches
     * yet, otherwise updates the existing primary wishlist. Prompt 11's
     * Wishlists tab uses the explicit add/update endpoints below.
     */
    public function saveWishlist(Request $request, Contact $contact)
    {
        $validated = $this->validateWishlistPayload($request);

        DB::transaction(function () use ($contact, $validated) {
            $this->applyPreapproval($contact, $validated);
            $match = $contact->matches()->primary()->first()
                  ?? $contact->matches()->orderByDesc('updated_at')->first();
            $matchFields = $this->extractMatchFields($validated);

            if (!$match) {
                $contact->matches()->create(array_merge([
                    'agency_id'          => $contact->agency_id,
                    'created_by_user_id' => Auth::id(),
                    'status'             => ContactMatch::STATUS_ACTIVE,
                    'is_primary'         => true,
                    'listing_type'       => $matchFields['listing_type'] ?? 'sale',
                ], $matchFields));
            } else {
                $match->update($matchFields);
            }
        });

        return back()->with('success', 'Wishlist saved.');
    }

    /**
     * Create a new ContactMatch for this contact via the buyer-pipeline UI.
     * Always creates — never updates. Use updateWishlist() for edits.
     */
    public function addWishlist(Request $request, Contact $contact)
    {
        $validated = $this->validateWishlistPayload($request);

        DB::transaction(function () use ($contact, $validated) {
            $this->applyPreapproval($contact, $validated);
            $matchFields = $this->extractMatchFields($validated);

            // Observer auto-flags is_primary=true when this is the contact's
            // first match. If is_primary=true was explicitly submitted on a
            // subsequent match, the observer's saved() handler demotes others.
            $contact->matches()->create(array_merge([
                'agency_id'          => $contact->agency_id,
                'created_by_user_id' => Auth::id(),
                'status'             => ContactMatch::STATUS_ACTIVE,
                'listing_type'       => $matchFields['listing_type'] ?? 'sale',
            ], $matchFields));
        });

        return redirect()
            ->route('command-center.buyers.show', $contact)
            ->with('success', 'Wishlist added.');
    }

    /**
     * Update an existing ContactMatch from the buyer-pipeline UI.
     */
    public function updateWishlist(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $validated = $this->validateWishlistPayload($request);

        DB::transaction(function () use ($contact, $match, $validated) {
            $this->applyPreapproval($contact, $validated);
            $match->update($this->extractMatchFields($validated));
        });

        return redirect()
            ->route('command-center.buyers.show', $contact)
            ->with('success', 'Wishlist updated.');
    }

    /**
     * Promote a wishlist to primary. ContactMatchObserver auto-demotes the
     * previous primary via its saved() handler (spec D1).
     */
    public function setWishlistPrimary(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $match->setAsPrimary();

        return redirect()
            ->route('command-center.buyers.show', $contact)
            ->with('success', 'Primary wishlist updated.');
    }

    /**
     * Archive (soft-delete) a wishlist. CoreX rule #1: no hard deletes.
     * If the archived row was the primary, the observer's deleted() handler
     * auto-promotes the next-most-recently-updated sibling.
     */
    public function archiveWishlist(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $match->delete(); // soft-delete via SoftDeletes trait

        return redirect()
            ->route('command-center.buyers.show', $contact)
            ->with('success', 'Wishlist archived.');
    }

    /* =========================================================
     |  Shared wishlist payload helpers
     * ========================================================= */

    /** @return array<string,mixed> */
    private function validateWishlistPayload(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            // Wishlist criteria → ContactMatch.
            'listing_type'              => 'nullable|in:sale,rental',
            'category'                  => 'nullable|string|max:100',
            'property_types'            => 'nullable|array',
            'property_types.*'          => 'string|max:100',
            'suburbs'                   => 'nullable|array',
            'suburbs.*'                 => 'string|max:150',
            'price_min'                 => 'nullable|integer|min:0',
            'price_max'                 => 'nullable|integer|min:0',
            'beds_min'                  => 'nullable|integer|min:0|max:20',
            'bedrooms_max'              => 'nullable|integer|min:0|max:20',
            'must_have_features'        => 'nullable|array',
            'must_have_features.*'      => 'string|max:60',
            'nice_to_have_features'     => 'nullable|array',
            'nice_to_have_features.*'   => 'string|max:60',
            'deal_breakers'             => 'nullable|array',
            'deal_breakers.*'           => 'string|max:60',
            'notes'                     => 'nullable|string|max:500',
            'is_primary'                => 'nullable|boolean',
            // Preapproval block → Contact pillar (spec D3).
            'preapproval_amount'        => 'nullable|numeric|min:0',
            'preapproval_expires_at'    => 'nullable|date',
            'preapproval_institution'   => 'nullable|string|max:100',
            'name'                      => 'nullable|string|max:120',
        ]);

        // Cross-field: bedrooms_max must be >= beds_min when both present (spec D4).
        $validator->after(function ($v) {
            $bedsMin = $v->getData()['beds_min'] ?? null;
            $bedsMax = $v->getData()['bedrooms_max'] ?? null;
            if ($bedsMin !== null && $bedsMax !== null && (int) $bedsMax < (int) $bedsMin) {
                $v->errors()->add('bedrooms_max', 'Maximum bedrooms cannot be less than minimum bedrooms.');
            }
        });

        return $validator->validate();
    }

    private function applyPreapproval(Contact $contact, array $validated): void
    {
        $keys = ['preapproval_amount', 'preapproval_expires_at', 'preapproval_institution'];
        $updates = array_intersect_key($validated, array_flip($keys));
        if (!empty($updates)) {
            $contact->update($updates);
        }
    }

    /**
     * Pluck only the ContactMatch-bound fields out of the validated payload.
     * Mirrors legacy property_type column (spec D2 deprecation window).
     *
     * @return array<string,mixed>
     */
    private function extractMatchFields(array $validated): array
    {
        if (isset($validated['property_types']) && !empty($validated['property_types'])) {
            $validated['property_type'] = $validated['property_types'][0] ?? null;
        }
        return array_intersect_key($validated, array_flip([
            'listing_type', 'category', 'property_type', 'property_types',
            'suburbs', 'price_min', 'price_max', 'beds_min', 'bedrooms_max',
            'must_have_features', 'nice_to_have_features', 'deal_breakers',
            'notes', 'is_primary', 'name',
        ]));
    }

    public function markLost(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'reason_code' => 'required|string|max:50',
            'notes' => 'nullable|string|max:2000',
            'outcome' => 'nullable|string|max:2000',
        ]);

        $reason = DB::table('agency_lost_deal_reasons')
            ->where('code', $data['reason_code'])
            ->where('agency_id', $contact->agency_id ?? 1)
            ->first();

        DB::table('buyer_lost_records')->insert([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id ?? 1,
            'reason_code' => $data['reason_code'],
            'reason_label' => $reason->label ?? $data['reason_code'],
            'notes' => $data['notes'] ?? null,
            'outcome' => $data['outcome'] ?? null,
            'recorded_by_user_id' => auth()->id(),
            'recorded_at' => now(),
            'source' => 'manual',
            'buyer_state_at_loss' => $contact->buyer_state,
            'days_in_pipeline_at_loss' => $contact->buyer_pipeline_entered_at ? (int) $contact->buyer_pipeline_entered_at->diffInDays(now()) : null,
            'days_since_last_activity_at_loss' => $contact->last_activity_at ? (int) $contact->last_activity_at->diffInDays(now()) : null,
            'agent_owner_user_id_at_loss' => $contact->created_by_user_id,
            'branch_id_at_loss' => $contact->branch_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Transition state
        app(\App\Services\BuyerStateService::class)->transitionTo($contact, 'lost', 'manual_override', auth()->id());

        return back()->with('success', 'Buyer marked as lost. Reason recorded.');
    }

    public function reengage(Request $request, Contact $contact)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:2000']);

        // Mark most recent lost record as recovered
        $lastLost = DB::table('buyer_lost_records')
            ->where('contact_id', $contact->id)
            ->whereNull('recovered_at')
            ->orderByDesc('recorded_at')
            ->first();

        if ($lastLost) {
            DB::table('buyer_lost_records')->where('id', $lastLost->id)->update([
                'recovered_at' => now(),
                'recovered_by_user_id' => auth()->id(),
                'recovered_notes' => $data['notes'] ?? null,
            ]);
        }

        app(\App\Services\BuyerStateService::class)->transitionTo($contact, 'warm', 'manual_override', auth()->id());

        BuyerActivityLog::create([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id ?? 1,
            'activity_type' => 'manual',
            'activity_date' => now(),
            'metadata' => ['action' => 'reengaged', 'notes' => $data['notes'] ?? null],
            'logged_by_user_id' => auth()->id(),
        ]);

        $contact->updateQuietly(['last_activity_at' => now()]);

        return back()->with('success', 'Buyer re-engaged. State set to Warm.');
    }

    public function markPlaybookAction(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'action_code' => 'required|string|max:50',
            'notes' => 'nullable|string|max:2000',
            'outcome' => 'nullable|string|max:50',
        ]);

        BuyerActivityLog::create([
            'contact_id' => $contact->id,
            'agency_id' => $contact->agency_id ?? 1,
            'activity_type' => 'retention_action',
            'activity_date' => now(),
            'metadata' => [
                'action_code' => $data['action_code'],
                'notes' => $data['notes'] ?? null,
                'outcome' => $data['outcome'] ?? null,
            ],
            'logged_by_user_id' => auth()->id(),
        ]);

        // Update last_activity_at
        $contact->updateQuietly(['last_activity_at' => now()]);

        return back()->with('success', 'Action recorded.');
    }

}
