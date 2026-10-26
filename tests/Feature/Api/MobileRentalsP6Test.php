<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\RentalInspection;
use App\Models\RentalInspectionPlannedDate;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileInspectionExtras;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * AT-508 P6 — the inspections-due list and staff inventories (list, detail, add a photo) for the app.
 * .ai/specs/at-508-mobile-rentals.md §4 P6 / §5 / §6.
 */
final class MobileRentalsP6Test extends TestCase
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

    private function inventory(array $attrs = []): RentalInventory
    {
        $createdAt = $attrs['created_at'] ?? null;
        unset($attrs['created_at']);
        $inventory = RentalInventory::create($attrs + [
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'status' => RentalInventory::STATUS_DRAFT, 'created_by_user_id' => $this->agent->id,
        ]);
        if ($createdAt) {
            $inventory->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $inventory;
    }

    private function upload(RentalInventory $inv, array $over = [], $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->asStaff($as ?? $this->admin)->post(self::INV . "/{$inv->id}/photos", $over + ['photo' => $this->jpeg(), 'client_idempotency_key' => $this->uuid()], ['Accept' => 'application/json']);
    }

    // ═══ due list ═════════════════════════════════════════════════════════════════════════════

    public function test_the_due_list_shows_the_in_and_out_inspections_that_are_due_with_the_web_lists_rows(): void
    {
        $res = $this->asStaff($this->admin)->getJson($this->base('/due'))->assertOk();
        $rows = collect($res->json('data'));

        $this->assertSame(['in', 'out'], $rows->pluck('type')->all(), 'soonest due first: the move-in that is already past, then the move-out at the end of the term');
        $in = $rows->firstWhere('type', 'in');
        $this->assertSame('overdue', $in['state']);
        $this->assertSame('Overdue', $in['state_label']);
        $this->assertSame($this->lease->id, $in['lease_id']);
        $this->assertSame('Tina Test', $in['tenant_names']);
        $this->assertStringContainsString('12 Sea Road', $in['property']['address']);
        $this->assertGreaterThan(0, $in['days_overdue']);
        $this->assertSame(1, $res->json('meta.page'));
        $this->assertSame(2, $res->json('meta.total'));
        $this->assertNull($res->json('meta.empty_reason'));

        // starting the move-in from the phone satisfies it, exactly as on the web
        $this->asStaff($this->admin)->postJson($this->base(), ['lease_id' => $this->lease->id, 'type' => 'in', 'client_idempotency_key' => $this->uuid()])->assertCreated();
        $this->assertSame(['out'], collect($this->asStaff($this->admin)->getJson($this->base('/due'))->json('data'))->pluck('type')->all());
    }

    public function test_the_due_list_searches_sorts_filters_and_loaded_interim_dates_appear(): void
    {
        $planned = RentalInspectionPlannedDate::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id, 'type' => 'interim',
            'planned_on' => now()->addDays(10)->toDateString(), 'status' => 'planned', 'note' => 'Mid-term check', 'created_by_user_id' => $this->agent->id,
        ]);

        $all = $this->asStaff($this->admin)->getJson($this->base('/due'))->assertOk();
        $this->assertSame(['in', 'interim', 'out'], $all->json('data.*.type'));
        $interim = collect($all->json('data'))->firstWhere('type', 'interim');
        $this->assertSame($planned->id, $interim['planned_date_id']);
        $this->assertSame('Mid-term check', $interim['note']);

        $this->assertSame(['out', 'interim', 'in'], $this->asStaff($this->admin)->getJson($this->base('/due?direction=desc'))->json('data.*.type'));
        $this->assertSame(['in', 'interim', 'out'], $this->asStaff($this->admin)->getJson($this->base('/due?sort=type&direction=asc'))->json('data.*.type'));
        $this->assertSame(['out'], $this->asStaff($this->admin)->getJson($this->base('/due?type=out'))->json('data.*.type'));
        $this->assertSame(['in'], $this->asStaff($this->admin)->getJson($this->base('/due?status=overdue'))->json('data.*.type'));
        $range = '?date_from=' . now()->addDays(5)->toDateString() . '&date_to=' . now()->addDays(15)->toDateString();
        $this->assertSame(['interim'], $this->asStaff($this->admin)->getJson($this->base('/due' . $range))->json('data.*.type'));
        $this->assertCount(3, $this->asStaff($this->admin)->getJson($this->base('/due?q=Tina'))->json('data'));

        $none = $this->asStaff($this->admin)->getJson($this->base('/due?q=Nowhere+Lane'))->assertOk();
        $this->assertSame([], $none->json('data'));
        $this->assertSame('No due inspections match your search or filters.', $none->json('meta.empty_reason'));

        $this->asStaff($this->admin)->getJson($this->base('/due?type=ad_hoc'))->assertStatus(422);
        $this->asStaff($this->admin)->getJson($this->base('/due?sort=bogus'))->assertStatus(422);
        $this->asStaff($this->admin)->getJson($this->base('/due?date_from=not-a-date'))->assertStatus(422);
    }

    public function test_the_due_list_pages_at_25_and_is_scoped_by_the_same_rules_as_the_web_tab(): void
    {
        $this->grantEveryone();

        $mine = $this->asStaff($this->agent)->getJson($this->base('/due'))->assertOk();
        $this->assertSame(2, $mine->json('meta.total'));                                                    // the property's agent / lease creator
        $this->assertSame(0, $this->asStaff($this->colleague)->getJson($this->base('/due'))->json('meta.total'));          // own scope, not theirs
        $this->assertSame(0, $this->asStaff($this->otherBranchManager)->getJson($this->base('/due'))->json('meta.total')); // another branch
        $out = $this->asStaff($this->outsider)->getJson($this->base('/due'))->assertOk();                   // another agency
        $this->assertSame([], $out->json('data'));
        $this->assertSame('No inspections are due right now.', $out->json('meta.empty_reason'));

        $nobody = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'nobody_role', 'is_active' => true]);
        $this->asStaff($nobody)->getJson($this->base('/due'))->assertForbidden();

        // 27 leases due -> 25 on the first page
        foreach (range(1, 27) as $n) {
            $p = \App\Models\Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => "{$n} Pager Road", 'address' => "{$n} Pager Road", 'status' => 'active', 'listing_type' => 'rental']);
            \App\Models\Lease::withoutGlobalScopes()->create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $p->id, 'status' => 'active', 'rental_amount' => 5000,
                'start_date' => now()->subDays($n)->toDateString(), 'end_date' => now()->addYear()->toDateString(), 'lease_type' => 'residential', 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            ]);
        }
        $p1 = $this->asStaff($this->agent)->getJson($this->base('/due?type=in'))->assertOk();
        $this->assertCount(25, $p1->json('data'));
        $this->assertSame(28, $p1->json('meta.total'));
        $this->assertSame(2, $p1->json('meta.last_page'));
        $this->assertCount(3, $this->asStaff($this->agent)->getJson($this->base('/due?type=in&page=2'))->json('data'));
    }

    // ═══ inventories: list ════════════════════════════════════════════════════════════════════

    public function test_the_inventory_list_pages_at_25_newest_first(): void
    {
        foreach (range(1, 27) as $n) {
            $this->inventory(['created_at' => now()->subDays(30 - $n)]);
        }

        $p1 = $this->asStaff($this->admin)->getJson(self::INV)->assertOk();
        $this->assertCount(25, $p1->json('data'));
        $this->assertSame(27, $p1->json('meta.total'));
        $this->assertSame(2, $p1->json('meta.last_page'));
        $this->assertNull($p1->json('meta.empty_reason'));
        $created = $p1->json('data.*.created_at');
        $desc = $created;
        rsort($desc);
        $this->assertSame($desc, $created, 'newest first');
        $this->assertCount(2, $this->asStaff($this->admin)->getJson(self::INV . '?page=2')->json('data'));
    }

    public function test_the_inventory_list_searches_sorts_and_filters_and_says_why_it_is_empty(): void
    {
        $draft = $this->inventory(['created_at' => now()->subDays(3)]);
        $done = $this->inventory(['status' => 'completed', 'created_at' => now()->subDays(10)]);

        $this->assertSame([$draft->id, $done->id], $this->asStaff($this->admin)->getJson(self::INV)->json('data.*.id'));
        $this->assertSame([$done->id, $draft->id], $this->asStaff($this->admin)->getJson(self::INV . '?direction=asc')->json('data.*.id'));
        $this->assertSame([$draft->id], $this->asStaff($this->admin)->getJson(self::INV . '?status=draft')->json('data.*.id'));
        $this->assertSame(['completed', 'draft'], $this->asStaff($this->admin)->getJson(self::INV . '?sort=status&direction=asc')->json('data.*.status'));
        $this->assertCount(2, $this->asStaff($this->admin)->getJson(self::INV . '?sort=property')->json('data'));
        $range = '?date_from=' . now()->subDays(12)->toDateString() . '&date_to=' . now()->subDays(8)->toDateString();
        $this->assertSame([$done->id], $this->asStaff($this->admin)->getJson(self::INV . $range)->json('data.*.id'));
        $this->assertCount(2, $this->asStaff($this->admin)->getJson(self::INV . '?q=Sea+Road')->json('data'));
        $this->assertCount(2, $this->asStaff($this->admin)->getJson(self::INV . '?search=Tina')->json('data'));

        $none = $this->asStaff($this->admin)->getJson(self::INV . '?q=Nowhere+Lane')->assertOk();
        $this->assertSame([], $none->json('data'));
        $this->assertSame('No inventories match your search or filters.', $none->json('meta.empty_reason'));
        $this->asStaff($this->admin)->getJson(self::INV . '?status=bogus')->assertStatus(422);
        $this->asStaff($this->admin)->getJson(self::INV . '?sort=bogus')->assertStatus(422);
    }

    public function test_the_inventory_list_and_record_are_scoped_and_permissioned(): void
    {
        $this->grantEveryone();
        $empty = $this->asStaff($this->agent)->getJson(self::INV)->assertOk();
        $this->assertSame('There are no inventories for you to see yet.', $empty->json('meta.empty_reason'));

        $inv = $this->inventory();

        $this->assertSame([$inv->id], $this->asStaff($this->agent)->getJson(self::INV)->json('data.*.id'));
        $this->assertSame([], $this->asStaff($this->colleague)->getJson(self::INV)->json('data'));
        $this->assertSame([], $this->asStaff($this->otherBranchManager)->getJson(self::INV)->json('data'));
        $this->assertSame([], $this->asStaff($this->outsider)->getJson(self::INV)->json('data'));

        $this->asStaff($this->agent)->getJson(self::INV . "/{$inv->id}")->assertOk();
        $this->asStaff($this->outsider)->getJson(self::INV . "/{$inv->id}")->assertNotFound();            // another agency
        $this->asStaff($this->colleague)->getJson(self::INV . "/{$inv->id}")->assertForbidden();          // same agency, outside their own
        $this->asStaff($this->otherBranchManager)->getJson(self::INV . "/{$inv->id}")->assertForbidden(); // same agency, another branch
        $this->asStaff($this->agent)->getJson(self::INV . '/999999')->assertNotFound();

        $nobody = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'nobody_role', 'is_active' => true]);
        $this->asStaff($nobody)->getJson(self::INV)->assertForbidden();
    }

    // ═══ inventories: detail + photo ══════════════════════════════════════════════════════════

    public function test_the_inventory_detail_carries_rooms_lines_photos_and_signatures(): void
    {
        $inv = $this->inventory();
        $line = RentalInventoryLine::create(['agency_id' => $this->agency->id, 'rental_inventory_id' => $inv->id, 'property_room_id' => $this->room->id, 'room_label' => 'Bedroom 1', 'quantity' => 2, 'description' => 'White wooden headboards', 'sort_order' => 1, 'created_by_user_id' => $this->agent->id]);
        $photo = $this->upload($inv, ['property_room_id' => $this->room->id, 'rental_inventory_line_id' => $line->id])->assertCreated();

        $d = $this->asStaff($this->admin)->getJson(self::INV . "/{$inv->id}")->assertOk();

        $d->assertJsonPath('id', $inv->id)->assertJsonPath('status', 'draft')->assertJsonPath('editable', true)
            ->assertJsonPath('rooms.0.label', 'Bedroom 1')->assertJsonPath('rooms.0.marked_empty', false)
            ->assertJsonPath('lines.0.description', 'White wooden headboards')->assertJsonPath('lines.0.quantity', 2)
            ->assertJsonPath('lines.0.photo_ids', [$photo->json('id')])
            ->assertJsonPath('photos.0.id', $photo->json('id'))->assertJsonPath('photos.0.room_id', $this->room->id)->assertJsonPath('photos.0.line_ids', [$line->id])
            ->assertJsonPath('tenant_names', 'Tina Test');
        $this->assertStringStartsWith('http', $d->json('photos.0.url'));
        $this->assertSame([], $d->json('unvisited_room_ids'));
        $this->assertNotEmpty($d->json('condition_states'));
        $this->assertSame([], $d->json('signatures'));
    }

    public function test_an_inventory_photo_is_stored_and_a_replay_returns_the_same_photo(): void
    {
        $inv = $this->inventory();
        $key = $this->uuid();
        $captured = now()->subHours(3)->startOfSecond();

        $first = $this->upload($inv, ['client_idempotency_key' => $key, 'captured_at' => $captured->toIso8601String()])->assertCreated();
        $first->assertJsonPath('duplicate', false)->assertJsonPath('inventory_id', $inv->id)->assertJsonPath('side', 'move_in')->assertJsonPath('client_idempotency_key', $key);
        $this->assertStringStartsWith('http', (string) $first->json('url'));
        $this->assertSame($captured->toIso8601String(), \Carbon\Carbon::parse($first->json('captured_at'))->toIso8601String(), 'the time it was taken, not the time it was sent');

        $again = $this->upload($inv, ['client_idempotency_key' => $key])->assertOk();
        $again->assertJsonPath('duplicate', true)->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, RentalInventoryPhoto::where('rental_inventory_id', $inv->id)->count());

        // late photos arrive in any order: the detail lists them by when they were taken
        $earlier = $this->upload($inv, ['captured_at' => now()->subHours(9)->toIso8601String()])->assertCreated();
        $this->assertSame([$earlier->json('id'), $first->json('id')], $this->asStaff($this->admin)->getJson(self::INV . "/{$inv->id}")->json('photos.*.id'));
    }

    public function test_an_inventory_photo_is_validated(): void
    {
        $inv = $this->inventory();
        $other = $this->inventory();

        $this->asStaff($this->admin)->post(self::INV . "/{$inv->id}/photos", ['client_idempotency_key' => $this->uuid()], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->asStaff($this->admin)->post(self::INV . "/{$inv->id}/photos", ['photo' => $this->jpeg()], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('client_idempotency_key');
        $this->upload($inv, ['client_idempotency_key' => 'nope'])->assertStatus(422);
        $this->upload($inv, ['property_room_id' => 999999])->assertStatus(422);

        // a retry key already used on another inventory is refused, never attached to the wrong one
        $key = $this->uuid();
        $this->upload($other, ['client_idempotency_key' => $key])->assertCreated();
        $this->upload($inv, ['client_idempotency_key' => $key])->assertStatus(422);
    }

    public function test_an_inventory_photo_is_scoped_permissioned_and_draft_only(): void
    {
        $this->grantEveryone();
        $inv = $this->inventory();

        $this->upload($inv, [], $this->outsider)->assertNotFound();
        $this->upload($inv, [], $this->colleague)->assertForbidden();
        $viewer = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);
        $this->upload($inv, [], $viewer)->assertForbidden();   // may look, may not record
        $this->upload($inv, [], $this->agent)->assertCreated();

        // a completed inventory is a signed record: no new photos (a live upload is a 409), but a retry of one already stored is still answered
        $key = $this->uuid();
        $this->upload($inv, ['client_idempotency_key' => $key], $this->agent)->assertCreated();
        $inv->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->upload($inv, [], $this->agent)->assertStatus(409);
        $this->upload($inv, ['client_idempotency_key' => $key], $this->agent)->assertOk()->assertJsonPath('duplicate', true);
    }

    public function test_an_upload_for_an_inventory_or_photo_archived_meanwhile_is_a_410_the_app_can_drop(): void
    {
        $inv = $this->inventory();
        $key = $this->uuid();
        $photoId = $this->upload($inv, ['client_idempotency_key' => $key])->assertCreated()->json('id');

        RentalInventoryPhoto::find($photoId)->delete();   // the photo was archived while the app still retried it
        $this->upload($inv, ['client_idempotency_key' => $key])->assertStatus(410)->assertJsonPath('code', 'archived');

        $inv->delete();                                    // and now the whole inventory
        $this->upload($inv)->assertStatus(410)->assertJsonPath('code', 'archived');
        $this->asStaff($this->admin)->getJson(self::INV . "/{$inv->id}")->assertStatus(410)->assertJsonPath('code', 'archived');
        $this->assertSame([], $this->asStaff($this->admin)->getJson(self::INV)->json('data'), 'an archived inventory is not listed');
    }
}
