<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryBuyerAcceptance;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventorySignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 2026-10-06 (cc1, security) — the public inventory report link
 * (/rental-inventory-report/{token}) AND its unauthenticated buyer-acceptance
 * write (POST .../buyer-acceptance) must stop working the moment the
 * inventory is ARCHIVED (soft-deleted) or CANCELLED, and work again — on the
 * SAME token, so the QR already printed on the PDF revives — when an archived
 * one is RESTORED. Expiry, revoke and regenerate rules are unchanged.
 *
 * Same defect and same fix as the inspection link
 * (RentalInspectionPublicLinkLifecycleTest): RentalInventory::
 * findByPublicToken() ran withoutGlobalScopes(), which strips SoftDeletes,
 * and never looked at status (rental-inventory.md §25).
 *
 * Input paths covered: archived page · archived buyer write · cancelled page
 * (real cancel() on a draft) · cancelled buyer write · restored page and
 * write on the same token · archive → restore → archive again · archive and
 * restore through the agent's real HTTP routes · token not cleared by
 * archiving · expired / revoked / replaced tokens stay dead after a restore ·
 * archived link identical to a never-issued token · other agency unaffected.
 */
final class RentalInventoryPublicLinkLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const UNAVAILABLE = 'Please contact your agent for a current link.';

    protected function setUp(): void
    {
        parent::setUp();
        Auth::logout();
        DocumentType::firstOrCreate(['slug' => 'inventory_list'], ['label' => 'Inventory List', 'is_active' => true]);
        Storage::fake('public');
        Mail::fake();
    }

    /** A completed sale inventory with a committed deal and one buyer, token issued. */
    private function completedInventory(string $suffix = 'a'): array
    {
        $agency = Agency::create(['name' => "Inv Link {$suffix}", 'slug' => 'inv-link-' . $suffix . '-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => "Inventory Link Property {$suffix}", 'status' => 'active', 'listing_type' => 'sale',
            'suburb' => 'Ramsgate', 'address' => "{$suffix} Beach Road",
        ]);
        $seller = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Sipho', 'last_name' => 'Seller',
            'email' => "inv-seller-{$suffix}-" . uniqid() . '@example.test', 'created_by_user_id' => $agent->id,
        ]);
        $property->contacts()->attach($seller->id, ['role' => 'seller']);
        $room = PropertyRoom::create([
            'agency_id' => $agency->id, 'property_id' => $property->id, 'type' => 'kitchen', 'label' => 'Kitchen',
            'source' => 'manual', 'sort_order' => 1, 'created_by_user_id' => $agent->id,
        ]);

        $this->actingAs($agent);
        $inventory = RentalInventory::startForProperty($property, $agent);
        RentalInventoryLine::create([
            'agency_id' => $agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $room->id, 'room_label' => $room->label,
            'quantity' => 1, 'description' => "Built-in oven {$suffix}", 'created_by_user_id' => $agent->id,
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_SELLER, 'signed', [
            'party_contact_id' => $seller->id, 'party_signature_path' => 'signatures/fake-seller.png',
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_AGENT, 'signed', [
            'party_signature_path' => 'signatures/fake.png',
        ]);
        $inventory->markCompleted();

        $buyer = Contact::create([
            'agency_id' => $agency->id, 'first_name' => 'Bongani', 'last_name' => 'Buyer',
            'email' => "inv-buyer-{$suffix}-" . uniqid() . '@example.test', 'created_by_user_id' => $agent->id,
        ]);
        $deal = Deal::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'deal_no' => random_int(100000, 999999), 'deal_date' => now()->toDateString(), 'period' => now()->format('Y-m'),
            'property_value' => 1850000, 'total_commission' => 115000, 'accepted_status' => 'G', 'commission_status' => 'Not Paid',
        ]);
        $deal->contacts()->attach($buyer->id, ['role' => 'buyer']);

        $inventory = $inventory->fresh(['property']);
        $token = $inventory->generatePublicLink();
        Auth::logout();

        return compact('agency', 'agent', 'property', 'inventory', 'buyer', 'token');
    }

    private function postAcceptance(string $token, Contact $buyer)
    {
        return $this->postJson(route('rental-inventories.public.buyer-acceptance.store', $token), [
            'buyer_contact_id' => $buyer->id,
            'disposition' => 'signed',
            'signature_image' => 'data:image/png;base64,' . base64_encode('fake-image-bytes'),
        ]);
    }

    private function assertPageLive(string $token, string $suffix = 'a'): void
    {
        $resp = $this->get(route('rental-inventories.public.show', $token));
        $resp->assertOk();
        $resp->assertSee("Built-in oven {$suffix}");
        $resp->assertDontSee(self::UNAVAILABLE);
    }

    private function assertPageDead(string $token, string $suffix = 'a'): void
    {
        $resp = $this->get(route('rental-inventories.public.show', $token));
        // The standard "link isn't available" page — never an exception page,
        // never any of the inventory's own data.
        $resp->assertOk();
        $resp->assertSee(self::UNAVAILABLE);
        $resp->assertDontSee("Built-in oven {$suffix}");
        $resp->assertDontSee('Beach Road');
        $resp->assertDontSee('Whoops');
        $resp->assertDontSee('Stack trace');
    }

    // ── ARCHIVED ────────────────────────────────────────────────────────

    public function test_an_archived_inventorys_public_page_shows_the_unavailable_page(): void
    {
        $s = $this->completedInventory();
        $this->assertPageLive($s['token']); // control: works before archiving

        $s['inventory']->delete();

        self::assertNull(RentalInventory::findByPublicToken($s['token']));
        $this->assertPageDead($s['token']);
    }

    public function test_archiving_does_not_clear_the_token(): void
    {
        $s = $this->completedInventory();

        $s['inventory']->delete();

        self::assertSame($s['token'], RentalInventory::withTrashed()->find($s['inventory']->id)->public_token);
    }

    public function test_an_archived_inventory_refuses_the_buyer_acceptance_write(): void
    {
        $s = $this->completedInventory();
        $s['inventory']->delete();

        $this->postAcceptance($s['token'], $s['buyer'])
            ->assertStatus(404)
            ->assertJsonPath('message', 'This link is no longer available.');

        self::assertSame(0, RentalInventoryBuyerAcceptance::withoutGlobalScopes()->where('rental_inventory_id', $s['inventory']->id)->count());
    }

    // ── CANCELLED ───────────────────────────────────────────────────────

    public function test_a_cancelled_inventorys_public_page_shows_the_unavailable_page(): void
    {
        // A draft is the only state the app lets you cancel (a completed one is a signed record).
        $s = $this->completedInventory();
        $agent = $s['agent'];
        $this->actingAs($agent);
        $draftProperty = Property::forceCreate([
            'agency_id' => $s['agency']->id, 'agent_id' => $agent->id, 'branch_id' => $agent->branch_id,
            'title' => 'Draft Property', 'status' => 'active', 'listing_type' => 'sale', 'address' => '9 Draft Lane',
        ]);
        $draft = RentalInventory::startForProperty($draftProperty, $agent);
        $room = PropertyRoom::create([
            'agency_id' => $s['agency']->id, 'property_id' => $draftProperty->id, 'type' => 'kitchen', 'label' => 'Kitchen',
            'source' => 'manual', 'sort_order' => 1, 'created_by_user_id' => $agent->id,
        ]);
        RentalInventoryLine::create([
            'agency_id' => $s['agency']->id, 'rental_inventory_id' => $draft->id,
            'property_room_id' => $room->id, 'room_label' => $room->label,
            'quantity' => 1, 'description' => 'Built-in oven draft', 'created_by_user_id' => $agent->id,
        ]);
        $token = $draft->generatePublicLink();
        Auth::logout();
        $this->assertPageLive($token, 'draft'); // control

        $this->actingAs($agent);
        $draft->fresh()->cancel($agent, 'Started on the wrong property');
        Auth::logout();

        self::assertSame(RentalInventory::STATUS_CANCELLED, $draft->fresh()->status);
        self::assertNull(RentalInventory::findByPublicToken($token));
        $this->assertPageDead($token, 'draft');
    }

    public function test_a_cancelled_inventory_refuses_the_buyer_acceptance_write(): void
    {
        $s = $this->completedInventory();
        // The app cannot cancel a completed inventory (by design), so force the
        // state to prove the rule is status-based, not path-based.
        $s['inventory']->forceFill(['status' => RentalInventory::STATUS_CANCELLED])->save();

        $this->postAcceptance($s['token'], $s['buyer'])->assertStatus(404);
        $this->assertPageDead($s['token']);
        self::assertSame(0, RentalInventoryBuyerAcceptance::withoutGlobalScopes()->where('rental_inventory_id', $s['inventory']->id)->count());
    }

    // ── RESTORED ────────────────────────────────────────────────────────

    public function test_restoring_an_archived_inventory_brings_the_same_link_back(): void
    {
        $s = $this->completedInventory();
        $s['inventory']->delete();
        $this->assertPageDead($s['token']);

        RentalInventory::withTrashed()->find($s['inventory']->id)->restore();

        self::assertNotNull(RentalInventory::findByPublicToken($s['token']));
        $this->assertPageLive($s['token']); // same token, not a regenerated one
    }

    public function test_a_restored_inventory_accepts_the_buyer_write_again(): void
    {
        $s = $this->completedInventory();
        $s['inventory']->delete();
        $this->postAcceptance($s['token'], $s['buyer'])->assertStatus(404);

        RentalInventory::withTrashed()->find($s['inventory']->id)->restore();

        $this->postAcceptance($s['token'], $s['buyer'])->assertStatus(201);
        self::assertSame(1, RentalInventoryBuyerAcceptance::withoutGlobalScopes()->where('rental_inventory_id', $s['inventory']->id)->count());
    }

    public function test_archive_restore_archive_again_is_dead_again(): void
    {
        $s = $this->completedInventory();

        $s['inventory']->delete();
        RentalInventory::withTrashed()->find($s['inventory']->id)->restore();
        $this->assertPageLive($s['token']);

        RentalInventory::find($s['inventory']->id)->delete();
        $this->assertPageDead($s['token']);
    }

    // ── The real HTTP archive / restore path an agent uses ──────────────

    public function test_archive_and_restore_through_the_agents_own_screens(): void
    {
        $s = $this->completedInventory();
        $this->assertPageLive($s['token']);

        $this->actingAs($s['agent'])
            ->delete(route('corex.rental-inventories.destroy', $s['inventory']))
            ->assertRedirect(route('corex.rental-inventories.index'));
        Auth::logout();
        $this->assertPageDead($s['token']);

        $this->actingAs($s['agent'])
            ->post(route('corex.rental-inventories.restore', $s['inventory']->id))
            ->assertRedirect();
        Auth::logout();
        $this->assertPageLive($s['token']);
    }

    // ── Rules that must NOT have changed ────────────────────────────────

    public function test_an_expired_token_stays_dead_even_after_a_restore(): void
    {
        $s = $this->completedInventory();
        $s['inventory']->generatePublicLink(-1);
        $token = $s['inventory']->public_token;

        $s['inventory']->delete();
        RentalInventory::withTrashed()->find($s['inventory']->id)->restore();

        self::assertNull(RentalInventory::findByPublicToken($token));
        $this->assertPageDead($token);
    }

    public function test_a_revoked_token_stays_dead_after_a_restore(): void
    {
        $s = $this->completedInventory();
        $s['inventory']->revokePublicLink();

        $s['inventory']->delete();
        RentalInventory::withTrashed()->find($s['inventory']->id)->restore();

        $this->assertPageDead($s['token']);
    }

    public function test_a_replaced_token_stays_dead_and_the_new_one_follows_the_lifecycle(): void
    {
        $s = $this->completedInventory();
        $old = $s['token'];
        $new = $s['inventory']->generatePublicLink();

        $this->assertPageDead($old);
        $this->assertPageLive($new);

        $s['inventory']->delete();
        $this->assertPageDead($new);
        RentalInventory::withTrashed()->find($s['inventory']->id)->restore();
        $this->assertPageLive($new);
        $this->assertPageDead($old);
    }

    public function test_an_archived_link_is_identical_to_a_never_issued_one(): void
    {
        $s = $this->completedInventory();

        $never = $this->get(route('rental-inventories.public.show', 'never-issued-token'));
        $s['inventory']->delete();
        $archived = $this->get(route('rental-inventories.public.show', $s['token']));

        self::assertSame($never->getStatusCode(), $archived->getStatusCode());
        self::assertSame(
            preg_replace('/\s+/', ' ', strip_tags($never->getContent())),
            preg_replace('/\s+/', ' ', strip_tags($archived->getContent())),
        );
    }

    public function test_one_agencys_archived_inventory_does_not_affect_another_agencys_live_link(): void
    {
        $a = $this->completedInventory('a');
        $b = $this->completedInventory('b');

        $a['inventory']->delete();

        $this->assertPageDead($a['token'], 'a');
        $this->assertPageLive($b['token'], 'b');
    }
}
