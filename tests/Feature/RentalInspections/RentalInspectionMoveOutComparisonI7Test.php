<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionPhotoMatchGroup;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInventory;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\Rentals\RentalInspectionFollowUpService;
use App\Services\RentalInspectionComparisonService as Svc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §45.7a (Build I-7) — the move-out comparison: baseline from the tenancy's lease chain, marks derived
 * from the AGENCY's own condition severities, both photo sets with capture times and pairing, a context
 * timeline on marked rows, classification on any marked row, search/filter/sort, and a comparison-aware
 * Follow-up list. Nothing here asserts an amount: the comparison never computes one.
 */
final class RentalInspectionMoveOutComparisonI7Test extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Property $property;
    private User $agent;
    private Lease $lease;
    private Svc $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => '14 Jackson Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = $this->makeLease();
        $this->service = new Svc();

        foreach (['rental_inspections.view', 'rental_inspections.review_deposit_comparison'] as $key) {
            \DB::table('role_permissions')->insert([
                'role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => 'all',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function makeLease(array $attrs = []): Lease
    {
        $lease = Lease::create($attrs + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12500, 'start_date' => now()->subMonths(11),
        ]);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Naledi', 'last_name' => 'Dlamini', 'email' => 'naledi@example.co.za',
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id]);

        return $lease;
    }

    private function inspection(string $type, ?Lease $lease = null, array $extra = []): RentalInspection
    {
        $lease ??= $this->lease;

        return RentalInspection::create($extra + [
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => $type, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function room(string $label, int $order, string $type = 'bedroom'): PropertyRoom
    {
        return PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => $type, 'label' => $label, 'sort_order' => $order,
        ]);
    }

    private function item(string $label, ?PropertyRoom $room = null, int $order = 0): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'property_room_id' => $room?->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'sort_order' => $order,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function observe(RentalInspection $inspection, RentalInspectionItem $item, string $condition, ?string $notes = null, ?string $source = null): RentalInspectionObservation
    {
        return RentalInspectionObservation::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id, 'observed_by_user_id' => $this->agent->id,
            'condition' => $condition, 'notes' => $notes,
            'source' => $source ?? match ($inspection->type) {
                RentalInspection::TYPE_IN => RentalInspectionObservation::SOURCE_IN_INSPECTION,
                RentalInspection::TYPE_OUT => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
                default => RentalInspectionObservation::SOURCE_AD_HOC,
            },
        ]);
    }

    private function photo(RentalInspection $inspection, ?RentalInspectionObservation $obs, string $name, array $extra = []): RentalInspectionPhoto
    {
        return RentalInspectionPhoto::create($extra + [
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_observation_id' => $obs?->id,
            'storage_path' => 'https://qa.example.test/storage/' . $name . '.jpg',
            'uploaded_by_user_id' => $this->agent->id,
        ]);
    }

    /** @return array<string, array> the row (by item label) of the built comparison */
    private function rowsByLabel(RentalInspection $out, array $filters = []): array
    {
        $built = $this->service->buildComparison($out, $filters);
        $rows = [];
        foreach ($built['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $rows[$row['item']->label] = $row;
            }
        }

        return $rows;
    }

    private function setStates(array $states): void
    {
        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'condition_states' => $states]);
    }

    // ───────────────────────────── marks: derived from the agency's severity buckets ─────────────────────────────

    public function test_marks_follow_severity_buckets_and_the_spec_labels(): void
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out');

        $cases = [
            'Worse' => ['good', 'damaged', Svc::DECLINED, 'Worse than move-in'],
            'Better' => ['damaged', 'good', Svc::IMPROVED, 'Better'],
            'FairToGood' => ['fair', 'good', Svc::DIFFERENT, 'Different from move-in'],
            'RedToRed' => ['damaged', 'missing', Svc::DIFFERENT, 'Different from move-in'],
            'SamePresent' => ['damaged', 'damaged', Svc::UNCHANGED, 'Same as move-in (already present)'],
            'SameFine' => ['good', 'good', Svc::UNCHANGED, 'Same as move-in'],
            'NaOneSide' => ['n_a', 'good', Svc::NA_MISMATCH, 'N/A on one side only'],
        ];
        foreach ($cases as $label => [$inState, $outState]) {
            $item = $this->item($label);
            $this->observe($in, $item, $inState, $inState === 'damaged' ? 'Existing mark.' : null);
            $this->observe($out, $item, $outState, in_array($outState, ['damaged', 'missing'], true) ? 'Noted.' : null);
        }
        $this->observe($in, $this->item('NotAtOut'), 'good');
        $this->observe($out, $this->item('NewItem'), 'good');
        $bothNa = $this->item('BothNa');
        $this->observe($in, $bothNa, 'n_a');
        $this->observe($out, $bothNa, 'n_a');

        $rows = $this->rowsByLabel($out);

        foreach ($cases as $label => [, , $classification, $markLabel]) {
            $this->assertSame($classification, $rows[$label]['classification'], $label);
            $this->assertSame($markLabel, $rows[$label]['mark_label'], $label);
        }
        $this->assertSame('Not at move-out', $rows['NotAtOut']['mark_label']);
        $this->assertSame('New item', $rows['NewItem']['mark_label']);
        $this->assertArrayNotHasKey('BothNa', $rows, 'N/A at both ends is not a finding.');
        $this->assertTrue($rows['Worse']['marked']);
        $this->assertFalse($rows['Better']['marked'], 'Better is not a marked difference.');
        $this->assertFalse($rows['SamePresent']['marked']);
    }

    public function test_an_agency_whose_calm_state_is_not_called_good_classifies_the_same_way(): void
    {
        $this->setStates([
            ['key' => 'ok', 'label' => 'OK', 'requires_notes' => false, 'severity' => 'blue'],
            ['key' => 'poor', 'label' => 'Poor', 'requires_notes' => true, 'severity' => 'red'],
            ['key' => 'n_a', 'label' => 'N/A', 'requires_notes' => false, 'severity' => 'grey'],
        ]);
        $in = $this->inspection('in');
        $out = $this->inspection('out');
        $a = $this->item('Geyser');
        $b = $this->item('Stove');
        $this->observe($in, $a, 'ok');
        $this->observe($out, $a, 'poor', 'Leaking.');
        $this->observe($in, $b, 'poor', 'Old burn.');
        $this->observe($out, $b, 'ok');

        $rows = $this->rowsByLabel($out);

        $this->assertSame(Svc::DECLINED, $rows['Geyser']['classification']);
        $this->assertSame('OK', $rows['Geyser']['in_cell']['label'], 'The agency\'s own word, never the raw key.');
        $this->assertSame('Poor', $rows['Geyser']['out_cell']['label']);
        $this->assertSame(Svc::IMPROVED, $rows['Stove']['classification']);
    }

    public function test_a_condition_the_agency_has_since_removed_still_reads_and_is_flagged_not_hidden(): void
    {
        $this->setStates([
            ['key' => 'ok', 'label' => 'OK', 'requires_notes' => false, 'severity' => 'blue'],
        ]);
        $in = $this->inspection('in');
        $out = $this->inspection('out');
        $item = $this->item('Door');
        $this->observe($in, $item, 'ok');
        $this->observe($out, $item, 'broken_hinge', 'Hinge snapped.');

        $row = $this->rowsByLabel($out)['Door'];

        $this->assertSame(Svc::DECLINED, $row['classification'], 'An unknown state is treated as red: flagged, never hidden.');
        $this->assertSame('Broken hinge', $row['out_cell']['label']);
    }

    // ───────────────────────────── baseline resolution ─────────────────────────────

    public function test_the_baseline_is_found_along_the_renewal_chain(): void
    {
        $original = $this->lease;
        $renewal = $this->makeLease(['previous_lease_id' => $original->id]);
        $in = $this->inspection('in', $original, ['status' => RentalInspection::STATUS_COMPLETED]);
        $out = $this->inspection('out', $renewal);
        $item = $this->item('Lounge');
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'damaged', 'Scratched.');

        $this->assertSame($in->id, $this->service->matchingInInspection($out)->id);
        $this->assertSame(Svc::DECLINED, $this->rowsByLabel($out)['Lounge']['classification']);
    }

    public function test_a_corrupt_circular_lease_chain_is_absorbed_not_an_endless_loop(): void
    {
        $a = $this->lease;
        $b = $this->makeLease(['previous_lease_id' => $a->id]);
        $a->forceFill(['previous_lease_id' => $b->id])->save();

        $ids = $this->service->tenancyLeaseIds($b->fresh());

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function test_completed_move_in_beats_a_later_abandoned_draft_and_an_archived_one_still_counts(): void
    {
        $completed = $this->inspection('in', null, ['status' => RentalInspection::STATUS_COMPLETED]);
        $draft = $this->inspection('in');
        $out = $this->inspection('out');
        $this->assertGreaterThan($completed->id, $draft->id);

        $this->assertSame($completed->id, $this->service->matchingInInspection($out)->id);

        $completed->delete(); // archived — evidence must survive the archive
        $this->assertSame($completed->id, $this->service->matchingInInspection($out)->id);
    }

    public function test_with_no_move_in_at_all_every_item_is_new_and_the_page_still_renders(): void
    {
        $out = $this->inspection('out');
        $item = $this->item('Bath');
        $this->observe($out, $item, 'good');

        $row = $this->rowsByLabel($out)['Bath'];
        $this->assertSame(Svc::ONLY_AT_OUT, $row['classification']);

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $out))
            ->assertOk()->assertSee('No in-inspection found for this tenancy')->assertSee('New item');
    }

    public function test_a_tenant_fault_report_filed_after_move_in_is_context_never_the_baseline(): void
    {
        $in = $this->inspection('in', null, ['status' => RentalInspection::STATUS_COMPLETED]);
        $out = $this->inspection('out');
        $item = $this->item('Wall');
        $this->observe($in, $item, 'good');
        $this->observe($in, $item, 'damaged', 'Damp patch appeared.', RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT);
        $this->observe($out, $item, 'damaged', 'Damp patch has spread.');

        $row = $this->rowsByLabel($out)['Wall'];

        $this->assertSame('good', $row['in_observation']->condition, 'Baseline = what was recorded at move-in.');
        $this->assertSame(Svc::DECLINED, $row['classification']);
        $kinds = array_column($row['context']['observations'], 'kind');
        $this->assertSame(['Tenant fault report'], $kinds);
        $this->assertSame('Damp patch appeared.', $row['context']['observations'][0]['notes']);
    }

    // ───────────────────────────── context: between the two inspections ─────────────────────────────

    public function test_marked_rows_carry_the_timeline_of_interim_checks_fault_reports_and_work_orders_with_no_money(): void
    {
        $in = $this->inspection('in', null, ['status' => RentalInspection::STATUS_COMPLETED]);
        $interim = $this->inspection('interim');
        $out = $this->inspection('out');
        $item = $this->item('Ceiling');
        $quiet = $this->item('Window');
        $this->observe($in, $item, 'good');
        $this->observe($interim, $item, 'damaged', 'Damp wall reported.');
        $this->observe($out, $item, 'damaged', 'Still damp.');
        $this->observe($in, $quiet, 'good');
        $this->observe($out, $quiet, 'good');

        RentalFaultReport::forceCreate([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_inspection_item_id' => $item->id, 'reported_by_type' => 'tenant', 'reported_channel' => 'in_person',
            'title' => 'Damp ceiling', 'description' => 'Damp', 'status' => 'work_order_raised', 'reported_at' => now()->subMonths(2),
        ]);
        RentalWorkOrder::forceCreate([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_inspection_item_id' => $item->id, 'title' => 'Treat damp', 'description' => 'Treat', 'reported_by_type' => 'tenant',
            'status' => 'cancelled', 'assignment_type' => RentalWorkOrder::ASSIGNMENT_INTERNAL,
            'reported_at' => now()->subMonth(), 'cancelled_at' => now()->subWeeks(2),
            'cost_amount' => 4321.50, 'paid_by' => 'owner',
        ]);

        $rows = $this->rowsByLabel($out);
        $ctx = $rows['Ceiling']['context'];

        $this->assertSame(['Interim inspection'], array_column($ctx['observations'], 'kind'));
        $this->assertSame('Damp ceiling', $ctx['fault_reports'][0]['title']);
        $this->assertSame('Our own team', $ctx['work_orders'][0]['done_by']);
        $this->assertSame('Cancelled', $ctx['work_orders'][0]['status']);
        $this->assertNotNull($ctx['work_orders'][0]['closed_at']);
        $this->assertStringNotContainsString('4321', json_encode($ctx), 'Money is never read onto the comparison.');
        $this->assertNull($rows['Window']['context'], 'Only marked rows carry a timeline.');

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $out))
            ->assertOk()->assertSee('What happened in between')->assertSee('Interim inspection')
            ->assertSee('Damp wall reported.')->assertDontSee('4,321')->assertDontSee('4321');
    }

    // ───────────────────────────── photos, capture time, pairing ─────────────────────────────

    public function test_photo_strips_carry_capture_time_notes_and_paired_photos_come_first(): void
    {
        $in = $this->inspection('in', null, ['status' => RentalInspection::STATUS_COMPLETED]);
        $out = $this->inspection('out');
        $room = $this->room('Main bedroom', 1);
        $item = $this->item('Carpet', $room);
        $inObs = $this->observe($in, $item, 'good');
        $outObs = $this->observe($out, $item, 'damaged', 'Burn mark.');

        $inUnpaired = $this->photo($in, $inObs, 'in-a', ['taken_at' => now()->subYear(), 'taken_at_source' => 'client']);
        $inPaired = $this->photo($in, $inObs, 'in-b', ['taken_at' => now()->subYear()->addMinutes(5), 'taken_at_source' => 'client']);
        $outPaired = $this->photo($out, $outObs, 'out-a', ['taken_at' => now()->subHour(), 'taken_at_source' => 'exif']);
        $this->photo($out, $outObs, 'out-b'); // upload time only
        $archived = $this->photo($out, $outObs, 'out-gone');
        $archived->delete();

        $group = RentalInspectionPhotoMatchGroup::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'created_by_user_id' => $this->agent->id]);
        $group->addMember($inPaired, $this->agent);
        $group->addMember($outPaired, $this->agent);

        $row = $this->rowsByLabel($out)['Carpet'];

        $this->assertSame([$inPaired->id, $inUnpaired->id], array_column($row['in_cell']['photos'], 'id'), 'Paired photo listed first.');
        $this->assertTrue($row['in_cell']['photos'][0]['paired']);
        $this->assertFalse($row['in_cell']['photos'][1]['paired']);
        $this->assertCount(2, $row['out_cell']['photos'], 'The archived photo is gone from the comparison.');
        $this->assertSame($outPaired->id, $row['out_cell']['photos'][0]['id']);
        $this->assertStringStartsWith('Taken ', $row['in_cell']['photos'][0]['caption']);
        $this->assertStringStartsWith('Uploaded ', $row['out_cell']['photos'][1]['caption'], 'Upload-only time is never presented as a capture time.');

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $out))
            ->assertOk()->assertSee('data-cmp-thumb', false)->assertSee('Paired');
    }

    public function test_room_level_general_photos_show_for_both_sides_and_empty_sides_are_fine(): void
    {
        $in = $this->inspection('in', null, ['status' => RentalInspection::STATUS_COMPLETED]);
        $out = $this->inspection('out');
        $room = $this->room('Kitchen', 1, 'kitchen');
        $item = $this->item('Oven', $room);
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'good');
        $this->photo($in, null, 'room-in', ['property_room_id' => $room->id]);
        $this->photo($out, null, 'room-out-1', ['property_room_id' => $room->id]);
        $this->photo($out, null, 'room-out-2', ['property_room_id' => $room->id]);
        $this->photo($out, null, 'tray-untagged'); // no room, no item: never shown

        $group = $this->service->buildComparison($out)['groups'][0];

        $this->assertCount(1, $group['in_photos']);
        $this->assertCount(2, $group['out_photos']);
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $out))
            ->assertOk()->assertSee('Room photos at move-in')->assertDontSee('tray-untagged');
    }

    // ───────────────────────────── rooms, retired items, renamed rooms, walking order ─────────────────────────────

    public function test_rooms_follow_walking_order_retired_items_and_renamed_rooms_still_appear(): void
    {
        $in = $this->inspection('in', null, ['status' => RentalInspection::STATUS_COMPLETED]);
        $out = $this->inspection('out');
        $second = $this->room('Bathroom', 2, 'bathroom');
        $first = $this->room('Entrance', 1, 'hall');
        $a = $this->item('Basin', $second);
        $b = $this->item('Front door', $first);
        $loose = $this->item('Gate motor'); // no room
        foreach ([$a, $b, $loose] as $it) {
            $this->observe($in, $it, 'good');
            $this->observe($out, $it, 'good');
        }
        $a->forceFill(['is_retired' => true])->save(); // retired after the In — evidence must not vanish
        $second->forceFill(['label' => 'Guest bathroom'])->save(); // renamed

        $groups = $this->service->buildComparison($out)['groups'];

        $this->assertSame(['Entrance', 'Guest bathroom', 'General'], array_column($groups, 'label'));
        $this->assertSame('Basin', $groups[1]['rows'][0]['item']->label);
    }

    // ───────────────────────────── search / filter / sort ─────────────────────────────

    private function filterFixture(): RentalInspection
    {
        $in = $this->inspection('in', null, ['status' => RentalInspection::STATUS_COMPLETED]);
        $out = $this->inspection('out');
        $kitchen = $this->room('Kitchen', 1, 'kitchen');
        $bed = $this->room('Bedroom 1', 2);
        $stove = $this->item('Stove', $kitchen);
        $tap = $this->item('Tap', $kitchen);
        $bedWall = $this->item('Wall', $bed);
        $this->observe($in, $stove, 'good');
        $this->observe($out, $stove, 'damaged', 'Grease stain.');
        $this->observe($in, $tap, 'good');
        $this->observe($out, $tap, 'good');
        $this->observe($in, $bedWall, 'fair');
        $this->observe($out, $bedWall, 'good');

        return $out;
    }

    public function test_search_room_difference_classification_only_differences_and_sort_filters(): void
    {
        $out = $this->filterFixture();
        $stove = RentalInspectionItem::where('label', 'Stove')->first();
        $kitchenId = (string) PropertyRoom::where('label', 'Kitchen')->value('id');

        $labels = fn (array $f) => array_keys($this->rowsByLabel($out, $f));

        $this->assertEqualsCanonicalizing(['Stove', 'Tap', 'Wall'], $labels([]));
        $this->assertSame(['Stove'], $labels(['q' => 'grease']));
        $this->assertSame(['Wall'], $labels(['q' => 'bedroom']));
        $this->assertEqualsCanonicalizing(['Stove', 'Tap'], $labels(['room' => $kitchenId]));
        $this->assertSame(['Stove'], $labels(['difference' => 'worse']));
        $this->assertSame(['Wall'], $labels(['difference' => 'different']));
        $this->assertSame(['Tap'], $labels(['difference' => 'same']));
        $this->assertEqualsCanonicalizing(['Stove', 'Wall'], $labels(['only_differences' => '1']));
        $this->assertEqualsCanonicalizing(['Stove', 'Wall'], $labels(['classification' => 'unclassified']));

        $this->service->recordFinding($out, $stove, 'charge_tenant', 'Burnt-on grease, not cleaned.', $this->agent);
        $this->assertSame(['Stove'], $labels(['classification' => 'charge_tenant']));
        $this->assertSame(['Wall'], $labels(['classification' => 'unclassified']));
        $this->assertSame([], $labels(['classification' => 'pre_existing']));

        $severity = $this->service->buildComparison($out, ['sort' => 'severity'])['groups'];
        $this->assertSame('Kitchen', $severity[0]['label']);
        $this->assertSame('Stove', $severity[0]['rows'][0]['item']->label, 'Worst row first.');
    }

    public function test_malformed_filter_values_are_absorbed_to_defaults_never_a_500(): void
    {
        $out = $this->filterFixture();

        $filters = $this->service->normaliseFilters([
            'q' => str_repeat('x', 500), 'room' => 'abc', 'difference' => 'bogus', 'classification' => ['array'],
            'sort' => 'DROP TABLE', 'only_differences' => 'maybe',
        ]);

        $this->assertSame(100, mb_strlen($filters['q']));
        $this->assertSame('', $filters['room']);
        $this->assertSame('all', $filters['difference']);
        $this->assertSame('all', $filters['classification']);
        $this->assertSame('walking', $filters['sort']);
        $this->assertFalse($filters['only_differences']);

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $out) . '?room=abc&difference=bogus&sort=zzz&classification[]=x&q[]=y')
            ->assertOk()->assertSee('Showing 3 of 3 items');
    }

    public function test_empty_states_say_why_and_what_to_do(): void
    {
        $out = $this->filterFixture();
        $url = route('corex.rental-inspections.deposit-comparison', $out);

        $this->actingAs($this->agent)->get($url . '?q=nonexistent-thing')
            ->assertOk()->assertSee('No items match these filters')->assertSee('Clear the filters');

        $emptyOut = $this->inspection('out', $this->makeLease());
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $emptyOut))
            ->assertOk()->assertSee('No comparable items yet');
    }

    // ───────────────────────────── classification ─────────────────────────────

    public function test_a_finding_can_be_recorded_on_any_marked_row_and_not_on_an_unmarked_one(): void
    {
        $out = $this->filterFixture();
        $wall = RentalInspectionItem::where('label', 'Wall')->first(); // fair -> good = Different (marked)
        $tap = RentalInspectionItem::where('label', 'Tap')->first();   // good -> good = Same (not marked)

        foreach (['wear_and_tear', 'flagged', 'pre_existing', 'landlord_cost', 'charge_tenant'] as $disposition) {
            $finding = $this->service->recordFinding($out, $wall, $disposition, "Reason for {$disposition}.", $this->agent);
            $this->assertSame($disposition, $finding->disposition);
        }

        $live = RentalInspectionItemFinding::where('rental_inspection_item_id', $wall->id)->whereNull('superseded_at')->get();
        $this->assertCount(1, $live, 'Superseded repeatedly: exactly one live judgement.');
        $this->assertSame(5, RentalInspectionItemFinding::where('rental_inspection_item_id', $wall->id)->count(), 'Nothing is ever hard-deleted.');

        $this->expectException(\LogicException::class);
        $this->service->recordFinding($out, $tap, 'pre_existing', 'Nothing to judge.', $this->agent);
    }

    public function test_the_finding_form_over_http_accepts_new_dispositions_and_rejects_unknown_ones_and_empty_notes(): void
    {
        $out = $this->filterFixture();
        $wall = RentalInspectionItem::where('label', 'Wall')->first();
        $url = route('corex.rental-inspections.deposit-comparison.finding', [$out, $wall]);

        $this->actingAs($this->agent)->post($url, ['disposition' => 'charge_tenant', 'note' => 'Scuffed by the tenant.'])
            ->assertRedirect(route('corex.rental-inspections.deposit-comparison', $out));
        $this->assertDatabaseHas('rental_inspection_item_findings', ['rental_inspection_item_id' => $wall->id, 'disposition' => 'charge_tenant', 'superseded_at' => null]);

        $this->actingAs($this->agent)->post($url, ['disposition' => 'delete_everything', 'note' => 'x'])->assertSessionHasErrors('disposition');
        $this->actingAs($this->agent)->post($url, ['disposition' => 'pre_existing', 'note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->agent)->post($url, ['disposition' => 'pre_existing', 'note' => '   '])->assertSessionHasErrors('note');

        $tap = RentalInspectionItem::where('label', 'Tap')->first();
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.deposit-comparison.finding', [$out, $tap]), ['disposition' => 'pre_existing', 'note' => 'n'])
            ->assertSessionHasErrors('finding');

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $out))
            ->assertOk()->assertSee('Classified:')->assertSee('Charge to tenant')->assertSee('Scuffed by the tenant.');
    }

    public function test_an_item_from_another_property_cannot_be_classified_through_this_inspection(): void
    {
        $out = $this->filterFixture();
        $otherProperty = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Elsewhere', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $stranger = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $otherProperty->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Alien item', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)->post(route('corex.rental-inspections.deposit-comparison.finding', [$out, $stranger]), ['disposition' => 'pre_existing', 'note' => 'n'])
            ->assertSessionHasErrors('finding');
        $this->assertDatabaseMissing('rental_inspection_item_findings', ['rental_inspection_item_id' => $stranger->id]);
    }

    // ───────────────────────────── scoping, navigation, inventory link ─────────────────────────────

    public function test_another_agencys_user_and_a_non_out_inspection_cannot_open_the_page(): void
    {
        $out = $this->filterFixture();
        $in = RentalInspection::where('type', 'in')->first();

        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'HQ']);
        $stranger = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        \DB::table('role_permissions')->insert([
            'role' => 'admin', 'permission_key' => 'rental_inspections.view', 'agency_id' => $otherAgency->id, 'scope' => 'all',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($stranger)->get(route('corex.rental-inspections.deposit-comparison', $out))->assertNotFound();
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $in))->assertNotFound();
    }

    private function inventory(string $status): RentalInventory
    {
        return RentalInventory::forceCreate([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'status' => $status, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    public function test_the_inventory_comparison_link_appears_only_for_a_completed_inventory_the_viewer_may_see(): void
    {
        \DB::table('role_permissions')->insert([
            'role' => 'admin', 'permission_key' => 'rental_inventories.view', 'agency_id' => $this->agency->id, 'scope' => 'all',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $out = $this->filterFixture();
        $url = route('corex.rental-inspections.deposit-comparison', $out);

        $this->actingAs($this->agent)->get($url)->assertOk()->assertDontSee('Inventory comparison');

        $inventory = $this->inventory(RentalInventory::STATUS_DRAFT);
        $this->actingAs($this->agent)->get($url)->assertOk()->assertDontSee('Inventory comparison');

        $inventory->forceFill(['status' => RentalInventory::STATUS_COMPLETED])->save();
        $this->actingAs($this->agent)->get($url)->assertOk()->assertSee('Inventory comparison')
            ->assertSee(route('corex.rental-inventories.comparison', $inventory), false);
    }

    public function test_a_user_with_no_inventories_permission_gets_no_link_and_no_crash(): void
    {
        $out = $this->filterFixture();
        $this->inventory(RentalInventory::STATUS_COMPLETED);

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $out))
            ->assertOk()->assertDontSee('Inventory comparison');
    }

    public function test_the_page_never_shows_an_amount_and_keeps_its_proposal_footer(): void
    {
        $out = $this->filterFixture();

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $out))
            ->assertOk()->assertSee('no amount has been calculated', false)->assertDontSee('R 12');
    }

    // ───────────────────────────── Follow-up becomes comparison-aware ─────────────────────────────

    public function test_follow_up_lists_new_and_worse_defects_first_and_labels_the_ones_already_present(): void
    {
        $in = $this->inspection('in', null, ['status' => RentalInspection::STATUS_COMPLETED]);
        $out = $this->inspection('out');
        $old = $this->item('Cracked tile');
        $fresh = $this->item('Broken handle');
        $this->observe($in, $old, 'damaged', 'Crack at move-in.');
        $this->observe($out, $old, 'damaged', 'Crack still there.');
        $this->observe($in, $fresh, 'good');
        $this->observe($out, $fresh, 'damaged', 'Snapped.'); // recorded AFTER the old one, so plain id order would list it last

        $list = app(RentalInspectionFollowUpService::class)->followUpObservations($out->fresh('observations'));

        $this->assertSame('Broken handle', $list->first()->item->label);
        $this->assertFalse((bool) $list->first()->getAttribute('already_present_at_move_in'));
        $this->assertSame('Cracked tile', $list->last()->item->label);
        $this->assertTrue((bool) $list->last()->getAttribute('already_present_at_move_in'));
    }

    public function test_follow_up_order_is_untouched_on_an_in_inspection(): void
    {
        $in = $this->inspection('in');
        $a = $this->item('A');
        $b = $this->item('B');
        $this->observe($in, $a, 'damaged', 'a');
        $this->observe($in, $b, 'damaged', 'b');

        $list = app(RentalInspectionFollowUpService::class)->followUpObservations($in->fresh('observations'));

        $this->assertSame(['A', 'B'], $list->map(fn ($o) => $o->item->label)->all());
        $this->assertNull($list->first()->getAttribute('already_present_at_move_in'));
    }
}
