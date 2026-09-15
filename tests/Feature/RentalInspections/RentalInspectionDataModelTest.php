<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 1 (data model) verification for .ai/specs/rental-inspections.md.
 * Every test exercises the real model/DB layer directly — no controller
 * exists yet (stage 3). Covers the §11 acceptance criteria this stage can
 * actually prove.
 */
final class RentalInspectionDataModelTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'RI Test Agency', 'slug' => 'ri-test-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id,
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'title' => 'RI Test Property',
            'status' => 'active',
            'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 12000,
            'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeItem(string $label = 'Bedroom 1'): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id,
            'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE,
            'label' => $label,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(string $type = RentalInspection::TYPE_IN): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id,
            'lease_id' => $this->lease->id,
            'type' => $type,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeObservation(RentalInspection $inspection, RentalInspectionItem $item, string $condition): RentalInspectionObservation
    {
        $observation = RentalInspectionObservation::create([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id,
            'condition' => $condition,
            'notes' => $condition !== RentalInspectionObservation::CONDITION_GOOD ? 'Test note' : null,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);

        RentalInspectionDiscrepancy::detectFor($observation);

        return $observation;
    }

    public function test_inspection_denormalizes_property_id_from_lease_at_creation(): void
    {
        $inspection = $this->makeInspection();

        $this->assertSame($this->property->id, $inspection->property_id);
    }

    public function test_two_conflicting_observations_produce_exactly_one_discrepancy_referencing_both(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $obs1 = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $obs2 = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);

        $this->assertSame(1, RentalInspectionDiscrepancy::count());
        $discrepancy = RentalInspectionDiscrepancy::first();
        $this->assertNull($discrepancy->resolved_at);
        $this->assertEqualsCanonicalizing(
            [$obs1->id, $obs2->id],
            $discrepancy->observations()->pluck('rental_inspection_observations.id')->all(),
        );
    }

    public function test_a_third_conflicting_observation_joins_the_same_discrepancy_not_a_new_one(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();

        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_FAIR);

        $this->assertSame(1, RentalInspectionDiscrepancy::count(), 'one row per conflicting GROUP, not per pair');
        $this->assertSame(3, RentalInspectionDiscrepancy::first()->observations()->count());
    }

    public function test_matching_observations_across_different_inspections_do_not_conflict(): void
    {
        $item = $this->makeItem();
        $inIns = $this->makeInspection(RentalInspection::TYPE_IN);
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->makeObservation($inIns, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($outIns, $item, RentalInspectionObservation::CONDITION_DAMAGED);

        $this->assertSame(0, RentalInspectionDiscrepancy::count(), 'ordinary wear between check-in and check-out is not a discrepancy');
    }

    public function test_inspection_cannot_be_treated_as_completable_while_discrepancy_unresolved(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);

        $this->assertTrue($inspection->hasUnresolvedDiscrepancy());

        $discrepancy = RentalInspectionDiscrepancy::first();
        $winner = $discrepancy->observations()->orderByDesc('rental_inspection_observations.id')->first();
        $discrepancy->resolve($winner, $this->agent, 'Confirmed damaged on recheck.');

        $inspection->refresh();
        $this->assertFalse($inspection->fresh()->hasUnresolvedDiscrepancy());
    }

    public function test_resolving_a_discrepancy_never_touches_the_losing_observation(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $loser = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $winner = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);

        $discrepancy = RentalInspectionDiscrepancy::first();
        $discrepancy->resolve($winner, $this->agent, 'Agreed.');

        $this->assertDatabaseHas('rental_inspection_observations', ['id' => $loser->id, 'condition' => 'good']);
        $this->assertSame(2, $discrepancy->observations()->count(), 'losing observation stays on the pivot, never removed');
    }

    public function test_current_observation_excludes_unresolved_discrepancy_participants(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);

        $this->assertNull($item->fresh()->currentObservation(), 'both sit inside an unresolved discrepancy — no current condition yet');

        $discrepancy = RentalInspectionDiscrepancy::first();
        $winner = $discrepancy->observations()->orderByDesc('rental_inspection_observations.id')->first();
        $discrepancy->resolve($winner, $this->agent, 'Resolved.');

        $current = $item->fresh()->currentObservation();
        $this->assertNotNull($current);
        $this->assertSame($winner->id, $current->id);
    }

    public function test_retiring_an_item_does_not_hide_its_observation_history(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);

        $item->update(['is_retired' => true]);

        $this->assertSame(1, $item->fresh()->fullHistory()->count(), 'is_retired only blocks NEW observations, never hides history');
    }

    public function test_client_idempotency_key_prevents_a_retried_sync_from_double_recording(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection();
        $key = (string) \Illuminate\Support\Str::uuid();

        RentalInspectionObservation::create([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id,
            'condition' => RentalInspectionObservation::CONDITION_GOOD,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            'client_idempotency_key' => $key,
        ]);

        $this->expectException(QueryException::class);
        RentalInspectionObservation::create([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id,
            'condition' => RentalInspectionObservation::CONDITION_GOOD,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            'client_idempotency_key' => $key,
        ]);
    }

    public function test_settings_default_to_seven_days_when_no_row_exists(): void
    {
        $this->assertSame(7, RentalInspectionSetting::faultReportWindowDaysFor($this->agency->id));
        $this->assertSame(7, RentalInspectionSetting::signingWindowDaysFor($this->agency->id));
    }

    public function test_settings_row_overrides_the_default(): void
    {
        RentalInspectionSetting::create([
            'agency_id' => $this->agency->id,
            'fault_report_window_days' => 14,
        ]);

        $this->assertSame(14, RentalInspectionSetting::faultReportWindowDaysFor($this->agency->id));
        $this->assertSame(7, RentalInspectionSetting::signingWindowDaysFor($this->agency->id), 'unset column still falls back to the default');
    }

    public function test_signature_refusal_note_must_contain_the_exact_required_phrase(): void
    {
        $this->assertFalse(RentalInspectionSignature::refusalNoteIsValid('Tenant did not respond.'));
        $this->assertTrue(RentalInspectionSignature::refusalNoteIsValid('Called three times — tenant refused to sign out inspection.'));
    }

    public function test_agency_scoping_hides_another_agencys_inspection(): void
    {
        // BelongsToAgency force-stamps agency_id from the acting user on create
        // (an ordinary user can never spoof another tenant's agency_id) — that
        // includes User itself, so building the "other agency" fixtures while
        // still acting as $this->agent would silently stamp $otherAgent (and
        // everything created under it) onto THIS agency instead. Log out first:
        // with no authenticated user, an explicit agency_id is trusted verbatim.
        \Illuminate\Support\Facades\Auth::logout();

        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAgent = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'agent']);
        $otherProperty = Property::forceCreate([
            'agency_id' => $otherAgency->id, 'agent_id' => $otherAgent->id, 'branch_id' => $otherBranch->id,
            'title' => 'Other property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now(), 'created_by_user_id' => $otherAgent->id,
        ]);
        RentalInspection::create(['agency_id' => $otherAgency->id, 'lease_id' => $otherLease->id, 'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $otherAgent->id]);

        $this->actingAs($this->agent);
        $this->makeInspection();

        $this->assertSame(1, RentalInspection::count(), 'AgencyScope must exclude the other agency\'s inspection for the acting user');
    }
}
