<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyAuditLog;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInventory;
use App\Models\RentalInventoryPhoto;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-inventory.md section 29 - the bulk photo manager on the inventory and the inspection screens: select all across pages, delete
 * selected, delete all, move to an inspection (with a room), the other way round, restore, permissions and scoping.
 */
final class RentalPhotoBulkTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private PropertyRoom $lounge;
    private PropertyRoom $kitchen;
    private RentalInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Bulk Photos Agency', 'slug' => 'bulk-photos-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '5 Bulk Photo Lane', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subMonths(1), 'created_by_user_id' => $this->agent->id,
        ]);
        $this->lounge = $this->room('lounge', 'Lounge', 1);
        $this->kitchen = $this->room('kitchen', 'Kitchen', 2);
        $this->inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
        $this->actingAs($this->agent);
    }

    private function room(string $type, string $label, int $order): PropertyRoom
    {
        return PropertyRoom::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => $type, 'label' => $label, 'source' => 'manual', 'sort_order' => $order, 'created_by_user_id' => $this->agent->id]);
    }

    private function invPhotos(int $n, ?PropertyRoom $room = null, ?RentalInventory $inventory = null): array
    {
        $inventory ??= $this->inventory;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = RentalInventoryPhoto::create([
                'agency_id' => $inventory->agency_id, 'rental_inventory_id' => $inventory->id, 'property_room_id' => $room?->id,
                'storage_path' => 'private:private-images/rental-inventories/' . $inventory->agency_id . '/' . $inventory->id . '/' . uniqid('', true) . '.jpg',
                'uploaded_by_user_id' => $this->agent->id,
            ]);
        }

        return $out;
    }

    private function inspection(string $status = RentalInspection::STATUS_IN_PROGRESS, ?Property $property = null, ?Lease $lease = null): RentalInspection
    {
        $lease ??= $this->lease;

        return RentalInspection::create([
            'agency_id' => $lease->agency_id, 'lease_id' => $lease->id, 'property_id' => ($property ?? $this->property)->id,
            'type' => 'in', 'status' => $status, 'created_by_user_id' => $this->agent->id,
            'completed_at' => $status === RentalInspection::STATUS_COMPLETED ? now() : null,
        ]);
    }

    private function inspPhotos(RentalInspection $inspection, int $n, ?PropertyRoom $room = null): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = RentalInspectionPhoto::create([
                'agency_id' => $inspection->agency_id, 'rental_inspection_id' => $inspection->id, 'property_room_id' => $room?->id,
                'storage_path' => 'private:private-images/rental-inspections/' . $inspection->agency_id . '/' . $inspection->id . '/' . uniqid('', true) . '.jpg',
                'uploaded_by_user_id' => $this->agent->id,
            ]);
        }

        return $out;
    }

    private function act(array $body, ?RentalInventory $inventory = null)
    {
        return $this->postJson(route('corex.rental-inventories.photo-manager.act', $inventory ?? $this->inventory), $body);
    }

    // ── select all across pages ──────────────────────────────────────────

    public function test_the_list_pages_at_48_and_select_all_n_reaches_every_page(): void
    {
        $this->invPhotos(60, $this->lounge);

        $p1 = $this->getJson(route('corex.rental-inventories.photo-manager.index', $this->inventory))->assertOk()->json();
        $this->assertSame(48, count($p1['items']));
        $this->assertSame(60, $p1['total']);
        $this->assertSame(2, $p1['pages']);
        $this->assertTrue($p1['can_edit']);
        $p2 = $this->getJson(route('corex.rental-inventories.photo-manager.index', [$this->inventory, 'page' => 2]))->json();
        $this->assertSame(12, count($p2['items']));

        // "Select all 60" = the filter, not the ticked ids on one page; a stale count is refused and changes nothing
        $this->act(['action' => 'delete', 'scope' => 'filtered', 'expected_count' => 59])->assertStatus(409);
        $this->assertSame(60, RentalInventoryPhoto::count());

        $this->act(['action' => 'delete', 'scope' => 'filtered', 'expected_count' => 60])->assertOk()->assertJsonPath('affected', 60);
        $this->assertSame(0, RentalInventoryPhoto::count());
        $this->assertSame(60, RentalInventoryPhoto::onlyTrashed()->count(), 'soft delete only');
    }

    // ── delete selected / delete all ─────────────────────────────────────

    public function test_delete_selected_takes_only_the_ticked_photos_and_ignores_other_inventories(): void
    {
        $mine = $this->invPhotos(5, $this->lounge);
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $foreignInv = RentalInventory::create(['agency_id' => $otherAgency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'created_by_user_id' => $this->agent->id]);
        $foreign = $this->invPhotos(1, null, $foreignInv)[0];

        $this->act(['action' => 'delete', 'scope' => 'ids', 'ids' => [$mine[0]->id, $mine[1]->id, $mine[2]->id, $foreign->id]])->assertOk()->assertJsonPath('affected', 3);

        $this->assertSame(2, RentalInventoryPhoto::where('rental_inventory_id', $this->inventory->id)->count());
        $this->assertNotNull(RentalInventoryPhoto::withTrashed()->find($mine[0]->id)->archived_by_user_id);
        $this->assertNull(RentalInventoryPhoto::withoutGlobalScopes()->find($foreign->id)->deleted_at, 'a photo of another inventory can never be named');
        $audit = PropertyAuditLog::where('property_id', $this->property->id)->where('event_type', 'inventory_photos_deleted')->first();
        $this->assertNotNull($audit, 'one audit line per bulk action');
        $this->assertEqualsCanonicalizing([$mine[0]->id, $mine[1]->id, $mine[2]->id], $audit->metadata['photo_ids']);

        $this->act(['action' => 'delete', 'scope' => 'ids', 'ids' => []])->assertStatus(422);
    }

    public function test_delete_all_follows_the_room_filter_and_restore_brings_them_back(): void
    {
        $this->invPhotos(4, $this->lounge);
        $this->invPhotos(3, $this->kitchen);

        $this->act(['action' => 'delete', 'scope' => 'filtered', 'expected_count' => 4, 'filters' => ['room' => (string) $this->lounge->id]])->assertOk();
        $this->assertSame(3, RentalInventoryPhoto::count(), 'only the lounge went');

        $deleted = $this->getJson(route('corex.rental-inventories.photo-manager.index', [$this->inventory, 'deleted' => 1]))->assertOk()->json();
        $this->assertSame(4, $deleted['total']);
        $ids = array_column($deleted['items'], 'id');

        $this->act(['action' => 'restore', 'scope' => 'ids', 'ids' => array_slice($ids, 0, 2)])->assertOk()->assertJsonPath('affected', 2);
        $this->assertSame(5, RentalInventoryPhoto::count());
        $this->assertNull(RentalInventoryPhoto::find($ids[0])->archived_by_user_id);
        $this->assertSame(1, PropertyAuditLog::where('event_type', 'inventory_photos_restored')->count());

        $this->act(['action' => 'restore', 'scope' => 'filtered', 'expected_count' => 2, 'filters' => ['room' => (string) $this->lounge->id]])->assertOk();
        $this->assertSame(7, RentalInventoryPhoto::count());
    }

    // ── move to an inspection ────────────────────────────────────────────

    public function test_move_to_an_inspection_keeps_the_same_file_and_each_photos_room(): void
    {
        $inspection = $this->inspection();
        $inLounge = $this->invPhotos(2, $this->lounge);
        $noRoom = $this->invPhotos(1);
        $shared = RentalInventoryPhoto::create(['agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id, 'property_room_id' => $this->lounge->id, 'storage_path' => null,
            'rental_inspection_photo_id' => $this->inspPhotos($inspection, 1, $this->lounge)[0]->id, 'uploaded_by_user_id' => $this->agent->id]);
        $ids = array_map(fn ($p) => $p->id, array_merge($inLounge, $noRoom, [$shared]));
        $before = RentalInspectionPhoto::count();

        $this->act(['action' => 'move', 'scope' => 'ids', 'ids' => $ids, 'target_type' => 'inspection', 'target_id' => $inspection->id, 'room' => 'keep'])
            ->assertOk()->assertJsonPath('affected', 3);

        $this->assertSame($before + 3, RentalInspectionPhoto::count(), 'three new inspection rows (the shared one is not moved)');
        foreach ($inLounge as $p) {
            $raw = RentalInventoryPhoto::withTrashed()->find($p->id)->rawStoragePath();
            $new = RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->get()->first(fn ($x) => $x->rawStoragePath() === $raw);
            $this->assertNotNull($new, 'the SAME stored file, no copy');
            $this->assertSame($this->lounge->id, $new->property_room_id);
            $this->assertNotNull(RentalInventoryPhoto::withTrashed()->find($p->id)->deleted_at, 'the inventory row is soft-deleted');
        }
        $tray = RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->get()->first(fn ($x) => $x->rawStoragePath() === RentalInventoryPhoto::withTrashed()->find($noRoom[0]->id)->rawStoragePath());
        $this->assertNull($tray->property_room_id, 'no room stays in the untagged tray');
        $this->assertNull(RentalInventoryPhoto::find($shared->id)->deleted_at, 'a photo already shared from an inspection stays where it is');
        $this->assertSame(1, RentalInspectionAuditLog::where('rental_inspection_id', $inspection->id)->where('event', RentalInspectionAuditLog::EVENT_PHOTOS_MOVED)->count());
        $this->assertSame(1, PropertyAuditLog::where('event_type', 'inventory_photos_moved')->count());
    }

    public function test_move_into_one_room_or_unassigned_and_the_refusals(): void
    {
        $inspection = $this->inspection();
        $a = $this->invPhotos(2, $this->lounge);
        $this->act(['action' => 'move', 'scope' => 'ids', 'ids' => [$a[0]->id], 'target_type' => 'inspection', 'target_id' => $inspection->id, 'room' => (string) $this->kitchen->id])->assertOk();
        $this->assertSame($this->kitchen->id, RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->first()->property_room_id);
        $this->act(['action' => 'move', 'scope' => 'ids', 'ids' => [$a[1]->id], 'target_type' => 'inspection', 'target_id' => $inspection->id, 'room' => 'none'])->assertOk();
        $this->assertSame(1, RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->whereNull('property_room_id')->count());

        $more = $this->invPhotos(1, $this->lounge);
        $signed = $this->inspection(RentalInspection::STATUS_COMPLETED);
        $this->act(['action' => 'move', 'scope' => 'ids', 'ids' => [$more[0]->id], 'target_type' => 'inspection', 'target_id' => $signed->id, 'room' => 'keep'])->assertStatus(409);

        $otherProperty = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => 'Elsewhere', 'status' => 'active', 'listing_type' => 'rental']);
        $elsewhere = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $otherProperty->id, 'type' => 'in', 'status' => 'in_progress', 'created_by_user_id' => $this->agent->id]);
        $this->act(['action' => 'move', 'scope' => 'ids', 'ids' => [$more[0]->id], 'target_type' => 'inspection', 'target_id' => $elsewhere->id, 'room' => 'keep'])->assertNotFound();
        $this->assertNull(RentalInventoryPhoto::find($more[0]->id)->deleted_at, 'a refused move changes nothing');

        $otherRoom = PropertyRoom::create(['agency_id' => $this->agency->id, 'property_id' => $otherProperty->id, 'type' => 'lounge', 'label' => 'Foreign room', 'source' => 'manual', 'sort_order' => 1, 'created_by_user_id' => $this->agent->id]);
        $this->act(['action' => 'move', 'scope' => 'ids', 'ids' => [$more[0]->id], 'target_type' => 'inspection', 'target_id' => $inspection->id, 'room' => (string) $otherRoom->id])->assertStatus(422);

        $targets = $this->getJson(route('corex.rental-inventories.photo-manager.targets', $this->inventory))->assertOk()->json();
        $this->assertSame($signed->id, $targets['inspections'][0]['id'], 'newest first');
        $this->assertFalse($targets['inspections'][0]['open'], 'a signed inspection is shown but cannot be chosen');
    }

    // ── the inspection screen ────────────────────────────────────────────

    public function test_the_inspection_screen_deletes_restores_and_moves_to_the_inventory_or_another_room(): void
    {
        $inspection = $this->inspection();
        $photos = $this->inspPhotos($inspection, 6, $this->lounge);
        $act = fn (array $b) => $this->postJson(route('corex.rental-inspections.photo-manager.act', $inspection), $b);

        $list = $this->getJson(route('corex.rental-inspections.photo-manager.index', $inspection))->assertOk()->json();
        $this->assertSame(6, $list['total']);

        $act(['action' => 'move', 'scope' => 'ids', 'ids' => [$photos[0]->id, $photos[1]->id], 'target_type' => 'room', 'room' => (string) $this->kitchen->id])->assertOk()->assertJsonPath('affected', 2);
        $this->assertSame($this->kitchen->id, RentalInspectionPhoto::find($photos[0]->id)->property_room_id);

        $act(['action' => 'move', 'scope' => 'ids', 'ids' => [$photos[2]->id], 'target_type' => 'inventory', 'target_id' => $this->inventory->id, 'room' => 'keep'])->assertOk();
        $raw = RentalInspectionPhoto::withTrashed()->find($photos[2]->id)->rawStoragePath();
        $moved = RentalInventoryPhoto::where('rental_inventory_id', $this->inventory->id)->get()->first(fn ($x) => $x->rawStoragePath() === $raw);
        $this->assertNotNull($moved, 'same stored file on the inventory');
        $this->assertSame($this->lounge->id, $moved->property_room_id);
        $this->assertNotNull(RentalInspectionPhoto::withTrashed()->find($photos[2]->id)->deleted_at);

        $act(['action' => 'delete', 'scope' => 'filtered', 'expected_count' => 5])->assertOk()->assertJsonPath('affected', 5);
        $this->assertSame(0, RentalInspectionPhoto::count());
        $act(['action' => 'restore', 'scope' => 'ids', 'ids' => [$photos[3]->id]])->assertOk();
        $this->assertSame(1, RentalInspectionPhoto::count());
        $events = RentalInspectionAuditLog::where('rental_inspection_id', $inspection->id)->pluck('event')->all();
        foreach ([RentalInspectionAuditLog::EVENT_PHOTOS_MOVED, RentalInspectionAuditLog::EVENT_PHOTOS_ARCHIVED, RentalInspectionAuditLog::EVENT_PHOTOS_RESTORED] as $e) {
            $this->assertContains($e, $events);
        }
    }

    public function test_a_signed_inspection_and_a_completed_inventory_refuse_every_change(): void
    {
        $signed = $this->inspection(RentalInspection::STATUS_COMPLETED);
        $p = $this->inspPhotos($signed, 1, $this->lounge)[0];
        $this->postJson(route('corex.rental-inspections.photo-manager.act', $signed), ['action' => 'delete', 'scope' => 'ids', 'ids' => [$p->id]])->assertStatus(409);
        $list = $this->getJson(route('corex.rental-inspections.photo-manager.index', $signed))->assertOk()->json();
        $this->assertFalse($list['can_edit']);

        $q = $this->invPhotos(1, $this->lounge)[0];
        $this->inventory->forceFill(['status' => RentalInventory::STATUS_COMPLETED])->save();
        $this->act(['action' => 'delete', 'scope' => 'ids', 'ids' => [$q->id]])->assertStatus(409);
        $this->assertNull(RentalInventoryPhoto::find($q->id)->deleted_at);
    }

    // ── permissions and scoping ──────────────────────────────────────────

    public function test_a_view_only_user_can_look_but_not_change_and_other_agencies_are_invisible(): void
    {
        $photos = $this->invPhotos(2, $this->lounge);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        foreach ([['rental_inventories.view', 'all'], ['rental_inspections.view', 'all'], ['access_properties', null]] as [$key, $scope]) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();
        $viewer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->actingAs($viewer);
        $list = $this->getJson(route('corex.rental-inventories.photo-manager.index', $this->inventory))->assertOk()->json();
        $this->assertFalse($list['can_edit'], 'no edit permission: no tick boxes, no actions');
        $this->act(['action' => 'delete', 'scope' => 'ids', 'ids' => [$photos[0]->id]])->assertForbidden();
        $this->assertSame(2, RentalInventoryPhoto::count());

        // another agency's inventory answers 404 for list and act - by direct URL too
        $otherAgency = Agency::create(['name' => 'Elsewhere Agency', 'slug' => 'elsewhere-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $otherAgency->id]);
        $outsider = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $this->actingAs($outsider);
        $this->assertContains($this->getJson(route('corex.rental-inventories.photo-manager.index', $this->inventory))->getStatusCode(), [403, 404], 'blocked by direct URL');
        $this->assertContains($this->act(['action' => 'delete', 'scope' => 'filtered', 'expected_count' => 2])->getStatusCode(), [403, 404]);
        $this->assertSame(2, RentalInventoryPhoto::withoutGlobalScopes()->whereNull('deleted_at')->count());
    }

    public function test_an_own_scoped_user_cannot_reach_a_colleagues_inventory(): void
    {
        $this->invPhotos(1, $this->lounge);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        foreach ([['rental_inventories.view', 'own'], ['rental_inventories.create', null], ['access_properties', null]] as [$key, $scope]) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();
        $colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->actingAs($colleague);

        $this->getJson(route('corex.rental-inventories.photo-manager.index', $this->inventory))->assertNotFound();
        $this->act(['action' => 'delete', 'scope' => 'filtered', 'expected_count' => 1])->assertNotFound();
    }

    // ── the screens carry the control ────────────────────────────────────

    public function test_both_screens_render_the_manager(): void
    {
        $this->get(route('corex.properties.inventory.show', $this->property))->assertOk()
            ->assertSee('data-qa="photo-bulk-inventory"', false)->assertSee('Select all on this page', false)->assertSee('Delete selected', false);
        $inspection = $this->inspection();
        $this->get(route('corex.rental-inspections.show', $inspection))->assertOk()
            ->assertSee('data-qa="photo-bulk-inspection"', false)->assertSee('Move selected…', false);
    }
}
