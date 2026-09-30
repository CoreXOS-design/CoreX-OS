<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryPhoto;
use App\Models\RentalInventorySignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-inventory.md §20.2 — Johan, on property 5294/inventory 8:
 * "A completed/signed inventory is a legal record, and a cancelled one is
 * closed. Neither may be edited." Every capture-write endpoint on
 * RentalInventoryRecordingController and RentalInventoryCaptureController
 * must refuse (409) once the inventory's own status is no longer 'draft'
 * (RentalInventory::isDraft()/assertEditable()), and the capture screen must
 * hide every edit control (not merely disable it) once locked.
 */
final class RentalInventoryLockedAfterCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private RentalInventory $inventory;
    private PropertyRoom $lounge;
    private RentalInventoryLine $line;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Lock Test Agency', 'slug' => 'lock-test-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Lock Test Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->lounge = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'lounge', 'label' => 'Lounge', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
        $this->line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => $this->lounge->label,
            'quantity' => 1, 'description' => 'Samsung TV 55"', 'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function completeInventory(): void
    {
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/agent-signature.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);
        $this->inventory->markCompleted();
    }

    public function test_a_draft_inventory_still_accepts_every_write(): void
    {
        $this->postJson(route('corex.rental-inventories.lines.store', $this->inventory), [
            'property_room_id' => $this->lounge->id, 'quantity' => 1, 'description' => 'Coffee table',
        ])->assertStatus(201);

        $this->postJson(route('corex.rental-inventories.rooms.mark-empty', [$this->inventory, $this->lounge]))
            ->assertStatus(201);
    }

    public function test_storeLine_refuses_on_a_completed_inventory(): void
    {
        $this->completeInventory();

        $response = $this->postJson(route('corex.rental-inventories.lines.store', $this->inventory), [
            'property_room_id' => $this->lounge->id, 'quantity' => 1, 'description' => 'Extra item',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'This inventory is completed — it is a signed record and can no longer be edited.');
    }

    public function test_updateLine_refuses_on_a_completed_inventory(): void
    {
        $this->completeInventory();

        $this->putJson(route('corex.rental-inventories.lines.update', [$this->inventory, $this->line]), [
            'description' => 'Changed after completion',
        ])->assertStatus(409);

        $this->assertSame('Samsung TV 55"', $this->line->fresh()->description);
    }

    public function test_retireLine_refuses_on_a_completed_inventory(): void
    {
        $this->completeInventory();

        $this->postJson(route('corex.rental-inventories.lines.retire', [$this->inventory, $this->line]))
            ->assertStatus(409);

        $this->assertFalse($this->line->fresh()->is_retired);
    }

    /**
     * Audit H1 — the move-out comparison only opens on a COMPLETED inventory
     * and RentalInventoryLineDisposition::record() requires it, so the
     * disposition endpoint must be open exactly then (it used to 409 here,
     * making the whole move-out flow unusable).
     */
    public function test_storeLineDisposition_is_allowed_on_a_completed_inventory(): void
    {
        $this->completeInventory();

        $response = $this->postJson(route('corex.rental-inventories.lines.dispositions.store', [$this->inventory, $this->line]), [
            'disposition_key' => 'present',
        ]);

        // Never the lock's 409; a bad preset key would be a 422, success a 201.
        $this->assertContains($response->getStatusCode(), [201, 422]);
    }

    public function test_storeLineDisposition_refuses_on_a_draft_and_on_a_cancelled_inventory(): void
    {
        $this->postJson(route('corex.rental-inventories.lines.dispositions.store', [$this->inventory, $this->line]), [
            'disposition_key' => 'present',
        ])->assertStatus(409)->assertJsonPath('message', 'Move-out findings can only be recorded once the inventory is completed.');

        $this->inventory->cancel($this->agent, 'Tenant withdrew before move-in.');

        $this->postJson(route('corex.rental-inventories.lines.dispositions.store', [$this->inventory, $this->line]), [
            'disposition_key' => 'present',
        ])->assertStatus(409);
    }

    public function test_storeLineMoveOutPhoto_is_allowed_on_completed_but_not_draft(): void
    {
        Storage::fake('public');

        $this->postJson(route('corex.rental-inventories.lines.move-out-photos.store', [$this->inventory, $this->line]), [
            'photos' => [UploadedFile::fake()->image('x.jpg')],
        ])->assertStatus(409);

        $this->completeInventory();

        $response = $this->postJson(route('corex.rental-inventories.lines.move-out-photos.store', [$this->inventory, $this->line]), [
            'photos' => [UploadedFile::fake()->image('x.jpg')],
        ]);
        $this->assertNotSame(409, $response->getStatusCode());
    }

    public function test_a_completed_inventory_cannot_be_cancelled(): void
    {
        $this->completeInventory();

        $this->post(route('corex.rental-inventories.cancel', $this->inventory), ['cancel_reason' => 'oops'])
            ->assertSessionHasErrors('rental_inventory');

        $this->assertSame(RentalInventory::STATUS_COMPLETED, $this->inventory->fresh()->status);
    }

    public function test_markRoomEmpty_refuses_on_a_completed_inventory(): void
    {
        $this->completeInventory();

        $this->postJson(route('corex.rental-inventories.rooms.mark-empty', [$this->inventory, $this->lounge]))
            ->assertStatus(409);
    }

    public function test_unmarkRoomEmpty_refuses_on_a_completed_inventory(): void
    {
        $this->postJson(route('corex.rental-inventories.rooms.mark-empty', [$this->inventory, $this->lounge]))->assertStatus(201);
        $this->completeInventory();

        $this->deleteJson(route('corex.rental-inventories.rooms.unmark-empty', [$this->inventory, $this->lounge]))
            ->assertStatus(409);
    }

    public function test_copyFromLastInventory_refuses_on_a_completed_inventory(): void
    {
        $this->completeInventory();

        $this->postJson(route('corex.rental-inventories.copy-from-last', $this->inventory))
            ->assertStatus(409);
    }

    public function test_storeSignature_refuses_on_a_completed_inventory(): void
    {
        $this->completeInventory();

        $this->postJson(route('corex.rental-inventories.signatures.store', $this->inventory), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => null,
        ])->assertStatus(409);
    }

    public function test_complete_refuses_a_second_time_on_an_already_completed_inventory(): void
    {
        $this->completeInventory();
        $completedAt = $this->inventory->fresh()->completed_at;

        $this->postJson(route('corex.rental-inventories.complete', $this->inventory))
            ->assertStatus(409);

        $this->assertTrue($completedAt->equalTo($this->inventory->fresh()->completed_at), 'completed_at must not move on a re-completion attempt.');
    }

    public function test_storePhotos_refuses_on_a_completed_inventory(): void
    {
        Storage::fake('public');
        $this->completeInventory();

        $this->postJson(route('corex.rental-inventories.photos.store', $this->inventory), [
            'property_room_id' => $this->lounge->id,
            'photos' => [UploadedFile::fake()->image('x.jpg')],
        ])->assertStatus(409);
    }

    public function test_archivePhoto_refuses_on_a_completed_inventory(): void
    {
        $photo = RentalInventoryPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'storage_path' => 'fake.jpg',
            'uploaded_by_user_id' => $this->agent->id,
        ]);
        $this->completeInventory();

        $this->deleteJson(route('corex.rental-inventories.photos.destroy', [$this->inventory, $photo]))
            ->assertStatus(409);

        $this->assertFalse($photo->fresh()->trashed());
    }

    public function test_attachLinePhoto_and_detachLinePhoto_refuse_on_a_completed_inventory(): void
    {
        $photo = RentalInventoryPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'storage_path' => 'fake.jpg',
            'uploaded_by_user_id' => $this->agent->id,
        ]);
        $this->completeInventory();

        $this->postJson(route('corex.rental-inventories.lines.photos.attach', [$this->inventory, $this->line, $photo]))
            ->assertStatus(409);
        $this->deleteJson(route('corex.rental-inventories.lines.photos.detach', [$this->inventory, $this->line, $photo]))
            ->assertStatus(409);
    }

    public function test_every_guarded_write_also_refuses_on_a_cancelled_inventory(): void
    {
        $this->inventory->cancel($this->agent, 'Tenant withdrew before move-in.');

        $response = $this->postJson(route('corex.rental-inventories.lines.store', $this->inventory), [
            'property_room_id' => $this->lounge->id, 'quantity' => 1, 'description' => 'Extra item',
        ]);
        $response->assertStatus(409);
        $response->assertJsonPath('message', 'This inventory is cancelled and can no longer be edited.');

        $this->postJson(route('corex.rental-inventories.rooms.mark-empty', [$this->inventory, $this->lounge]))
            ->assertStatus(409);
    }

    public function test_resendReport_is_unaffected_it_still_requires_completed_not_draft(): void
    {
        // The opposite-direction guard (already existed) must keep working
        // exactly as before this change — resending a filed report happens
        // AFTER completion, not before, and is not "editing" the record.
        $this->postJson(route('corex.rental-inventories.resend-report', $this->inventory))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This inventory is not yet completed.');
    }

    public function test_capture_page_shows_the_banner_and_renders_no_edit_controls_once_completed(): void
    {
        $this->completeInventory();

        $response = $this->get(route('corex.properties.inventory.show', $this->property));

        $response->assertOk();
        $response->assertSee('Completed — signed record, read-only');
        $response->assertSee('Open the full record');
        // The edit surface (Add-space form, every room panel, the tagger
        // modal) must be genuinely ABSENT from the compiled HTML, not
        // merely hidden by an x-show — a locked inventory has no reachable
        // edit control for the server's 409 to silently swallow. Checked
        // via markup-only, server-rendered text that never also appears
        // inside the page's own <script>/<style> blocks (unlike e.g.
        // "addSpace()", which is also the JS method's own name/doc-comment
        // text and would false-pass this assertion either way).
        $response->assertDontSee('placeholder="e.g. Bedroom 1"', false);
        $response->assertDontSee('placeholder="e.g. White wooden headboard"', false);
    }

    /**
     * Cancelling an inventory does NOT leave the capture screen showing a
     * "Cancelled — read-only" banner — RentalInventory::currentFor()/
     * currentForProperty() (the resolvers RentalInventoryCaptureController::
     * show() calls) deliberately exclude cancelled records from being
     * "current", so the very next visit to this screen transparently starts
     * a FRESH draft inventory instead. That is pre-existing, intentional
     * behaviour (a cancelled inventory should not block starting over), not
     * something this fix changes — capture.blade.php's own cancelled-status
     * banner branch is therefore only reachable if that resolution behaviour
     * ever changes, and is kept for that reason rather than removed. The
     * cancelled lock ITSELF is still fully enforced server-side (see
     * test_every_guarded_write_also_refuses_on_a_cancelled_inventory above)
     * and on the record's own page (RentalInventoryController::show(),
     * pre-existing `@if(!in_array($inventory->status, ['completed',
     * 'cancelled']))` guards) — this test locks in the resolver behaviour so
     * a future change to it doesn't silently strand an agent on a dead page.
     */
    public function test_visiting_the_capture_page_after_cancellation_offers_start_and_only_the_post_creates_a_fresh_draft(): void
    {
        $this->inventory->cancel($this->agent, 'Tenant withdrew before move-in.');
        $before = RentalInventory::where('property_id', $this->property->id)->count();

        // Audit M4 — the GET is read-only: it creates nothing.
        $response = $this->get(route('corex.properties.inventory.show', $this->property));

        $response->assertOk();
        $response->assertDontSee('Cancelled — closed, read-only');
        $response->assertSee('Start inventory');
        $this->assertSame($before, RentalInventory::where('property_id', $this->property->id)->count());

        // The explicit POST starts the fresh draft; the normal edit surface renders.
        $this->post(route('corex.properties.inventory.start', $this->property))
            ->assertRedirect(route('corex.properties.inventory.show', $this->property));
        $this->get(route('corex.properties.inventory.show', $this->property))
            ->assertSee('placeholder="e.g. Bedroom 1"', false);

        $fresh = RentalInventory::where('property_id', $this->property->id)->latest('id')->first();
        $this->assertNotSame($this->inventory->id, $fresh->id);
        $this->assertTrue($fresh->isDraft());
    }

    public function test_capture_page_still_renders_the_full_edit_surface_while_draft(): void
    {
        $response = $this->get(route('corex.properties.inventory.show', $this->property));

        $response->assertOk();
        $response->assertSee('placeholder="e.g. Bedroom 1"', false);
        $response->assertSee('placeholder="e.g. White wooden headboard"', false);
        $response->assertDontSee('read-only');
    }
}
