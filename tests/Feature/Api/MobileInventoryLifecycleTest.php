<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\RentalInventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileInspectionExtras;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * Mobile parity, inventories: start on site, cancel, archive, restore, the move-out comparison, and the routes that point straight at the web
 * actions (rooms marked empty, copy from last, signatures, completion ...). The rules are tested with the web; this proves the phone reaches
 * them with the same permission, the same scoping and the same refusals.
 */
final class MobileInventoryLifecycleTest extends TestCase
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

    private function inventory(): RentalInventory
    {
        return RentalInventory::start($this->property, $this->lease, $this->admin);
    }

    private function viewer(): User
    {
        $this->grantScope($this->agency, 'viewer', 'all', ['rental_inventories.view', 'leases.view', 'properties.view', 'access_properties']);

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);
    }

    public function test_an_inventory_is_started_on_site_once_and_a_second_tap_opens_the_same_one(): void
    {
        $first = $this->asStaff($this->admin)->postJson("/api/v1/mobile/properties/{$this->property->id}/inventory")->assertCreated();
        $second = $this->asStaff($this->admin)->postJson("/api/v1/mobile/properties/{$this->property->id}/inventory")->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, RentalInventory::query()->where('property_id', $this->property->id)->count());
    }

    public function test_another_agencys_property_cannot_be_started_and_a_viewer_is_refused(): void
    {
        $this->asStaff($this->outsider)->postJson("/api/v1/mobile/properties/{$this->property->id}/inventory")->assertNotFound();
        $this->asStaff($this->viewer())->postJson("/api/v1/mobile/properties/{$this->property->id}/inventory")->assertForbidden();
        $this->assertSame(0, RentalInventory::query()->count());
    }

    public function test_cancel_needs_a_reason_and_archive_then_restore_round_trips(): void
    {
        $inv = $this->inventory();

        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('cancel_reason');
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/cancel", ['cancel_reason' => 'Wrong property'])->assertOk();
        $this->assertSame(RentalInventory::STATUS_CANCELLED, $inv->fresh()->status);

        $this->asStaff($this->admin)->deleteJson(self::INV . "/{$inv->id}")->assertOk()->assertJsonPath('archived', true);
        $this->assertSoftDeleted('rental_inventories', ['id' => $inv->id]);
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/cancel", ['cancel_reason' => 'x'])->assertStatus(410)->assertJsonPath('code', 'archived');

        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/restore")->assertOk()->assertJsonPath('id', $inv->id);
        $this->assertNull(RentalInventory::withTrashed()->find($inv->id)->deleted_at);
    }

    public function test_the_comparison_is_refused_until_the_inventory_is_completed(): void
    {
        $inv = $this->inventory();

        $this->asStaff($this->admin)->getJson(self::INV . "/{$inv->id}/comparison")->assertStatus(409)->assertJsonPath('code', 'not_completed');

        $inv->forceFill(['status' => RentalInventory::STATUS_COMPLETED, 'completed_at' => now()])->save();
        $this->asStaff($this->admin)->getJson(self::INV . "/{$inv->id}/comparison")->assertOk()->assertJsonPath('inventory_id', $inv->id)->assertJsonStructure(['rows', 'disposition_presets']);
    }

    public function test_move_out_steps_are_refused_by_the_model_until_the_inventory_is_ready(): void
    {
        $inv = $this->inventory();

        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/move-out/complete")->assertStatus(409)->assertJsonPath('code', 'cannot_complete_move_out');
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/move-out/sign-off", [])->assertStatus(422);
    }

    public function test_the_web_actions_the_phone_now_reaches_answer_json_for_a_bearer_token(): void
    {
        $inv = $this->inventory();

        // marking a room "nothing here" and undoing it
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/rooms/{$this->room->id}/mark-empty")->assertSuccessful();
        $this->asStaff($this->admin)->deleteJson(self::INV . "/{$inv->id}/rooms/{$this->room->id}/mark-empty")->assertSuccessful();
        // copy from last: this property has no earlier inventory, so the web answers 404 with its own message, not a server error
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/copy-from-last")->assertStatus(404);
        // completing an empty inventory is refused by the web rule
        $this->asStaff($this->admin)->postJson(self::INV . "/{$inv->id}/complete")->assertStatus(409);
    }

    public function test_every_new_inventory_route_is_scoped_to_the_agency_and_permissioned(): void
    {
        $this->grantEveryone(); // real permission rows, so the outsider reaches the agency scope instead of the permission check
        $inv = $this->inventory();
        $viewer = $this->viewer();

        foreach ([['post', '/cancel', ['cancel_reason' => 'x']], ['post', '/restore', []], ['get', '/comparison', []], ['post', '/complete', []],
            ['post', '/copy-from-last', []], ['get', '/report', []]] as [$verb, $path, $body]) {
            $this->asStaff($this->outsider)->json(strtoupper($verb), self::INV . "/{$inv->id}{$path}", $body)->assertNotFound();
        }
        $this->asStaff($this->outsider)->deleteJson(self::INV . "/{$inv->id}")->assertNotFound();

        $this->asStaff($viewer)->postJson(self::INV . "/{$inv->id}/cancel", ['cancel_reason' => 'x'])->assertForbidden();
        $this->asStaff($viewer)->deleteJson(self::INV . "/{$inv->id}")->assertForbidden();
        $this->asStaff($viewer)->postJson(self::INV . "/{$inv->id}/complete")->assertForbidden();
        $this->assertSame(RentalInventory::STATUS_DRAFT, $inv->fresh()->status);
    }
}
