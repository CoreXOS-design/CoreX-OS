<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\User;
use App\Services\RentalInspectionComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspection-form.md §7 — the in-vs-out comparison. This
 * proves the CLASSIFICATION and matching mechanics only — no test here
 * asserts anything about an amount or a deduction, because nothing in the
 * built code computes one (see the service's own docblock for why).
 */
final class RentalInspectionComparisonServiceTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Property $property;
    private User $agent;
    private Lease $lease;
    private RentalInspectionComparisonService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Test Agency', 'slug' => 'test-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'HQ']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Test Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'email' => 'thabo@example.co.za',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => now()->subMonths(6),
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $contact->id]);

        $this->service = new RentalInspectionComparisonService();
    }

    private function inInspection(): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function outInspection(): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_OUT, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function item(string $label = 'Bedroom 1'): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function observe(RentalInspection $inspection, RentalInspectionItem $item, string $condition, ?string $notes = null): RentalInspectionObservation
    {
        return RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id, 'observed_by_user_id' => $this->agent->id,
            'condition' => $condition, 'notes' => $notes,
        ]);
    }

    public function test_same_condition_on_both_sides_is_unchanged(): void
    {
        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'good');

        $rows = $this->service->compareItems($out);

        $this->assertCount(1, $rows);
        $this->assertSame(RentalInspectionComparisonService::UNCHANGED, $rows->first()['classification']);
    }

    public function test_moving_to_good_is_improved(): void
    {
        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'fair', 'Scuffed on move-in.');
        $this->observe($out, $item, 'good');

        $rows = $this->service->compareItems($out);

        $this->assertSame(RentalInspectionComparisonService::IMPROVED, $rows->first()['classification']);
    }

    public function test_moving_away_from_good_is_declined(): void
    {
        $item = $this->item('Kitchen');
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'damaged', 'Stove top plates stain marks.');

        $rows = $this->service->compareItems($out);

        $row = $rows->first();
        $this->assertSame(RentalInspectionComparisonService::DECLINED, $row['classification']);
        $this->assertSame('Kitchen', $row['item']->label);
        $this->assertSame('good', $row['in_observation']->condition);
        $this->assertSame('damaged', $row['out_observation']->condition);
        $this->assertSame('Stove top plates stain marks.', $row['out_observation']->notes);
    }

    public function test_changing_between_two_non_good_values_is_still_declined_not_silently_dropped(): void
    {
        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'damaged');
        $this->observe($out, $item, 'missing');

        $rows = $this->service->compareItems($out);

        $this->assertSame(RentalInspectionComparisonService::DECLINED, $rows->first()['classification']);
    }

    /**
     * N/A doesn't exist in the condition enum yet (cc2's own work, in
     * flight). This proves the whitelist-based exclusion works for ANY
     * value outside the six real conditions — including whatever cc2's N/A
     * ends up being called — without this test (or the service) needing to
     * know its exact name.
     */
    public function test_a_condition_value_outside_the_known_enum_on_both_sides_is_excluded_entirely(): void
    {
        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'not_applicable');
        $this->observe($out, $item, 'not_applicable');

        $rows = $this->service->compareItems($out);

        $this->assertCount(0, $rows, 'An item N/A at both ends must not appear as a finding at all.');
    }

    public function test_na_on_only_one_side_is_flagged_as_a_mismatch_not_silently_matched(): void
    {
        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'not_applicable');
        $this->observe($out, $item, 'good');

        $rows = $this->service->compareItems($out);

        $this->assertSame(RentalInspectionComparisonService::NA_MISMATCH, $rows->first()['classification']);
    }

    public function test_an_item_only_observed_at_move_in_is_flagged_only_at_in(): void
    {
        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'good');
        // No out-inspection observation for this item at all.

        $rows = $this->service->compareItems($out);

        $this->assertSame(RentalInspectionComparisonService::ONLY_AT_IN, $rows->first()['classification']);
    }

    public function test_an_item_only_observed_at_move_out_is_flagged_only_at_out(): void
    {
        // This is the "agent added a new item on site" / "two agents made
        // two different item rows for the same room" case named in
        // rental-inspection-form.md §7 — an item that genuinely has no
        // in-inspection counterpart at all.
        $item = $this->item('Newly discovered storeroom');
        $out = $this->outInspection();
        $this->observe($out, $item, 'fair');

        $rows = $this->service->compareItems($out);

        $this->assertSame(RentalInspectionComparisonService::ONLY_AT_OUT, $rows->first()['classification']);
    }

    public function test_with_no_in_inspection_at_all_every_out_item_is_only_at_out(): void
    {
        $item = $this->item();
        $out = $this->outInspection();
        $this->observe($out, $item, 'good');

        $this->assertNull($this->service->matchingInInspection($out));
        $rows = $this->service->compareItems($out);
        $this->assertSame(RentalInspectionComparisonService::ONLY_AT_OUT, $rows->first()['classification']);
    }

    public function test_recording_a_wear_and_tear_finding_on_a_declined_item_persists_it(): void
    {
        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'fair', 'Rust wear and tear.');

        $finding = $this->service->recordFinding($out, $item, 'wear_and_tear', 'Cupboards show fair wear and tear, not damage.', $this->agent);

        $this->assertSame('wear_and_tear', $finding->disposition);
        $this->assertNull($finding->superseded_at);

        $rows = $this->service->compareItems($out);
        $this->assertSame($finding->id, $rows->first()['finding']->id);
    }

    public function test_recording_a_second_finding_supersedes_the_first_never_edits_it(): void
    {
        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'damaged');

        $first = $this->service->recordFinding($out, $item, 'wear_and_tear', 'Initial call — wear and tear.', $this->agent);
        $second = $this->service->recordFinding($out, $item, 'flagged', 'Reconsidered — this is real damage.', $this->agent);

        $first->refresh();
        $this->assertNotNull($first->superseded_at);
        $this->assertSame($second->id, $first->superseded_by_finding_id);
        $this->assertNull($second->superseded_at);

        $rows = $this->service->compareItems($out);
        $this->assertSame($second->id, $rows->first()['finding']->id, 'Only the live finding should be returned.');
    }

    public function test_recording_a_finding_on_a_non_declined_item_is_refused(): void
    {
        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'good'); // unchanged — nothing to judge

        $this->expectException(\LogicException::class);
        $this->service->recordFinding($out, $item, 'wear_and_tear', 'Trying to mark an unchanged item.', $this->agent);
    }

    public function test_the_comparison_endpoint_shows_both_sides_evidence_and_the_wear_and_tear_form(): void
    {
        \DB::table('role_permissions')->insert([
            ['role' => 'admin', 'permission_key' => 'rental_inspections.view', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'rental_inspections.review_deposit_comparison', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $item = $this->item('Kitchen');
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'damaged', 'Stove top plates stain marks.');

        $response = $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $out));

        $response->assertOk();
        $response->assertSee('Kitchen');
        $response->assertSee('Stove top plates stain marks.');
        $response->assertSee('Fair wear and tear');
        $response->assertSee('no amount has been calculated', false);
    }

    public function test_recording_a_finding_via_http_persists_and_redirects(): void
    {
        \DB::table('role_permissions')->insert([
            ['role' => 'admin', 'permission_key' => 'rental_inspections.view', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'rental_inspections.review_deposit_comparison', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $item = $this->item();
        $in = $this->inInspection();
        $out = $this->outInspection();
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'damaged');

        $response = $this->actingAs($this->agent)->post(
            route('corex.rental-inspections.deposit-comparison.finding', [$out, $item]),
            ['disposition' => 'wear_and_tear', 'note' => 'Genuine wear and tear.'],
        );

        $response->assertRedirect(route('corex.rental-inspections.deposit-comparison', $out));
        $this->assertDatabaseHas('rental_inspection_item_findings', [
            'rental_inspection_id' => $out->id,
            'rental_inspection_item_id' => $item->id,
            'disposition' => 'wear_and_tear',
        ]);
    }

    public function test_header_facts_compare_keys_and_remotes_when_the_columns_exist(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('rental_inspections', 'keys_count')) {
            $this->markTestSkipped('Header-block columns (cc6, rental-inspection-form.md §4.1) not landed on this checkout yet.');
        }

        $in = $this->inInspection();
        $in->forceFill(['keys_count' => 3])->save();
        $out = $this->outInspection();
        $out->forceFill(['keys_count' => 2])->save();

        $facts = $this->service->compareHeaderFacts($in, $out);
        $keysRow = collect($facts)->firstWhere('label', 'Keys');

        $this->assertNotNull($keysRow);
        $this->assertSame(3, $keysRow['in']);
        $this->assertSame(2, $keysRow['out']);
        $this->assertTrue($keysRow['changed']);
    }

    public function test_header_facts_returns_empty_when_nothing_is_recorded_on_either_side(): void
    {
        $in = $this->inInspection();
        $out = $this->outInspection();

        $this->assertSame([], $this->service->compareHeaderFacts($in, $out));
    }
}
