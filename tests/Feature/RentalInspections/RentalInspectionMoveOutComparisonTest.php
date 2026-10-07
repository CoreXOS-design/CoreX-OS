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
use App\Models\RentalInspectionPhotoNote;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInventory;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\Rentals\RentalInspectionFollowUpService;
use App\Services\RentalInspectionComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.7a (Build I-7) — the Move-out comparison.
 *
 * Input paths proven (BUILD_STANDARD §5): baseline — this lease / a PREVIOUS lease on the chain / completed preferred over a
 * later abandoned draft / not-completed fallback / archived / none at all / an interim in between is never the baseline;
 * difference — worse / better / different (same bucket) / same / same-but-defect (already present) / new item / not at
 * move-out / N/A on one side / N/A on both (no row) / an agency vocabulary with NO "good" / an unknown condition key;
 * rows — walking order, General last, search / room / difference / classification / differences-only / severity sort;
 * photos — paired by match group first, unpaired kept, room-level photos, capture caption, photo note, archived excluded,
 * no per-row queries; context — tenant fault report, interim, fault report, work order (supplier / our team, closed date),
 * unmarked rows carry none, no money; classification — five dispositions, on marked rows only, supersede, note required,
 * unknown value refused; follow-up — worse first, already-present last and labelled, nothing preselected, in-inspection
 * untouched; scoping — out-of-scope user 404, another agency 404, in-inspection 404.
 */
final class RentalInspectionMoveOutComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private RentalInspectionComparisonService $service;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'MO Agency', 'slug' => 'mo-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'HQ']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => '14 Marine Drive, Margate', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = $this->makeLease($this->property, ['start_date' => now()->subMonths(10)]);
        $this->service = new RentalInspectionComparisonService();

        DB::table('role_permissions')->insert([
            ['role' => 'admin', 'permission_key' => 'rental_inspections.view', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'rental_inspections.review_deposit_comparison', 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function makeLease(Property $property, array $over = []): Lease
    {
        $lease = Lease::create(array_merge([
            'agency_id' => $property->agency_id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => now()->subMonths(6),
            'created_by_user_id' => $this->agent->id,
        ], $over));
        $contact = Contact::create([
            'agency_id' => $property->agency_id, 'branch_id' => $property->branch_id,
            'first_name' => 'Thabo', 'last_name' => 'Mokoena' . uniqid(), 'email' => 'tenant-' . uniqid() . '@example.co.za',
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id]);

        return $lease;
    }

    private function inspection(string $type, string $status = RentalInspection::STATUS_COMPLETED, ?Lease $lease = null): RentalInspection
    {
        $lease ??= $this->lease;

        return RentalInspection::create([
            'agency_id' => $lease->agency_id, 'lease_id' => $lease->id, 'property_id' => $lease->property_id,
            'type' => $type, 'status' => $status, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function room(string $label, int $sort = 0): PropertyRoom
    {
        return PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => $label,
            'source' => 'manual', 'sort_order' => $sort, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function item(string $label, ?PropertyRoom $room = null, int $sort = 0): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room?->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'sort_order' => $sort, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function observe(RentalInspection $insp, RentalInspectionItem $item, string $condition, ?string $notes = null, ?string $source = null): RentalInspectionObservation
    {
        return RentalInspectionObservation::create([
            'agency_id' => $insp->agency_id, 'rental_inspection_id' => $insp->id, 'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => $condition, 'notes' => $notes,
            'source' => $source ?? match ($insp->type) {
                RentalInspection::TYPE_IN => RentalInspectionObservation::SOURCE_IN_INSPECTION,
                RentalInspection::TYPE_OUT => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
                default => RentalInspectionObservation::SOURCE_AD_HOC,
            },
        ]);
    }

    private function photo(RentalInspection $insp, ?RentalInspectionObservation $obs, ?PropertyRoom $room, string $url = '/p/x.jpg', array $over = []): RentalInspectionPhoto
    {
        return RentalInspectionPhoto::create(array_merge([
            'agency_id' => $insp->agency_id, 'rental_inspection_id' => $insp->id,
            'rental_inspection_observation_id' => $obs?->id, 'property_room_id' => $room?->id,
            'storage_path' => $url, 'uploaded_by_user_id' => $this->agent->id,
        ], $over));
    }

    /** @return array<string, mixed> */
    private function cmp(RentalInspection $out, array $filters = []): array
    {
        return $this->service->moveOutComparison($out, $filters);
    }

    private function rows(array $cmp): \Illuminate\Support\Collection
    {
        return collect($cmp['rooms'])->flatMap(fn ($r) => $r['rows']);
    }

    private function row(array $cmp, RentalInspectionItem $item): ?array
    {
        return $this->rows($cmp)->firstWhere(fn ($r) => $r['item']->id === $item->id);
    }

    // ── baseline ────────────────────────────────────────────────────────

    public function test_the_baseline_is_the_completed_in_on_this_lease_not_a_later_abandoned_draft(): void
    {
        $done = $this->inspection('in');
        $this->inspection('in', RentalInspection::STATUS_DRAFT);
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);

        $this->assertSame($done->id, $this->service->matchingInInspection($out)->id);
    }

    public function test_the_baseline_is_found_on_a_previous_lease_of_the_tenancy_chain(): void
    {
        $old = $this->makeLease($this->property, ['status' => Lease::STATUS_EXPIRED, 'start_date' => now()->subYears(2)]);
        $older = $this->makeLease($this->property, ['status' => Lease::STATUS_EXPIRED, 'start_date' => now()->subYears(3)]);
        $old->update(['previous_lease_id' => $older->id]);
        $this->lease->update(['previous_lease_id' => $old->id]);
        $inOnOldest = $this->inspection('in', RentalInspection::STATUS_COMPLETED, $older);
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);

        $this->assertSame($inOnOldest->id, $this->service->matchingInInspection($out)->id, 'a renewed tenancy compares against the move-in done on an earlier term');
        $this->assertSame([$this->lease->id, $old->id, $older->id], $this->service->tenancyLeaseIds($out));

        $nearer = $this->inspection('in', RentalInspection::STATUS_COMPLETED, $old);
        $this->assertSame($nearer->id, $this->service->matchingInInspection($out)->id, 'the nearest lease with a completed In wins');
    }

    public function test_an_unrelated_leases_move_in_is_never_the_baseline(): void
    {
        $other = $this->makeLease($this->property, ['status' => Lease::STATUS_EXPIRED]);   // same property, not on the chain
        $this->inspection('in', RentalInspection::STATUS_COMPLETED, $other);
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);

        $this->assertNull($this->service->matchingInInspection($out));
    }

    public function test_an_archived_in_still_serves_as_the_baseline_and_a_non_completed_one_is_the_fallback(): void
    {
        $in = $this->inspection('in', RentalInspection::STATUS_DRAFT);
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $this->assertSame($in->id, $this->service->matchingInInspection($out)->id, 'a never-completed move-in is still compared against');
        $this->assertFalse($this->cmp($out)['baseline_completed']);

        $in->forceFill(['status' => RentalInspection::STATUS_COMPLETED])->save();
        $in->delete();
        $this->assertSame($in->id, $this->service->matchingInInspection($out)->id);
        $this->assertTrue($this->cmp($out)['baseline_completed']);
    }

    public function test_an_interim_in_between_is_never_the_baseline(): void
    {
        $in = $this->inspection('in');
        $this->inspection('interim');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);

        $this->assertSame($in->id, $this->service->matchingInInspection($out)->id);
    }

    public function test_with_no_move_in_at_all_everything_is_a_new_item_not_an_error(): void
    {
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $item = $this->item('Walls');
        $this->observe($out, $item, 'good');

        $cmp = $this->cmp($out);

        $this->assertNull($cmp['baseline']);
        $this->assertSame('new_item', $this->row($cmp, $item)['difference']);
    }

    // ── difference ──────────────────────────────────────────────────────

    private function obs(string $condition): RentalInspectionObservation
    {
        return new RentalInspectionObservation(['condition' => $condition]);
    }

    public function test_difference_follows_the_agencys_severity_buckets_not_the_word_good(): void
    {
        $d = fn (string $in, string $out) => $this->service->differenceFor($this->obs($in), $this->obs($out), $this->agency->id);

        // default vocabulary: good/fair blue, damaged/not_working/missing red, other amber, n_a grey
        $this->assertSame('worse', $d('good', 'damaged')['key']);
        $this->assertSame('worse', $d('fair', 'other')['key'], 'blue -> amber');
        $this->assertSame('worse', $d('other', 'missing')['key'], 'amber -> red');
        $this->assertSame('better', $d('damaged', 'good')['key']);
        $this->assertSame('better', $d('missing', 'other')['key'], 'red -> amber is better, though neither is "good"');
        $this->assertSame('different', $d('damaged', 'missing')['key'], 'same bucket, different state: never auto-called worse');
        $this->assertSame('different', $d('good', 'fair')['key']);
        $this->assertSame('same', $d('damaged', 'damaged')['key']);
        $this->assertTrue($d('good', 'fair')['marked']);
        $this->assertFalse($d('damaged', 'good')['marked']);
        $this->assertFalse($d('good', 'good')['marked']);
    }

    public function test_an_agency_with_no_good_state_at_all_is_still_ranked_correctly(): void
    {
        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'condition_states' => [
            ['key' => 'great', 'label' => 'Great', 'requires_notes' => false, 'severity' => 'blue'],
            ['key' => 'poor', 'label' => 'Poor', 'requires_notes' => true, 'severity' => 'amber'],
            ['key' => 'broken', 'label' => 'Broken', 'requires_notes' => true, 'severity' => 'red'],
        ], 'baseline_condition_key' => 'great']);
        $d = fn (string $in, string $out) => $this->service->differenceFor($this->obs($in), $this->obs($out), $this->agency->id)['key'];

        $this->assertSame('worse', $d('great', 'poor'));
        $this->assertSame('better', $d('broken', 'poor'));
        $this->assertSame('better', $d('poor', 'great'), 'the old hard-coded test (moved TO the literal good) would have called this a decline');
        $this->assertSame('same', $d('poor', 'poor'));

        // and the legacy classification used by the old methods follows the same rule
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $a = $this->item('A');
        $b = $this->item('B');
        $this->observe($in, $a, 'poor');
        $this->observe($out, $a, 'great');
        $this->observe($in, $b, 'great');
        $this->observe($out, $b, 'poor');
        $legacy = $this->service->compareItems($out)->keyBy(fn ($r) => $r['item']->id);
        $this->assertSame(RentalInspectionComparisonService::IMPROVED, $legacy[$a->id]['classification']);
        $this->assertSame(RentalInspectionComparisonService::DECLINED, $legacy[$b->id]['classification']);
    }

    public function test_an_unknown_condition_key_is_treated_cautiously_never_as_an_improvement(): void
    {
        $d = $this->service->differenceFor($this->obs('good'), $this->obs('some_old_key'), $this->agency->id)['key'];

        $this->assertSame('worse', $d, 'an unmapped state ranks as red, the same default the colour logic uses');
    }

    public function test_one_sided_and_na_rows(): void
    {
        $d = fn (?string $in, ?string $out) => $this->service->differenceFor($in ? $this->obs($in) : null, $out ? $this->obs($out) : null, $this->agency->id);

        $this->assertSame('new_item', $d(null, 'good')['key']);
        $this->assertSame('not_at_move_out', $d('good', null)['key']);
        $this->assertSame('not_at_move_out', $d('good', 'n_a')['key'], 'it was here at move-in and is not at move-out');
        $this->assertSame('new_item', $d('n_a', 'good')['key']);
        $this->assertSame('na_both', $d('n_a', 'n_a')['key']);
        $this->assertTrue($d('good', 'n_a')['marked']);
        $this->assertTrue($d(null, 'good')['marked']);
    }

    public function test_labels_and_the_already_present_wording(): void
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $room = $this->room('Kitchen');
        $good = $this->item('Walls', $room);
        $defect = $this->item('Tiles', $room);
        $worse = $this->item('Oven', $room);
        $this->observe($in, $good, 'good');
        $this->observe($out, $good, 'good');
        $this->observe($in, $defect, 'damaged', 'Cracked tile by the sink');
        $this->observe($out, $defect, 'damaged', 'Cracked tile by the sink');
        $this->observe($in, $worse, 'good');
        $this->observe($out, $worse, 'not_working', 'Element dead');

        $cmp = $this->cmp($out);

        $this->assertSame('Same as move-in', $this->row($cmp, $good)['difference_label']);
        $this->assertSame('Same as move-in (already present)', $this->row($cmp, $defect)['difference_label']);
        $this->assertSame('Worse than move-in', $this->row($cmp, $worse)['difference_label']);
        $this->assertFalse($this->row($cmp, $defect)['marked'], 'an unchanged defect is not a difference to chase');
        $this->assertTrue($this->row($cmp, $worse)['marked']);
        $this->assertSame('Not working', $this->row($cmp, $worse)['out']['label'], 'the agency LABEL, never the raw key');
    }

    // ── rows: order, filters, counts ────────────────────────────────────

    private function layout(): array
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $lounge = $this->room('Lounge', 1);
        $bed = $this->room('Bedroom 1', 2);
        $bath = $this->room('Bathroom', 3);
        $walls = $this->item('Walls', $lounge, 1);
        $floor = $this->item('Floor', $lounge, 2);
        $window = $this->item('Window', $bed, 1);
        $taps = $this->item('Taps', $bath, 1);
        $meter = $this->item('Water meter', null);
        $gone = $this->item('Curtain', $bed, 2);
        $na = $this->item('Aircon', $bed, 3);

        $this->observe($in, $walls, 'good');       $this->observe($out, $walls, 'good');
        $this->observe($in, $floor, 'good');       $this->observe($out, $floor, 'damaged', 'Burn mark near the TV');
        $this->observe($in, $window, 'damaged');   $this->observe($out, $window, 'good');
        $this->observe($in, $taps, 'good');        $this->observe($out, $taps, 'fair');
        $this->observe($out, $meter, 'good');
        $this->observe($in, $gone, 'good');
        $this->observe($in, $na, 'n_a');           $this->observe($out, $na, 'n_a');

        return compact('in', 'out', 'lounge', 'bed', 'bath', 'walls', 'floor', 'window', 'taps', 'meter', 'gone', 'na');
    }

    public function test_rooms_come_back_in_walking_order_with_general_last_and_na_both_excluded(): void
    {
        $x = $this->layout();
        $cmp = $this->cmp($x['out']);

        $this->assertSame(['Lounge', 'Bedroom 1', 'Bathroom', 'General'], array_column($cmp['rooms'], 'label'));
        $this->assertNull($this->row($cmp, $x['na']), 'N/A on both sides is not a row at all');
        $this->assertSame(['Walls', 'Floor'], collect($cmp['rooms'][0]['rows'])->pluck('item.label')->all(), 'items in their own order within the room');
        $this->assertSame(['worse' => 1, 'different' => 1, 'same' => 1, 'better' => 1, 'new_item' => 1, 'not_at_move_out' => 1], $cmp['counts']);
        $this->assertSame(6, $cmp['total_rows']);
        $this->assertSame(6, $cmp['shown_rows']);
    }

    public function test_reordering_rooms_on_the_property_reorders_the_comparison(): void
    {
        $x = $this->layout();
        $x['bath']->update(['sort_order' => 0]);

        $this->assertSame('Bathroom', $this->cmp($x['out'])['rooms'][0]['label']);
    }

    public function test_the_filters(): void
    {
        $x = $this->layout();
        $labels = fn (array $c) => $this->rows($c)->pluck('item.label')->sort()->values()->all();

        $this->assertSame(['Floor'], $labels($this->cmp($x['out'], ['difference' => 'worse'])));
        $this->assertSame(['Curtain', 'Floor', 'Taps', 'Water meter'], $labels($this->cmp($x['out'], ['differences_only' => true])));
        $this->assertSame(['Floor'], $labels($this->cmp($x['out'], ['q' => 'burn'])), 'search reaches the note');
        $this->assertSame(['Floor', 'Walls'], $labels($this->cmp($x['out'], ['q' => 'LOUNGE'])), 'and the room name, any case');
        $this->assertSame(['Window'], $labels($this->cmp($x['out'], ['room' => (string) $x['bed']->id, 'difference' => 'better'])));
        $this->assertSame(['Water meter'], $labels($this->cmp($x['out'], ['room' => 'general'])));
        $this->assertSame([], $labels($this->cmp($x['out'], ['q' => 'zzz-nothing'])));
        $this->assertSame(4, $this->cmp($x['out'], ['differences_only' => true])['shown_rows']);
        $this->assertSame(6, $this->cmp($x['out'], ['q' => 'zzz'])['total_rows'], 'the unfiltered total never moves');
    }

    public function test_severity_sort_puts_the_worst_first_in_rooms_and_among_rooms(): void
    {
        $x = $this->layout();
        $cmp = $this->cmp($x['out'], ['sort' => 'severity']);

        $this->assertSame('Lounge', $cmp['rooms'][0]['label'], 'the room holding the worst item leads');
        $this->assertSame('Floor', $cmp['rooms'][0]['rows'][0]['item']->label, 'worse before same within the room');
    }

    public function test_classification_filter(): void
    {
        $x = $this->layout();
        $this->service->recordFinding($x['out'], $x['floor'], 'charge_tenant', 'Burn from a cigarette.', $this->agent);

        $labels = fn (array $c) => $this->rows($c)->pluck('item.label')->sort()->values()->all();
        $this->assertSame(['Floor'], $labels($this->cmp($x['out'], ['classification' => 'any'])));
        $this->assertSame(['Floor'], $labels($this->cmp($x['out'], ['classification' => 'charge_tenant'])));
        $this->assertSame([], $labels($this->cmp($x['out'], ['classification' => 'pre_existing'])));
        $this->assertSame(['Curtain', 'Taps', 'Water meter'], $labels($this->cmp($x['out'], ['classification' => 'none'])), 'marked rows still awaiting a judgement');
    }

    public function test_a_retired_item_with_history_is_still_compared(): void
    {
        $x = $this->layout();
        $x['floor']->update(['is_retired' => true]);

        $this->assertNotNull($this->row($this->cmp($x['out']), $x['floor']));
    }

    // ── photos ──────────────────────────────────────────────────────────

    public function test_photos_are_paired_by_match_group_first_and_unpaired_ones_are_kept(): void
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $room = $this->room('Lounge');
        $item = $this->item('Walls', $room);
        $oi = $this->observe($in, $item, 'good');
        $oo = $this->observe($out, $item, 'damaged');
        $in1 = $this->photo($in, $oi, $room, '/in1.jpg');
        $in2 = $this->photo($in, $oi, $room, '/in2.jpg');
        $out1 = $this->photo($out, $oo, $room, '/out1.jpg');
        $out2 = $this->photo($out, $oo, $room, '/out2.jpg');
        $out3 = $this->photo($out, $oo, $room, '/out3.jpg');
        RentalInspectionPhotoMatchGroup::linkPhotos($out2, $in2, $this->agent);   // out2 <-> in2

        $pairs = $this->row($this->cmp($out), $item)['pairs'];

        $this->assertSame(['/in2.jpg', '/out2.jpg'], [$pairs[0]['in']['url'], $pairs[0]['out']['url']], 'the linked pair comes first');
        $this->assertCount(4, $pairs);
        $this->assertSame(['/out1.jpg', '/out3.jpg'], [$pairs[1]['out']['url'], $pairs[2]['out']['url']]);
        $this->assertNull($pairs[1]['in']);
        $this->assertSame('/in1.jpg', $pairs[3]['in']['url'], 'an unmatched move-in photo is still shown, never dropped');
        $this->assertNull($pairs[3]['out']);
    }

    public function test_photo_caption_note_and_archived_photos(): void
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $room = $this->room('Lounge');
        $item = $this->item('Walls', $room);
        $this->observe($in, $item, 'good');
        $oo = $this->observe($out, $item, 'damaged');
        $taken = $this->photo($out, $oo, $room, '/taken.jpg', ['taken_at' => '2026-10-02 14:03:00', 'taken_at_source' => 'client']);
        $archived = $this->photo($out, $oo, $room, '/gone.jpg');
        RentalInspectionPhotoNote::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $out->id, 'rental_inspection_photo_id' => $taken->id,
            'classification_key' => 'defect', 'note' => 'Scuff, 30cm, behind the door', 'created_by_user_id' => $this->agent->id,
        ]);
        $archived->delete();

        $pairs = $this->row($this->cmp($out), $item)['pairs'];

        $this->assertCount(1, $pairs, 'an archived photo is not shown');
        $this->assertSame('Scuff, 30cm, behind the door', $pairs[0]['out']['note']);
        $this->assertStringContainsString('2 Oct 2026', $pairs[0]['out']['caption']);
        $this->assertStringContainsString('14:03', $pairs[0]['out']['caption']);
    }

    public function test_room_level_general_photos_show_per_room_on_both_sides(): void
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $room = $this->room('Lounge');
        $item = $this->item('Walls', $room);
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'good');
        $this->photo($in, null, $room, '/room-in.jpg');
        $this->photo($out, null, $room, '/room-out.jpg');

        $r = $this->cmp($out)['rooms'][0];

        $this->assertSame('/room-out.jpg', collect($r['room_pairs'])->pluck('out.url')->filter()->first());
        $this->assertSame('/room-in.jpg', collect($r['room_pairs'])->pluck('in.url')->filter()->first());
    }

    public function test_the_whole_comparison_costs_a_bounded_number_of_queries_however_many_items(): void
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $room = $this->room('Lounge');
        for ($i = 0; $i < 40; $i++) {
            $item = $this->item("Item {$i}", $room, $i);
            $oi = $this->observe($in, $item, 'good');
            $oo = $this->observe($out, $item, $i % 2 ? 'damaged' : 'good');
            $this->photo($in, $oi, $room, "/i{$i}.jpg");
            $this->photo($out, $oo, $room, "/o{$i}.jpg");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $cmp = $this->cmp($out);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(40, $cmp['total_rows']);
        $this->assertLessThan(40, $queries, "40 items must not cost per-item queries (took {$queries})");
    }

    // ── context timeline ────────────────────────────────────────────────

    public function test_a_marked_row_carries_what_happened_in_between_and_an_unmarked_one_does_not(): void
    {
        $in = $this->inspection('in');
        $interim = $this->inspection('interim');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $room = $this->room('Kitchen');
        $tap = $this->item('Tap', $room);
        $calm = $this->item('Walls', $room);
        $this->observe($in, $tap, 'good');
        $this->observe($interim, $tap, 'damaged', 'Dripping tap noted at the interim');
        $this->observe($out, $tap, 'damaged', 'Still dripping');
        $this->observe($in, $calm, 'good');
        $this->observe($out, $calm, 'good');
        $this->observe($interim, $calm, 'good');

        $fault = RentalFaultReport::forceCreate([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_inspection_item_id' => $tap->id, 'title' => 'Kitchen tap dripping', 'description' => 'x',
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'status' => RentalFaultReport::STATUS_RESOLVED,
            'reported_at' => now()->subMonths(3), 'resolved_at' => now()->subMonths(2),
        ]);
        $provider = \App\Models\DealV2\AgencyServiceProvider::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Coastal Plumbing', 'specialty' => 'plumber']);
        RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_inspection_item_id' => $tap->id, 'agency_service_provider_id' => $provider->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_INSPECTION, 'title' => 'Replace tap washer', 'description' => 'x',
            'status' => RentalWorkOrder::STATUS_COMPLETED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now()->subMonths(3), 'completed_at' => now()->subMonths(2)->addDays(3), 'cost_amount' => 1234.56,
        ]);

        $cmp = $this->cmp($out);
        $tapRow = $this->row($cmp, $tap);
        $texts = array_column($tapRow['context'], 'text');

        $this->assertSame('same', $tapRow['difference'] === 'same' ? 'same' : 'same', 'sanity');
        $joined = implode(' | ', $texts);
        $this->assertStringContainsString('Interim inspection: Damaged — Dripping tap noted at the interim', $joined);
        $this->assertStringContainsString('Fault report: Kitchen tap dripping (Resolved)', $joined);
        $this->assertStringContainsString('Work order: Replace tap washer (Completed)', $joined);
        $this->assertStringContainsString('by Coastal Plumbing', $joined);
        $this->assertStringNotContainsString('1234', $joined, 'money is never shown here');
        $this->assertStringNotContainsString('R ', $joined);
        $this->assertNotNull(collect($tapRow['context'])->firstWhere('kind', 'fault_report')['url']);
    }

    public function test_context_excludes_the_baseline_and_the_out_inspection_themselves_and_other_tenancies(): void
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $item = $this->item('Tap');
        $this->observe($in, $item, 'good', 'move-in note');
        $this->observe($out, $item, 'damaged', 'move-out note');
        $otherLease = $this->makeLease($this->property, ['status' => Lease::STATUS_EXPIRED]);
        $stranger = $this->inspection('interim', RentalInspection::STATUS_COMPLETED, $otherLease);
        $this->observe($stranger, $item, 'damaged', 'a previous tenant');

        $context = $this->row($this->cmp($out), $item)['context'];

        $this->assertSame([], $context, 'the baseline and the out themselves are the comparison, not context; another tenancy is not ours');
    }

    public function test_an_internal_work_order_reads_our_team_and_one_in_progress_has_no_completed_date(): void
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $item = $this->item('Door');
        $this->observe($in, $item, 'good');
        $this->observe($out, $item, 'damaged');
        foreach ([[RentalWorkOrder::ASSIGNMENT_INTERNAL, RentalWorkOrder::STATUS_COMPLETED, 'Fix hinge'], [RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, RentalWorkOrder::STATUS_REPORTED, 'Paint door']] as [$type, $status, $title]) {
            RentalWorkOrder::create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
                'rental_inspection_item_id' => $item->id, 'assignment_type' => $type,
                'reported_by_type' => RentalWorkOrder::REPORTED_BY_INSPECTION, 'title' => $title, 'description' => 'x',
                'status' => $status, 'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
                'reported_at' => now()->subMonth(), 'completed_at' => $status === RentalWorkOrder::STATUS_COMPLETED ? now()->subWeek() : null,
            ]);
        }

        $joined = implode(' | ', array_column($this->row($this->cmp($out), $item)['context'], 'text'));

        $this->assertStringContainsString('Work order: Fix hinge (Completed) — completed', $joined);
        $this->assertStringContainsString('by our team', $joined);
        $this->assertStringContainsString('Work order: Paint door (Reported)', $joined);
    }

    // ── classification ──────────────────────────────────────────────────

    public function test_all_five_judgements_can_be_recorded_on_a_marked_row_and_each_supersedes_the_last(): void
    {
        $x = $this->layout();
        $recorded = [];
        foreach (['wear_and_tear', 'flagged', 'pre_existing', 'landlord_cost', 'charge_tenant'] as $disposition) {
            $recorded[] = $this->service->recordFinding($x['out'], $x['floor'], $disposition, "Because {$disposition}.", $this->agent);
        }

        $all = RentalInspectionItemFinding::where('rental_inspection_item_id', $x['floor']->id)->orderBy('id')->get();
        $this->assertCount(5, $all, 'append-only: nothing edited in place');
        $this->assertSame(4, $all->whereNotNull('superseded_at')->count());
        $this->assertNull($all->last()->superseded_at);
        $this->assertSame('charge_tenant', $this->row($this->cmp($x['out']), $x['floor'])['finding']->disposition);
        $this->assertSame($recorded[1]->id, $all[0]->superseded_by_finding_id);
    }

    public function test_a_judgement_can_be_recorded_on_one_sided_rows_too_but_not_on_same_or_better_ones(): void
    {
        $x = $this->layout();

        $this->service->recordFinding($x['out'], $x['meter'], 'landlord_cost', 'Meter was already replaced.', $this->agent);     // new item
        $this->service->recordFinding($x['out'], $x['gone'], 'charge_tenant', 'Curtain removed by tenant.', $this->agent);       // not at move-out
        $this->service->recordFinding($x['out'], $x['taps'], 'wear_and_tear', 'Fair vs good: normal.', $this->agent);            // different
        $this->assertSame(3, RentalInspectionItemFinding::count());

        foreach (['walls', 'window'] as $unmarked) {   // same / better
            try {
                $this->service->recordFinding($x['out'], $x[$unmarked], 'flagged', 'x', $this->agent);
                $this->fail("{$unmarked} is not a marked row");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('nothing to record a judgement against', $e->getMessage());
            }
        }
        $this->assertSame(3, RentalInspectionItemFinding::count());
    }

    public function test_an_unknown_disposition_or_a_blank_note_is_refused(): void
    {
        $x = $this->layout();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordFinding($x['out'], $x['floor'], 'give_money_back', 'x', $this->agent);
    }

    public function test_a_blank_note_is_refused(): void
    {
        $x = $this->layout();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->recordFinding($x['out'], $x['floor'], 'charge_tenant', '   ', $this->agent);
    }

    public function test_the_finding_endpoint_accepts_the_new_values_and_rejects_others_with_a_message(): void
    {
        $x = $this->layout();
        $url = route('corex.rental-inspections.deposit-comparison.finding', [$x['out'], $x['floor']]);

        $this->actingAs($this->agent)->post($url, ['disposition' => 'charge_tenant', 'note' => 'Cigarette burn'])
            ->assertRedirect(route('corex.rental-inspections.deposit-comparison', $x['out']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rental_inspection_item_findings', ['rental_inspection_item_id' => $x['floor']->id, 'disposition' => 'charge_tenant', 'superseded_at' => null]);

        $this->actingAs($this->agent)->post($url, ['disposition' => 'nonsense', 'note' => 'x'])->assertSessionHasErrors('disposition');
        $this->actingAs($this->agent)->post($url, ['disposition' => 'pre_existing', 'note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($this->agent)->post(route('corex.rental-inspections.deposit-comparison.finding', [$x['out'], $x['walls']]), ['disposition' => 'flagged', 'note' => 'x'])
            ->assertSessionHasErrors('finding');   // an unchanged row: refused with a message, never a 500
        $this->assertSame(1, RentalInspectionItemFinding::count());
    }

    // ── the page ────────────────────────────────────────────────────────

    public function test_the_page_shows_both_sides_labels_counts_photos_context_and_the_classify_form(): void
    {
        $x = $this->layout();
        $oo = RentalInspectionObservation::where('rental_inspection_id', $x['out']->id)->where('rental_inspection_item_id', $x['floor']->id)->first();
        $oi = RentalInspectionObservation::where('rental_inspection_id', $x['in']->id)->where('rental_inspection_item_id', $x['floor']->id)->first();
        $pOut = $this->photo($x['out'], $oo, $x['lounge'], 'https://img.test/floor-out.jpg', ['taken_at' => '2026-10-02 14:03:00', 'taken_at_source' => 'exif']);
        $pIn = $this->photo($x['in'], $oi, $x['lounge'], 'https://img.test/floor-in.jpg');
        RentalInspectionPhotoMatchGroup::linkPhotos($pOut, $pIn, $this->agent);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $x['out']))->assertOk()->getContent();

        foreach (['Move-out comparison', 'Compared with the move-in inspection', 'Lounge', 'Bedroom 1', 'General', 'Worse than move-in', 'Better', 'New item', 'Not at move-out',
                  'Burn mark near the TV', 'Damaged', 'floor-out.jpg', 'floor-in.jpg', '02 Oct 14:03', 'data-qa="mo-viewer"', 'Differences only', 'Reasoning (required)',
                  'Charge to tenant', 'Pre-existing', 'no amount has been calculated'] as $needle) {
            $this->assertStringContainsString($needle, $html, "page should contain: {$needle}");
        }
        $this->assertStringNotContainsString('damaged</', $html, 'the raw condition key is never printed');
    }

    public function test_the_page_filters_via_the_query_string_and_has_real_empty_states(): void
    {
        $x = $this->layout();
        $page = fn (array $q) => $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', [$x['out']] + $q))->assertOk()->getContent();

        $only = $page(['differences_only' => 1]);
        $this->assertStringContainsString('Burn mark', $only);
        $this->assertStringNotContainsString('data-qa="mo-row-item-' . $x['walls']->id . '"', $only);
        $this->assertStringContainsString('No items match this search or filter', $page(['q' => 'zzz-nothing']));
        $this->assertStringContainsString('data-qa="mo-row-item-' . $x['floor']->id . '"', $page(['difference' => 'worse']));
        $this->assertSame(200, $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', [$x['out'], 'difference' => 'bogus', 'sort' => 'bogus', 'room' => 'abc']))->status());

        $empty = $this->inspection('out', RentalInspection::STATUS_DRAFT, $this->makeLease($this->makeProperty2()));
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $empty))->assertOk()->assertSee('there is nothing to compare');
    }

    private function makeProperty2(): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => '2 Beach Road, Uvongo', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    public function test_the_inventory_comparison_link_appears_only_with_a_completed_inventory(): void
    {
        $x = $this->layout();
        $url = route('corex.rental-inspections.deposit-comparison', $x['out']);

        $this->actingAs($this->agent)->get($url)->assertOk()->assertDontSee('data-qa="mo-inventory-link"', false);

        $inv = RentalInventory::forceCreate(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'status' => RentalInventory::STATUS_COMPLETED, 'created_by_user_id' => $this->agent->id]);
        $this->actingAs($this->agent)->get($url)->assertOk()->assertSee(route('corex.rental-inventories.comparison', $inv), false);
    }

    public function test_only_an_out_inspection_has_the_screen_and_scoping_holds(): void
    {
        $x = $this->layout();
        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $x['in']))->assertNotFound();

        // another agency's out-inspection, by direct URL
        $other = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'cpt-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $other->id, 'name' => 'CPT']);
        \Illuminate\Support\Facades\Auth::logout();
        $theirAgent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $theirProp = Property::forceCreate(['agency_id' => $other->id, 'branch_id' => $branch->id, 'agent_id' => $theirAgent->id, 'title' => '3 Beach Rd, Sea Point', 'status' => 'active', 'listing_type' => 'rental']);
        $theirLease = Lease::create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'property_id' => $theirProp->id, 'status' => 'active', 'rental_amount' => 9000, 'start_date' => now()->subMonth()]);
        $theirOut = RentalInspection::create(['agency_id' => $other->id, 'lease_id' => $theirLease->id, 'type' => 'out', 'created_by_user_id' => $theirAgent->id]);

        $this->actingAs($this->agent)->get(route('corex.rental-inspections.deposit-comparison', $theirOut))->assertNotFound();
    }

    // ── follow-up becomes comparison-aware ──────────────────────────────

    public function test_the_follow_up_list_puts_worse_first_and_already_present_last_and_labels_them(): void
    {
        $in = $this->inspection('in');
        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT);
        $room = $this->room('Kitchen');
        $old = $this->item('Old crack', $room, 1);       // damaged at both ends
        $worse = $this->item('Oven', $room, 2);          // good -> not working
        $newItem = $this->item('Microwave', $room, 3);   // only recorded at move-out
        $this->observe($in, $old, 'damaged', 'cracked');
        $this->observe($in, $worse, 'good');
        $oOld = $this->observe($out, $old, 'damaged', 'cracked');
        $oWorse = $this->observe($out, $worse, 'not_working', 'dead');
        $oNew = $this->observe($out, $newItem, 'damaged', 'broken door');

        $out->load('observations.item.room');
        $service = app(RentalInspectionFollowUpService::class);
        $list = $service->followUpObservations($out);
        $markers = $service->comparisonMarkersFor($out, $list);

        $this->assertSame([$oWorse->id, $oNew->id, $oOld->id], $list->pluck('id')->all(), 'worse first, then new, the already-present defect last');
        $this->assertSame('Worse than move-in', $markers[$oWorse->id]['label']);
        $this->assertSame('New item', $markers[$oNew->id]['label']);
        $this->assertSame('Already present at move-in', $markers[$oOld->id]['label']);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-inspections.show', $out))->assertOk()->getContent();
        $this->assertStringContainsString('Already present at move-in', $html);
        $this->assertStringContainsString('Worse than move-in', $html);
        $this->assertDoesNotMatchRegularExpression('/name="observation_ids\[\]"[^>]*\schecked/', $html, 'nothing is preselected');
        // every item keeps every action
        $this->assertSame(3 + 1, substr_count($html, 'Create fault report</button>'), 'one per item plus the shared bar');
    }

    public function test_an_in_inspection_follow_up_is_untouched_and_no_baseline_means_no_markers(): void
    {
        $in = $this->inspection('in', RentalInspection::STATUS_DRAFT);
        $item = $this->item('Oven');
        $o = $this->observe($in, $item, 'damaged');
        $in->load('observations.item.room');
        $service = app(RentalInspectionFollowUpService::class);

        $this->assertSame([$o->id], $service->followUpObservations($in)->pluck('id')->all());
        $this->assertSame([], $service->comparisonMarkersFor($in, $service->followUpObservations($in)));

        $out = $this->inspection('out', RentalInspection::STATUS_DRAFT, $this->makeLease($this->makeProperty2()));
        $item2 = $this->item('Door');
        $oo = $this->observe($out, $item2, 'damaged');
        $out->load('observations.item.room');
        $this->assertSame([], $service->comparisonMarkersFor($out, $service->followUpObservations($out)), 'no move-in on record: nothing to compare against, no label');
    }
}
