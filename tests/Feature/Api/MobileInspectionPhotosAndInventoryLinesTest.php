<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\RentalInspection;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileInspectionExtras;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * Photos taken on the phone and photos uploaded on the web are one set (item / room / loose), and an inventory can be typed on the phone.
 * .ai/specs/at-508-mobile-rentals.md "Inspection photos and typed inventory lines".
 */
final class MobileInspectionPhotosAndInventoryLinesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;
    use BuildsMobileInspectionExtras;

    private const INV = '/api/v1/mobile/rental-inventories';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->buildInspectionExtras();
    }

    private function startInspection(string $type = 'in'): int
    {
        return (int) $this->asStaff($this->admin)->postJson($this->base(), ['lease_id' => $this->lease->id, 'type' => $type, 'client_idempotency_key' => $this->uuid()])
            ->assertCreated()->json('id');
    }

    private function snap(int $inspection, array $fields = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->asStaff($this->admin)->post($this->base("/{$inspection}/photos"), $fields + ['photo' => $this->jpeg(), 'client_idempotency_key' => $key ?? $this->uuid()], ['Accept' => 'application/json']);
    }

    private function inventory(array $attrs = []): RentalInventory
    {
        return RentalInventory::create($attrs + [
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'status' => RentalInventory::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    // ── inspection photos: the phone's camera on an item, a room, or the whole inspection ───────────────────

    public function test_a_photo_taken_on_an_item_is_filed_to_that_item_without_waiting_for_a_condition(): void
    {
        $id = $this->startInspection();

        $res = $this->snap($id, ['item_id' => $this->ceiling->id])->assertCreated();
        $res->assertJsonPath('duplicate', false)->assertJsonPath('item_id', $this->ceiling->id)->assertJsonPath('room_id', $this->room->id);
        $this->assertNotNull($res->json('url'));
        $this->assertSame($this->ceiling->id, $res->json('observation.item_id'), 'the item gets its (pending) observation on the spot, like the web');
        $this->assertSame(RentalInspectionObservation::CONDITION_PENDING, $res->json('observation.condition'));

        $detail = $this->asStaff($this->admin)->getJson($this->base("/{$id}"))->assertOk();
        $this->assertCount(1, $detail->json('photos'));
        $this->assertSame($this->ceiling->id, $detail->json('photos.0.item_id'));
        $this->assertSame($this->ceiling->id, $detail->json('observations.0.photos.0.item_id'));
    }

    public function test_a_room_photo_and_a_loose_photo_are_kept_as_the_web_keeps_them(): void
    {
        $id = $this->startInspection();

        $room = $this->snap($id, ['room_id' => $this->room->id])->assertCreated();
        $room->assertJsonPath('room_id', $this->room->id)->assertJsonPath('item_id', null)->assertJsonPath('observation_id', null);

        $loose = $this->snap($id)->assertCreated();
        $loose->assertJsonPath('room_id', null)->assertJsonPath('item_id', null)->assertJsonPath('observation_id', null);

        $detail = $this->asStaff($this->admin)->getJson($this->base("/{$id}"))->assertOk();
        $this->assertCount(2, $detail->json('photos'), 'every photo on the inspection comes back, tagged or not');
    }

    public function test_a_photo_uploaded_on_the_web_comes_through_to_the_phone(): void
    {
        $id = $this->startInspection();
        // exactly what the web's tray / room upload writes: a photo on the inspection with no observation
        RentalInspectionPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $id, 'property_room_id' => $this->room->id, 'storage_path' => 'https://corex.test/storage/x.jpg',
            'uploaded_by_user_id' => $this->admin->id,
        ]);

        $detail = $this->asStaff($this->admin)->getJson($this->base("/{$id}"))->assertOk();
        $this->assertCount(1, $detail->json('photos'));
        $this->assertSame($this->room->id, $detail->json('photos.0.room_id'));
        $this->assertNull($detail->json('photos.0.item_id'));
        $this->assertSame('https://corex.test/storage/x.jpg', $detail->json('photos.0.url'));
    }

    public function test_a_photo_sent_twice_is_stored_once_and_an_unknown_item_is_refused(): void
    {
        $id = $this->startInspection();
        $key = $this->uuid();

        $this->snap($id, ['item_id' => $this->floor->id], $key)->assertCreated();
        $again = $this->snap($id, ['item_id' => $this->floor->id], $key)->assertOk();
        $again->assertJsonPath('duplicate', true);
        $this->assertSame(1, RentalInspectionPhoto::withoutGlobalScopes()->where('rental_inspection_id', $id)->count());

        $this->snap($id, ['item_id' => 999999])->assertNotFound();   // an item that is not on this property is refused, exactly as on the web
        $this->asStaff($this->admin)->post($this->base("/{$id}/photos"), ['client_idempotency_key' => $this->uuid()], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_a_photo_cannot_be_put_on_another_agencys_inspection(): void
    {
        $id = $this->startInspection();
        $this->grantEveryone();

        $this->asStaff($this->outsider)->post($this->base("/{$id}/photos"), ['photo' => $this->jpeg(), 'client_idempotency_key' => $this->uuid()], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_a_move_out_inspection_takes_photos_the_same_way(): void
    {
        $id = $this->startInspection('out');

        $this->snap($id, ['item_id' => $this->floor->id])->assertCreated()->assertJsonPath('item_id', $this->floor->id);
        $this->assertSame('out', $this->asStaff($this->admin)->getJson($this->base("/{$id}"))->json('type'));
        $this->assertSame(RentalInspection::TYPE_OUT, RentalInspection::withoutGlobalScopes()->find($id)->type);
    }

    // ── inventory: type a line on the phone ─────────────────────────────────────────────────────────────────

    public function test_a_line_can_be_typed_changed_and_removed_on_the_phone(): void
    {
        $inv = $this->inventory();
        $key = $this->uuid();

        $made = $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/lines", [
            'client_idempotency_key' => $key, 'property_room_id' => $this->room->id, 'description' => 'Wooden bed', 'quantity' => 1,
        ])->assertCreated();
        $made->assertJsonPath('duplicate', false)->assertJsonPath('description', 'Wooden bed')->assertJsonPath('room_label', 'Bedroom 1');
        $lineId = $made->json('id');

        // the same tap sent again (poor signal) is the same line
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/lines", [
            'client_idempotency_key' => $key, 'property_room_id' => $this->room->id, 'description' => 'Wooden bed', 'quantity' => 1,
        ])->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $lineId);
        $this->assertSame(1, RentalInventoryLine::withoutGlobalScopes()->where('rental_inventory_id', $inv->id)->count());

        $this->asStaff($this->admin)->putJson(self::INV . "/{$inv->id}/lines/{$lineId}", ['property_room_id' => $this->room->id, 'description' => 'Double wooden bed', 'quantity' => 2])
            ->assertOk()->assertJsonPath('description', 'Double wooden bed')->assertJsonPath('quantity', 2);

        $detail = $this->asStaff($this->admin)->getJson(self::INV . "/{$inv->id}")->assertOk();
        $this->assertSame(['Double wooden bed'], $detail->json('lines.*.description'));

        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/lines/{$lineId}/retire")->assertOk()->assertJsonPath('duplicate', false);
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/lines/{$lineId}/retire")->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame([], $this->asStaff($this->admin)->getJson(self::INV . "/{$inv->id}")->json('lines'));
        $this->assertTrue(RentalInventoryLine::withoutGlobalScopes()->find($lineId)->is_retired, 'kept on record, never deleted');
    }

    public function test_a_line_needs_a_description_and_a_room_and_a_retry_key(): void
    {
        $inv = $this->inventory();

        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/lines", ['client_idempotency_key' => $this->uuid(), 'room_label' => 'Kitchen'])->assertStatus(422);
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/lines", ['client_idempotency_key' => $this->uuid(), 'description' => 'Fridge'])->assertStatus(422);
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/lines", ['room_label' => 'Kitchen', 'description' => 'Fridge'])->assertStatus(422);
        $this->assertSame(0, RentalInventoryLine::withoutGlobalScopes()->where('rental_inventory_id', $inv->id)->count());

        // a typed room name is fine when the app has no room to pick
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/lines", ['client_idempotency_key' => $this->uuid(), 'room_label' => 'Kitchen', 'description' => 'Fridge'])->assertCreated();
    }

    public function test_a_completed_inventory_cannot_be_typed_into_and_another_agency_cannot_reach_it(): void
    {
        $done = $this->inventory(['status' => RentalInventory::STATUS_COMPLETED]);
        $this->asStaff($this->admin)->postJson(self::INV . "/{$done->id}/lines", ['client_idempotency_key' => $this->uuid(), 'room_label' => 'Kitchen', 'description' => 'Fridge'])
            ->assertStatus(409);

        $inv = $this->inventory();
        $this->grantEveryone();
        $this->asStaff($this->outsider)->postJson(self::INV . "/{$inv->id}/lines", ['client_idempotency_key' => $this->uuid(), 'room_label' => 'Kitchen', 'description' => 'Fridge'])
            ->assertNotFound();
    }

    public function test_a_person_who_may_only_view_inventories_cannot_type_into_one(): void
    {
        $inv = $this->inventory();
        $this->grantEveryone();
        $viewer = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);

        $this->asStaff($viewer)->postJson(self::INV . "/{$inv->id}/lines", ['client_idempotency_key' => $this->uuid(), 'room_label' => 'Kitchen', 'description' => 'Fridge'])
            ->assertForbidden();
    }
}
