<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\BuyerActivityLog;
use App\Models\Contact;
use App\Services\BuyerIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Load stated preferences
        $data['statedPrefs'] = DB::table('buyer_preferences')->where('contact_id', $contact->id)->first();

        return view('command-center.buyers.detail', $data);
    }

    public function savePreferences(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'budget_min' => 'nullable|numeric|min:0',
            'budget_max' => 'nullable|numeric|min:0',
            'bedrooms_min' => 'nullable|integer|min:0|max:20',
            'bedrooms_max' => 'nullable|integer|min:0|max:20',
            'must_have_features' => 'nullable|array',
            'deal_breakers' => 'nullable|array',
            'preferred_areas' => 'nullable|array',
            'preferred_property_types' => 'nullable|array',
        ]);

        DB::table('buyer_preferences')->updateOrInsert(
            ['contact_id' => $contact->id],
            [
                'budget_min' => $data['budget_min'] ?? null,
                'budget_max' => $data['budget_max'] ?? null,
                'bedrooms_min' => $data['bedrooms_min'] ?? null,
                'bedrooms_max' => $data['bedrooms_max'] ?? null,
                'must_have_features' => json_encode($data['must_have_features'] ?? []),
                'deal_breakers' => json_encode($data['deal_breakers'] ?? []),
                'preferred_areas' => json_encode($data['preferred_areas'] ?? []),
                'preferred_property_types' => json_encode($data['preferred_property_types'] ?? []),
                'updated_by_user_id' => auth()->id(),
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ]
        );

        return back()->with('success', 'Preferences saved.');
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
