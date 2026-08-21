<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Property;
use App\Models\PropertyAuditLog;
use App\Models\PropertyMatchDecision;
use App\Models\Prospecting\PropertyTakeRequest;
use App\Models\Prospecting\TrackedProperty;
use App\Models\SuggestedActionThresholds;
use App\Models\User;
use App\Services\Prospecting\PropertyDuplicateAgeResolver;
use App\Services\Prospecting\PropertyDuplicateAgeResult;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21).
 *
 * Covers: active/draft absolute blocks, each band, each status clock, the fallback
 * chain, the agent-confirmed same/different step, agent reassignment on take
 * (recorded, never silent), and settings changes moving the band boundaries.
 *
 * WORKED TEST CASE — 47 Howard (real staging incident, this exact prompt sequence):
 * expiry_date 2025-09-01 (~355 days before "today" in this suite), status withdrawn,
 * NO status_changed audit history (exercises the fallback chain), owned by Elize,
 * captured by Johan. Asserts all three: auto-take band via the fallback date,
 * reassignment to the capturing agent, status becomes Prospecting.
 */
final class PropertyDuplicateTakeRuleTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $johan;
    private User $elize;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(5), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->johan = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin', 'name' => 'Johan Reichel']);
        $this->elize = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin', 'name' => 'Elize Reichel']);
    }

    // ──────────────────────── resolver: absolute blocks ────────────────────────

    public function test_active_property_is_always_blocked_regardless_of_age(): void
    {
        $property = $this->property(['status' => 'active', 'expiry_date' => now()->subDays(1000)]);
        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property);

        $this->assertSame(PropertyDuplicateAgeResult::BAND_ACTIVE_BLOCKED, $result->band);
        $this->assertNull($result->days);
        $this->assertNull($result->dateField);
    }

    public function test_draft_property_is_always_blocked_regardless_of_age(): void
    {
        $property = $this->property(['status' => 'draft']);
        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property);

        $this->assertSame(PropertyDuplicateAgeResult::BAND_ACTIVE_BLOCKED, $result->band);
        $this->assertNull($result->days);
    }

    // ──────────────────────── resolver: bands ────────────────────────

    public function test_no_go_band_under_x_days(): void
    {
        SuggestedActionThresholds::getOrCreateForAgency($this->agencyId); // defaults X=7, Y=14
        $property = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(3)]);

        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property);
        $this->assertSame(PropertyDuplicateAgeResult::BAND_NO_GO, $result->band);
    }

    public function test_needs_approval_band_between_x_and_y_days(): void
    {
        SuggestedActionThresholds::getOrCreateForAgency($this->agencyId);
        $property = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(10)]);

        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property);
        $this->assertSame(PropertyDuplicateAgeResult::BAND_NEEDS_APPROVAL, $result->band);
    }

    public function test_auto_take_band_at_or_over_y_days(): void
    {
        SuggestedActionThresholds::getOrCreateForAgency($this->agencyId);
        $property = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(20)]);

        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property);
        $this->assertSame(PropertyDuplicateAgeResult::BAND_AUTO_TAKE, $result->band);
    }

    // ──────────────────────── resolver: per-status clocks ────────────────────────

    public function test_expired_status_uses_expiry_date_directly_no_fallback(): void
    {
        $property = $this->property(['status' => 'expired', 'expiry_date' => now()->subDays(30)]);
        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property);

        $this->assertSame('expiry_date', $result->dateField);
        $this->assertFalse($result->isFallback);
        $this->assertSame(30, $result->days);
    }

    public function test_status_changed_clock_used_when_history_exists(): void
    {
        $property = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(400)]);
        PropertyAuditLog::create([
            'property_id' => $property->id, 'agency_id' => $this->agencyId, 'branch_id' => $property->branch_id,
            'actor_type' => 'user', 'event_category' => 'property', 'event_type' => 'status_changed',
            'created_at' => now()->subDays(25),
        ]);

        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property);
        $this->assertSame('status_changed_at', $result->dateField);
        $this->assertFalse($result->isFallback);
        $this->assertSame(25, $result->days);
    }

    public function test_fallback_chain_status_changed_missing_falls_to_expiry_date(): void
    {
        $property = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(40)]);
        // No status_changed audit row at all.

        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property);
        $this->assertSame('expiry_date', $result->dateField);
        $this->assertTrue($result->isFallback);
        $this->assertSame(40, $result->days);
    }

    public function test_fallback_chain_falls_to_created_at_when_nothing_else_exists(): void
    {
        $property = $this->property(['status' => 'withdrawn', 'expiry_date' => null]);
        DB::table('properties')->where('id', $property->id)->update(['created_at' => now()->subDays(50)]);

        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property->fresh());
        $this->assertSame('created_at', $result->dateField);
        $this->assertTrue($result->isFallback);
        $this->assertSame(50, $result->days);
    }

    // ──────────────────────── settings move the band boundaries ────────────────────────

    public function test_settings_changes_move_band_boundaries(): void
    {
        app(ProspectingConfigurationService::class)->updateSuggestedActionThresholds($this->agencyId, [
            'deeds_duplicate_no_go_days' => 20,
            'deeds_duplicate_auto_take_days' => 40,
        ]);

        // 10 days: under the OLD default X(7) would have been no_go->needs_approval
        // boundary territory, but under the NEW X=20 it's still no_go.
        $property = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(10)]);
        $result = app(PropertyDuplicateAgeResolver::class)->resolve($property);
        $this->assertSame(PropertyDuplicateAgeResult::BAND_NO_GO, $result->band, 'moved X must still block a 10-day-old match');

        $property2 = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(30)]);
        $result2 = app(PropertyDuplicateAgeResolver::class)->resolve($property2);
        $this->assertSame(PropertyDuplicateAgeResult::BAND_NEEDS_APPROVAL, $result2->band, '30 days sits between the moved X(20) and Y(40)');

        $property3 = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(45)]);
        $result3 = app(PropertyDuplicateAgeResolver::class)->resolve($property3);
        $this->assertSame(PropertyDuplicateAgeResult::BAND_AUTO_TAKE, $result3->band, 'moved Y(40) must be honoured');
    }

    public function test_settings_reject_auto_take_days_less_than_no_go_days(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ProspectingConfigurationService::class)->updateSuggestedActionThresholds($this->agencyId, [
            'deeds_duplicate_no_go_days' => 20,
            'deeds_duplicate_auto_take_days' => 5,
        ]);
    }

    // ──────────────────────── controller flow: same / different confirmation ────────────────────────

    public function test_different_property_forces_a_fresh_create_and_rejects_the_match(): void
    {
        $existing = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(30), 'agent_id' => $this->elize->id]);
        $tp = $this->trackedPropertyMatching($existing);

        $this->actingAs($this->johan)->post(route('corex.deeds-capture.promote', $tp->id), [
            'match_decision' => 'different',
            'reject_reason_code' => 'different_erf',
        ])->assertRedirect(route('corex.deeds-capture.index'));

        $tp->refresh();
        $this->assertNotNull($tp->promoted_to_property_id);
        $this->assertNotSame($existing->id, $tp->promoted_to_property_id, 'a fresh property must be created, not the existing match');

        $created = Property::withoutGlobalScopes()->find($tp->promoted_to_property_id);
        $this->assertSame(Property::STATUS_PROSPECTING, $created->status);
        $this->assertSame($this->johan->id, $created->agent_id);

        $existing->refresh();
        $this->assertSame('withdrawn', $existing->status, 'the rejected match must be left completely untouched');
        $this->assertSame($this->elize->id, $existing->agent_id);

        $decision = PropertyMatchDecision::where('subject_key', 'deeds_capture_property:' . $tp->id)->first();
        $this->assertNotNull($decision);
        $this->assertTrue($decision->isRejected());
        $this->assertSame('different_erf', $decision->reject_reason_code);
        $this->assertSame('created_new', $decision->outcome);
    }

    public function test_same_property_in_auto_take_band_reassigns_and_confirms_decision(): void
    {
        $existing = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(30), 'agent_id' => $this->elize->id]);
        $tp = $this->trackedPropertyMatching($existing);

        $this->actingAs($this->johan)->post(route('corex.deeds-capture.promote', $tp->id), [
            'match_decision' => 'same',
        ])->assertRedirect();

        $existing->refresh();
        $this->assertSame(Property::STATUS_PROSPECTING, $existing->status);
        $this->assertSame($this->johan->id, $existing->agent_id);

        $decision = PropertyMatchDecision::where('subject_key', 'deeds_capture_property:' . $tp->id)->first();
        $this->assertTrue($decision->isConfirmed());
        $this->assertSame('took_existing', $decision->outcome);
    }

    public function test_same_property_in_no_go_band_is_blocked_and_leaves_property_untouched(): void
    {
        $existing = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(3), 'agent_id' => $this->elize->id]);
        $tp = $this->trackedPropertyMatching($existing);

        $response = $this->actingAs($this->johan)->post(route('corex.deeds-capture.promote', $tp->id), [
            'match_decision' => 'same',
        ]);
        $response->assertRedirect(route('corex.deeds-capture.index'));
        $this->assertTrue(session()->has('error'));

        $existing->refresh();
        $this->assertSame('withdrawn', $existing->status);
        $this->assertSame($this->elize->id, $existing->agent_id);
        $tp->refresh();
        $this->assertNull($tp->promoted_to_property_id);

        $decision = PropertyMatchDecision::where('subject_key', 'deeds_capture_property:' . $tp->id)->first();
        $this->assertTrue($decision->isConfirmed());
        $this->assertSame('blocked', $decision->outcome);
    }

    public function test_same_property_active_is_blocked_absolutely(): void
    {
        $existing = $this->property(['status' => 'active', 'agent_id' => $this->elize->id]);
        $tp = $this->trackedPropertyMatching($existing);

        $this->actingAs($this->johan)->post(route('corex.deeds-capture.promote', $tp->id), [
            'match_decision' => 'same',
        ])->assertRedirect(route('corex.deeds-capture.index'));

        $existing->refresh();
        $this->assertSame('active', $existing->status);
        $this->assertSame($this->elize->id, $existing->agent_id);
    }

    public function test_same_property_in_approval_band_files_a_pending_request_and_does_not_reassign(): void
    {
        $existing = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(10), 'agent_id' => $this->elize->id]);
        $tp = $this->trackedPropertyMatching($existing);

        $this->actingAs($this->johan)->post(route('corex.deeds-capture.promote', $tp->id), [
            'match_decision' => 'same',
        ])->assertRedirect(route('corex.deeds-capture.index'));

        $existing->refresh();
        $this->assertSame('withdrawn', $existing->status, 'nothing is promoted/reassigned until an admin decides');
        $this->assertSame($this->elize->id, $existing->agent_id);

        $request = PropertyTakeRequest::where('tracked_property_id', $tp->id)->first();
        $this->assertNotNull($request);
        $this->assertTrue($request->isPending());
        $this->assertSame($this->johan->id, $request->requested_by_user_id);

        // Approving now performs the recorded reassignment.
        $this->actingAs($this->elize) // acting as an admin approver
            ->post(route('corex.property-take-requests.approve', $request->id))
            ->assertRedirect();

        $existing->refresh();
        $this->assertSame(Property::STATUS_PROSPECTING, $existing->status);
        $this->assertSame($this->johan->id, $existing->agent_id, 'the REQUESTING agent takes it, not the approver');
    }

    // ──────────────────────── reassignment is logged, never silent ────────────────────────

    public function test_reassignment_is_recorded_on_property_audit_log(): void
    {
        $existing = $this->property(['status' => 'withdrawn', 'expiry_date' => now()->subDays(30), 'agent_id' => $this->elize->id]);
        $tp = $this->trackedPropertyMatching($existing);

        $this->actingAs($this->johan)->post(route('corex.deeds-capture.promote', $tp->id), [
            'match_decision' => 'same',
        ]);

        $log = PropertyAuditLog::where('property_id', $existing->id)
            ->where('event_type', 'deeds_duplicate_reassigned')->first();
        $this->assertNotNull($log, 'the reassignment must be on the SAME property history the agent would open');
        $this->assertSame($this->johan->id, $log->user_id);
        $this->assertSame($this->elize->id, $log->old_values['agent_id']);
        $this->assertSame($this->johan->id, $log->new_values['agent_id']);
        $this->assertSame('auto_take', $log->metadata['band']);
    }

    // ──────────────────────── the worked test case: 47 Howard ────────────────────────

    public function test_47_howard_lands_auto_take_via_fallback_and_reassigns_to_capturing_agent(): void
    {
        $howard = $this->property([
            'address' => '47 Howard Avenue', 'street_number' => '47', 'street_name' => 'Howard Avenue',
            'suburb' => 'Trafalgar', 'erf_number' => '217',
            'status' => 'withdrawn',
            'expiry_date' => \Illuminate\Support\Carbon::parse('2025-09-01'),
            'agent_id' => $this->elize->id,
        ]);
        // 47 Howard has NO status_changed audit history — arrived pre-dead via bulk import.
        $this->assertSame(0, PropertyAuditLog::where('property_id', $howard->id)->where('event_type', 'status_changed')->count());

        $tp = $this->trackedPropertyMatching($howard);

        $response = $this->actingAs($this->johan)->post(route('corex.deeds-capture.promote', $tp->id), [
            'match_decision' => 'same',
        ]);
        $response->assertRedirect();

        $howard->refresh();

        // 1. Lands in the automatic band (well past Y=14 default days).
        $decision = PropertyMatchDecision::where('subject_key', 'deeds_capture_property:' . $tp->id)->first();
        $this->assertSame('took_existing', $decision->outcome);

        // 2. Exercises the fallback chain (no status_changed history → expiry_date, not the primary signal).
        $log = PropertyAuditLog::where('property_id', $howard->id)->where('event_type', 'deeds_duplicate_reassigned')->first();
        $this->assertSame('expiry_date', $log->metadata['date_field_used']);
        $this->assertTrue($log->metadata['date_is_fallback']);

        // 3. Reassignment: from Elize to the capturing agent (Johan), Prospecting.
        $this->assertSame(Property::STATUS_PROSPECTING, $howard->status);
        $this->assertSame($this->johan->id, $howard->agent_id);
        $this->assertNotSame($this->elize->id, $howard->agent_id);
    }

    // ──────────────────────── helpers ──────────────────────────────────────────

    private function property(array $attrs): Property
    {
        return Property::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->johan->id,
            'address' => 'Test Street', 'suburb' => 'Margate', 'property_type' => 'house',
            'street_number' => '1', 'street_name' => 'Test Street', 'erf_number' => 'ERF-' . Str::random(8),
            'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1_000_000, 'title' => 'Test Property',
            'listing_type' => 'sale',
        ], $attrs));
    }

    /** A TrackedProperty carrying the SAME erf+suburb as $property, so resolvePropertyMatch() matches it. */
    private function trackedPropertyMatching(Property $property): TrackedProperty
    {
        return TrackedProperty::create([
            'agency_id' => $this->agencyId,
            'street_number' => $property->street_number,
            'street_name' => $property->street_name,
            'suburb' => $property->suburb,
            'erf_number' => $property->erf_number,
            'capture_kind' => 'deeds_capture',
            'deeds_captured_at' => now(),
            'deeds_captured_by_user_id' => $this->johan->id,
            'source_chain' => [['type' => 'deeds_capture', 'ref' => 'test:' . Str::random(8), 'date' => now()->toIso8601String()]],
        ]);
    }
}
