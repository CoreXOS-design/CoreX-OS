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
        $tab = $request->get('tab', 'overview');

        $data = [
            'buyer' => $contact->load('createdBy'),
            'tab' => $tab,
            'risk' => $service->getLostRiskScore($contact->id),
            'propertiesViewed' => $service->getPropertiesViewed($contact->id),
            'matched' => $service->getMatchedProperties($contact->id),
            'preferences' => $service->getPreferencePatterns($contact->id),
            'timeline' => $service->getActivityTimeline($contact->id),
            'playbook' => $service->getRetentionPlaybook($contact->id),
        ];

        // Stated preferences — sourced from the contact's primary ContactMatch
        // (post-unification) + preapproval block on the Contact pillar. Shaped
        // to the legacy buyer_preferences interface so the existing Preferences
        // tab Blade view continues to render until Prompt 11 replaces it with
        // the Wishlists tab.
        $data['statedPrefs'] = $this->buildStatedPrefsShim($contact);

        return view('command-center.buyers.detail', $data);
    }

    public function saveWishlist(Request $request, Contact $contact)
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
        ]);

        // Cross-field: bedrooms_max must be >= beds_min when both present (spec D4).
        $validator->after(function ($v) {
            $bedsMin = $v->getData()['beds_min'] ?? null;
            $bedsMax = $v->getData()['bedrooms_max'] ?? null;
            if ($bedsMin !== null && $bedsMax !== null && (int) $bedsMax < (int) $bedsMin) {
                $v->errors()->add('bedrooms_max', 'Maximum bedrooms cannot be less than minimum bedrooms.');
            }
        });

        $validated = $validator->validate();

        DB::transaction(function () use ($contact, $validated) {
            // 1. Preapproval block → Contact (spec D3).
            $preapprovalKeys = ['preapproval_amount', 'preapproval_expires_at', 'preapproval_institution'];
            $preapprovalUpdates = array_intersect_key($validated, array_flip($preapprovalKeys));
            if (!empty($preapprovalUpdates)) {
                $contact->update($preapprovalUpdates);
            }

            // 2. Wishlist criteria → contact's primary ContactMatch.
            //    Buyer-pipeline UI is single-wishlist for now; Prompt 11's Wishlists
            //    tab will allow multi-wishlist editing. Until then, this method
            //    operates on the primary ContactMatch.
            $match = $contact->matches()->primary()->first()
                  ?? $contact->matches()->orderByDesc('updated_at')->first();

            // Legacy property_type column mirroring (spec D2 deprecation window).
            if (isset($validated['property_types']) && !empty($validated['property_types'])) {
                $validated['property_type'] = $validated['property_types'][0] ?? null;
            }

            $matchFields = array_intersect_key($validated, array_flip([
                'listing_type', 'category', 'property_type', 'property_types',
                'suburbs', 'price_min', 'price_max', 'beds_min', 'bedrooms_max',
                'must_have_features', 'nice_to_have_features', 'deal_breakers',
                'notes', 'is_primary',
            ]));

            if (!$match) {
                $match = $contact->matches()->create(array_merge([
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

    /**
     * Build a stdClass that emulates the deprecated buyer_preferences row
     * shape so the existing Preferences tab in the view continues to render.
     * Removed in Prompt 11 along with the Preferences tab itself.
     */
    private function buildStatedPrefsShim(Contact $contact): ?object
    {
        $match = $contact->matches()->primary()->first()
              ?? $contact->matches()->orderByDesc('updated_at')->first();

        $hasPreapproval = $contact->preapproval_amount !== null
            || $contact->preapproval_expires_at !== null
            || $contact->preapproval_institution !== null;

        if (!$match && !$hasPreapproval) {
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
