<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryPhoto;
use App\Models\RentalInventorySignature;
use App\Models\User;
use App\Services\Rentals\RentalInventoryComparisonService;
use App\Services\Rentals\RentalInventoryReportPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit 2026-10-19 B32 - one photo set per room, shared by the inventory and the inspection. A room photo is stored once (in the inspection
 * store); the inventory keeps a reference to it, and every reader of inventory photos - the capture screen, tagging to a line, the move-in and
 * move-out comparison, the public page, the report - sees it through the same rows.
 */
final class RentalInventoryRoomPhotoSetTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private PropertyRoom $lounge;
    private PropertyRoom $kitchen;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Room Photos Agency', 'slug' => 'room-photos-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '3 Shared Photo Lane', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 11000, 'start_date' => now()->subMonths(3),
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->lounge = $this->room('lounge', 'Lounge', 1);
        $this->kitchen = $this->room('kitchen', 'Kitchen', 2);

        $this->actingAs($this->agent);
    }

    private function jpegBytes(): string
    {
        $img = imagecreatetruecolor(200, 100);
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    private function room(string $type, string $label, int $order): PropertyRoom
    {
        return PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => $type, 'label' => $label, 'source' => 'manual', 'sort_order' => $order, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function inspection(string $type = 'in', string $status = RentalInspection::STATUS_COMPLETED, ?Lease $lease = null): RentalInspection
    {
        $lease ??= $this->lease;

        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $lease->property_id,
            'type' => $type, 'status' => $status, 'created_by_user_id' => $this->agent->id,
            'completed_at' => $status === RentalInspection::STATUS_COMPLETED ? now() : null,
        ]);
    }

    private function inspectionPhoto(RentalInspection $inspection, ?PropertyRoom $room, array $extra = []): RentalInspectionPhoto
    {
        return RentalInspectionPhoto::create(array_merge([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id,
            'property_room_id' => $room?->id,
            'storage_path' => 'private:private-images/rental-inspections/' . $this->agency->id . '/' . $inspection->id . '/' . uniqid() . '.jpg',
            'uploaded_by_user_id' => $this->agent->id,
        ], $extra));
    }

    private function inventoryWithLine(): array
    {
        $inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
        $line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'created_by_user_id' => $this->agent->id,
        ]);

        return [$inventory, $line];
    }

    // ── the shared photo ─────────────────────────────────────────────────

    public function test_the_move_in_inspections_room_photos_are_offered_and_can_be_shared_once(): void
    {
        [$inventory, $line] = $this->inventoryWithLine();
        $in = $this->inspection('in');
        $loungePhoto = $this->inspectionPhoto($in, $this->lounge);
        $this->inspectionPhoto($in, $this->kitchen);

        $offered = $this->getJson(route('corex.rental-inventories.inspection-photos.index', [$inventory, 'property_room_id' => $this->lounge->id]))
            ->assertOk()->json('photos');
        $this->assertSame([$loungePhoto->id], array_column($offered, 'id'), 'only this room, only the inspection photos');
        $this->assertStringContainsString('/private-image/', $offered[0]['storage_path'], 'a signed link, never the stored reference');

        $first = $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $loungePhoto->id]), ['rental_inventory_line_id' => $line->id])
            ->assertCreated()->json('photo');
        $this->assertTrue($first['from_inspection']);
        $this->assertSame([$line->id], $first['lines'], 'shared and tagged to the line in one request');
        $this->assertSame($this->lounge->id, $first['property_room_id']);

        $again = $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $loungePhoto->id]))->assertCreated()->json('photo');
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, RentalInventoryPhoto::withTrashed()->where('rental_inventory_id', $inventory->id)->count(), 'one row, never a second copy');
        $this->assertSame(0, RentalInventoryPhoto::withTrashed()->whereNotNull('storage_path')->count(), 'the reference holds no file of its own');

        $this->getJson(route('corex.rental-inventories.inspection-photos.index', [$inventory, 'property_room_id' => $this->lounge->id]))
            ->assertOk()->assertJsonCount(0, 'photos');
    }

    public function test_a_shared_photo_reads_through_the_same_rows_as_an_own_photo(): void
    {
        [$inventory, $line] = $this->inventoryWithLine();
        $photo = $this->inspectionPhoto($this->inspection('in'), $this->lounge);
        $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $photo->id]), ['rental_inventory_line_id' => $line->id])->assertCreated();

        $own = RentalInventoryPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id, 'property_room_id' => $this->lounge->id,
            'storage_path' => '/storage/properties/1/legacy.jpg', 'uploaded_by_user_id' => $this->agent->id,
        ]);
        $line->photos()->attach($own->id, ['agency_id' => $this->agency->id]);

        $row = collect((new RentalInventoryComparisonService())->compare($inventory))->firstWhere('line_id', $line->id);
        $this->assertCount(2, $row['photos']);
        $this->assertContains('/storage/properties/1/legacy.jpg', array_column($row['photos'], 'storage_path'), 'a legacy public photo still reads as it always did');
        $shared = collect($row['photos'])->firstWhere('from_inspection', true);
        $this->assertNotNull($shared);
        $this->assertStringContainsString('/private-image/', $shared['storage_path']);

        $page = $this->get(route('corex.properties.inventory.show', $this->property))->assertOk();
        $page->assertSee('Use inspection photos');
    }

    public function test_only_this_tenancys_recorded_move_in_photos_can_be_shared(): void
    {
        [$inventory] = $this->inventoryWithLine();

        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '9 Other Road', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $otherLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subMonths(2), 'created_by_user_id' => $this->agent->id,
        ]);
        $foreignRoom = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $otherProperty->id, 'type' => 'lounge', 'label' => 'Lounge', 'source' => 'manual',
            'sort_order' => 1, 'created_by_user_id' => $this->agent->id,
        ]);

        $good = $this->inspectionPhoto($this->inspection('in'), $this->lounge);
        $forbidden = [
            'another property' => $this->inspectionPhoto($this->inspection('in', RentalInspection::STATUS_COMPLETED, $otherLease), $foreignRoom),
            'a move-out inspection' => $this->inspectionPhoto($this->inspection('out', RentalInspection::STATUS_IN_PROGRESS), $this->lounge),
            'a cancelled inspection' => $this->inspectionPhoto($this->inspection('in', RentalInspection::STATUS_CANCELLED), $this->lounge),
            'a photo with no room' => $this->inspectionPhoto($this->inspection('in'), null),
            'a photo added after signing' => $this->inspectionPhoto($this->inspection('in'), $this->lounge, ['added_after_signing_at' => now()]),
        ];

        $offered = $this->getJson(route('corex.rental-inventories.inspection-photos.index', $inventory))->assertOk()->json('photos');
        $this->assertSame([$good->id], array_column($offered, 'id'));

        foreach ($forbidden as $why => $photo) {
            $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $photo->id]))->assertNotFound();
            $this->assertSame(0, RentalInventoryPhoto::withTrashed()->where('rental_inspection_photo_id', $photo->id)->count(), $why);
        }
    }

    public function test_archiving_a_shared_photo_leaves_the_inspection_photo_and_it_can_be_shared_again(): void
    {
        [$inventory, $line] = $this->inventoryWithLine();
        $source = $this->inspectionPhoto($this->inspection('in'), $this->lounge);
        $shared = $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $source->id]), ['rental_inventory_line_id' => $line->id])->json('photo');

        $this->deleteJson(route('corex.rental-inventories.photos.destroy', [$inventory, $shared['id']]))->assertOk();
        $this->assertNull(RentalInspectionPhoto::find($source->id)?->deleted_at, 'the inspection keeps its photo');
        $this->assertNotNull(RentalInventoryPhoto::withTrashed()->find($shared['id'])->deleted_at, 'archived, not deleted');

        $back = $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $source->id]))->assertCreated()->json('photo');
        $this->assertSame($shared['id'], $back['id'], 'the archived row comes back, not a second one');
        $this->assertNull(RentalInventoryPhoto::find($back['id'])->deleted_at);
    }

    public function test_archiving_the_inspection_photo_takes_it_out_of_the_inventory_everywhere(): void
    {
        [$inventory, $line] = $this->inventoryWithLine();
        $source = $this->inspectionPhoto($this->inspection('in'), $this->lounge);
        $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $source->id]), ['rental_inventory_line_id' => $line->id])->assertCreated();
        $this->assertCount(1, $inventory->fresh()->photos);
        $this->assertCount(1, $line->fresh()->moveInPhotos);

        $source->delete();

        $this->assertCount(0, $inventory->fresh()->photos, 'no tile with no picture');
        $this->assertCount(0, $line->fresh()->moveInPhotos);
        $this->assertSame([], collect((new RentalInventoryComparisonService())->compare($inventory->fresh()))->firstWhere('line_id', $line->id)['photos']);

        $source->restore();
        $this->assertCount(1, $line->fresh()->moveInPhotos, 'restoring the inspection photo brings the shared one back');
    }

    public function test_sharing_needs_the_right_to_see_inspections(): void
    {
        [$inventory] = $this->inventoryWithLine();
        $photo = $this->inspectionPhoto($this->inspection('in'), $this->lounge);

        // an agent who may record inventories but may not see inspections
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        DB::table('role_permissions')->insert(array_map(fn ($key) => [
            'role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => 'all', 'created_at' => now(), 'updated_at' => now(),
        ], ['rental_inventories.view', 'rental_inventories.create']));
        $this->actingAs($other);

        $this->getJson(route('corex.rental-inventories.inspection-photos.index', $inventory))->assertForbidden();
        $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $photo->id]))->assertForbidden();
    }

    public function test_a_completed_inventory_takes_no_more_move_in_photos(): void
    {
        [$inventory] = $this->inventoryWithLine();
        $photo = $this->inspectionPhoto($this->inspection('in'), $this->lounge);
        \App\Models\RentalInventoryRoomMark::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id, 'property_room_id' => $this->kitchen->id,
            'marked_empty_by_user_id' => $this->agent->id, 'marked_empty_at' => now(),
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/agent-signature.png', 'recorded_by_user_id' => $this->agent->id,
        ]);
        $inventory->markCompleted();

        $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $photo->id]))->assertStatus(409);
    }

    // ── move-out ─────────────────────────────────────────────────────────

    public function test_a_move_out_inspection_photo_can_be_the_lines_move_out_evidence(): void
    {
        [$inventory, $line] = $this->inventoryWithLine();
        \App\Models\RentalInventoryRoomMark::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id, 'property_room_id' => $this->kitchen->id,
            'marked_empty_by_user_id' => $this->agent->id, 'marked_empty_at' => now(),
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/agent-signature.png', 'recorded_by_user_id' => $this->agent->id,
        ]);
        $inventory->markCompleted();
        $out = $this->inspection('out', RentalInspection::STATUS_IN_PROGRESS);
        $outPhoto = $this->inspectionPhoto($out, $this->lounge);
        $kitchenPhoto = $this->inspectionPhoto($out, $this->kitchen);
        $inPhoto = $this->inspectionPhoto($this->inspection('in'), $this->lounge);

        $offered = $this->getJson(route('corex.rental-inventories.lines.move-out-inspection-photos.index', [$inventory, $line]))->assertOk()->json('photos');
        $this->assertSame([$outPhoto->id], array_column($offered, 'id'), 'the Out inspection, this line\'s room only');

        $this->postJson(route('corex.rental-inventories.lines.move-out-inspection-photos.share', [$inventory, $line, $kitchenPhoto->id]))->assertNotFound();
        $this->postJson(route('corex.rental-inventories.lines.move-out-inspection-photos.share', [$inventory, $line, $inPhoto->id]))->assertNotFound();
        $this->postJson(route('corex.rental-inventories.lines.move-out-inspection-photos.share', [$inventory, $line, $outPhoto->id]))->assertCreated();

        $row = collect((new RentalInventoryComparisonService())->compare($inventory->fresh()))->firstWhere('line_id', $line->id);
        $this->assertCount(1, $row['move_out_photos']);
        $this->assertTrue($row['move_out_photos'][0]['from_inspection']);
        $this->assertSame([], $row['photos'], 'it is move-out evidence, not move-in');

        $this->get(route('corex.rental-inventories.comparison', $inventory))->assertOk()->assertSee('From the move-out inspection');
    }

    // ── new uploads, old photos, the public page and the report ─────────

    public function test_a_new_inventory_photo_goes_to_the_private_store_and_reads_as_a_signed_link(): void
    {
        [$inventory] = $this->inventoryWithLine();

        $response = $this->postJson(route('corex.rental-inventories.photos.store', $inventory), [
            'property_room_id' => $this->lounge->id,
            'photos' => [UploadedFile::fake()->image('lounge.jpg', 400, 300)],
        ])->assertCreated();

        $photo = RentalInventoryPhoto::firstOrFail();
        $this->assertStringStartsWith('private:private-images/rental-inventories/' . $this->agency->id . '/' . $inventory->id . '/', $photo->rawStoragePath());
        Storage::disk('local')->assertExists(substr($photo->rawStoragePath(), strlen('private:')));
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nothing on the public disk');
        $this->assertStringContainsString('/private-image/', $response->json('photos.0.storage_path'));
        $this->assertFalse($response->json('photos.0.from_inspection'));
    }

    public function test_the_public_page_and_the_report_include_a_shared_photo(): void
    {
        [$inventory, $line] = $this->inventoryWithLine();
        $source = $this->inspectionPhoto($this->inspection('in'), $this->lounge);
        $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $source->id]), ['rental_inventory_line_id' => $line->id])->assertCreated();

        $inventory->forceFill(['public_token' => 'tok-' . uniqid(), 'public_token_expires_at' => now()->addDays(30)])->save();
        \Illuminate\Support\Facades\Auth::logout();
        $this->get(route('rental-inventories.public.show', $inventory->public_token))
            ->assertOk()->assertSee('/private-image/', false)->assertSee('Photo from the inspection', false);

        $this->actingAs($this->agent);
        $pdf = app(RentalInventoryReportPdfService::class)->generate($inventory->fresh());
        $this->assertStringStartsWith('%PDF', $pdf->output());
    }

    public function test_the_privatise_command_moves_legacy_inventory_photos_and_skips_shared_ones(): void
    {
        [$inventory] = $this->inventoryWithLine();
        Storage::disk('public')->put('properties/' . $this->property->id . '/old.jpg', $this->jpegBytes());
        $legacy = RentalInventoryPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id, 'property_room_id' => $this->lounge->id,
            'storage_path' => '/storage/properties/' . $this->property->id . '/old.jpg', 'uploaded_by_user_id' => $this->agent->id,
        ]);
        $source = $this->inspectionPhoto($this->inspection('in'), $this->lounge);
        $this->postJson(route('corex.rental-inventories.inspection-photos.share', [$inventory, $source->id]))->assertCreated();

        $this->artisan('rental-inspections:privatise-photos', ['--inventory' => true, '--remove-public' => true])->assertSuccessful();

        $legacy->refresh();
        $this->assertStringStartsWith('private:private-images/rental-inventories/', $legacy->rawStoragePath());
        $this->assertStringContainsString('/private-image/', $legacy->storage_path);
        Storage::disk('public')->assertMissing('properties/' . $this->property->id . '/old.jpg');
        $this->assertNull(RentalInventoryPhoto::where('rental_inspection_photo_id', $source->id)->first()->rawStoragePath(), 'a shared photo is left alone');
    }
}
