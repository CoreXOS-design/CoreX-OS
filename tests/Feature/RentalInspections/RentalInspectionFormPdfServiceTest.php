<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionForm;
use App\Models\RentalInspectionItem;
use App\Models\User;
use App\Services\Rentals\RentalInspectionFormPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The printable tick-box form — Johan: "we read the form back by OMR —
 * COLUMN MARKING ONLY." This proves the CONTRACT a separate lane's OMR
 * reader depends on: the manifest's box count matches what was actually
 * drawn, every box carries the item/condition it represents, versioning
 * is idempotent until the room/item shape genuinely changes, and old
 * versions are retained (never deleted) once a new one is generated.
 */
final class RentalInspectionFormPdfServiceTest extends TestCase
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
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'RI Form Agency', 'slug' => 'ri-form-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'RI Form Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function addRoomWithItems(string $label, int $count): PropertyRoom
    {
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => $label,
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        for ($i = 1; $i <= $count; $i++) {
            RentalInspectionItem::create([
                'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id,
                'kind' => RentalInspectionItem::KIND_SPACE, 'label' => "{$label} item {$i}", 'space_type' => 'Bedroom',
                'source' => 'manual', 'sort_order' => $i, 'created_by_user_id' => $this->agent->id,
            ]);
        }

        return $room;
    }

    private function inspection(): RentalInspection
    {
        return RentalInspection::start($this->property, RentalInspection::TYPE_IN, $this->agent);
    }

    // ── The contract: manifest box count == what was actually drawn ────

    public function test_manifest_box_count_matches_page_count_boxes_and_the_db_column(): void
    {
        $this->addRoomWithItems('Bedroom 1', 5);
        $inspection = $this->inspection();

        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);

        // 5 items x 7 default condition states (RentalInspectionSetting::DEFAULT_CONDITION_STATES).
        $this->assertSame(35, $form->box_count);
        $this->assertCount(35, $form->manifest_json['boxes']);
        $this->assertSame($form->page_count, $form->manifest_json['page_count']);
        $this->assertGreaterThanOrEqual(1, $form->page_count);
        Storage::disk('local')->assertExists($form->pdf_storage_path);
    }

    public function test_every_box_carries_the_item_id_and_a_valid_condition_key(): void
    {
        $room = $this->addRoomWithItems('Bedroom 1', 2);
        $inspection = $this->inspection();

        $form = app(RentalInspectionFormPdfService::class)->generate($inspection, $this->agent);

        $itemIds = RentalInspectionItem::where('property_room_id', $room->id)->pluck('id')->all();
        $conditionKeys = collect(\App\Models\RentalInspectionSetting::conditionStatesFor($this->agency->id))->pluck('key')->all();

        foreach ($form->manifest_json['boxes'] as $box) {
            $this->assertContains($box['rental_inspection_item_id'], $itemIds);
            $this->assertContains($box['condition_key'], $conditionKeys);
            // is_numeric, not assertIsFloat — a whole-number pt position
            // (e.g. x=166.0) round-trips through JSON as a plain int, not a
            // float; the manifest's numeric TYPE is never part of the
            // contract, only its value.
            $this->assertIsNumeric($box['x']);
            $this->assertIsNumeric($box['y']);
            $this->assertGreaterThan(0, $box['width']);
            $this->assertGreaterThan(0, $box['height']);
        }
    }

    public function test_coordinate_space_is_stated_explicitly(): void
    {
        $this->addRoomWithItems('Bedroom 1', 1);
        $form = app(RentalInspectionFormPdfService::class)->generate($this->inspection(), $this->agent);

        $space = $form->manifest_json['coordinate_space'];
        $this->assertSame('top-left', $space['origin']);
        $this->assertStringContainsString('pt', $space['unit']);
        $this->assertArrayHasKey('recommended_min_scan_dpi', $space);
    }

    public function test_every_page_has_four_fiducials_and_one_page_identifier(): void
    {
        $this->addRoomWithItems('Bedroom 1', 1);
        $form = app(RentalInspectionFormPdfService::class)->generate($this->inspection(), $this->agent);

        $this->assertCount($form->page_count * 4, $form->manifest_json['fiducials']);
        $this->assertCount($form->page_count, $form->manifest_json['page_identifiers']);
        foreach ($form->manifest_json['page_identifiers'] as $pid) {
            $this->assertCount(40, $pid['bits']); // 24 (inspection id) + 8 (page) + 8 (version)
        }
    }

    // ── Versioning ───────────────────────────────────────────────────────

    public function test_regenerating_with_no_item_list_change_returns_the_same_version(): void
    {
        $this->addRoomWithItems('Bedroom 1', 3);
        $inspection = $this->inspection();
        $service = app(RentalInspectionFormPdfService::class);

        $first = $service->generate($inspection, $this->agent);
        $second = $service->generate($inspection, $this->agent);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RentalInspectionForm::where('rental_inspection_id', $inspection->id)->count());
    }

    public function test_adding_an_item_produces_a_new_version_and_retains_the_old_one(): void
    {
        $room = $this->addRoomWithItems('Bedroom 1', 2);
        $inspection = $this->inspection();
        $service = app(RentalInspectionFormPdfService::class);

        $v1 = $service->generate($inspection, $this->agent);

        RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'A new facet', 'space_type' => 'Bedroom',
            'source' => 'manual', 'sort_order' => 99, 'created_by_user_id' => $this->agent->id,
        ]);

        $v2 = $service->generate($inspection, $this->agent);

        $this->assertNotSame($v1->id, $v2->id);
        $this->assertSame(2, $v2->version);
        // Never deleted — old version stays a real, findable row.
        $this->assertNotNull(RentalInspectionForm::find($v1->id));
        Storage::disk('local')->assertExists($v1->pdf_storage_path);
        Storage::disk('local')->assertExists($v2->pdf_storage_path);
    }

    // ── Controller / scoping ─────────────────────────────────────────────

    public function test_download_route_returns_a_pdf(): void
    {
        $this->addRoomWithItems('Bedroom 1', 1);
        $inspection = $this->inspection();

        $response = $this->get(route('corex.rental-inspections.form', $inspection));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_download_route_404s_for_an_inspection_in_a_different_agency(): void
    {
        $this->addRoomWithItems('Bedroom 1', 1);
        $inspection = $this->inspection();

        // BelongsToAgency::creating() force-stamps a new row's agency_id from
        // the CURRENTLY authenticated user (correct tenant-spoofing defence —
        // an already-scoped user must never be able to create a row in
        // another agency by just passing a different agency_id). setUp()'s
        // actingAs($this->agent) is still in effect here, so $otherAgent must
        // be created while logged out — same pattern
        // RentalInspectionListScreenTest::test_cross_agency_inspection_is_not_reachable_by_id()
        // already uses for exactly this reason.
        \Illuminate\Support\Facades\Auth::logout();
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $otherAgent = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);

        $this->actingAs($otherAgent)->get(route('corex.rental-inspections.form', $inspection))
            ->assertNotFound();
    }

    // ── Overflow must fail loudly, never silently wrap ──────────────────
    //
    // The 24-bit inspection-id field maxes out at 16,777,215. A value that
    // doesn't fit must never be silently truncated to its low 24 bits —
    // that would encode a DIFFERENT, WRONG inspection id with no error, and
    // the printed form would scan back onto the wrong inspection.

    public function test_encoding_a_value_that_overflows_its_bit_field_throws(): void
    {
        $service = app(RentalInspectionFormPdfService::class);
        $toBits = new \ReflectionMethod($service, 'toBits');
        $toBits->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not fit in 24 bits');
        $toBits->invoke($service, 16_777_216, 24); // 2^24 — one past the 24-bit max.
    }

    public function test_encoding_the_maximum_value_that_fits_does_not_throw(): void
    {
        $service = app(RentalInspectionFormPdfService::class);
        $toBits = new \ReflectionMethod($service, 'toBits');
        $toBits->setAccessible(true);

        $bits = $toBits->invoke($service, 16_777_215, 24); // 2^24 - 1 — the 24-bit max.
        $this->assertCount(24, $bits);
        $this->assertSame(1, $bits[0]); // MSB set, as expected for the max value.
    }
}
