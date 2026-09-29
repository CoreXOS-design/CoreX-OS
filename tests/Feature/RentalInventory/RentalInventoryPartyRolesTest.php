<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\DocumentType;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventorySignature;
use App\Models\User;
use App\Services\PartyRoleLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * §22 ruling (Johan, 2026-09-29) — three things proven here:
 *   1. A RENTAL inventory still requires tenant(s) + landlord + agent,
 *      unchanged (ruling #2, the regression check).
 *   2. A SALE inventory (lease_id null, property listing_type=sale)
 *      requires ONLY seller + agent — no tenant is ever asked for, and the
 *      owner's signature is recorded with party_role='seller', never
 *      'landlord'.
 *   3. PartyRoleLabel reads its display word from the real
 *      App\Models\ContactType catalogue (the agency's own "Contact Types"
 *      settings screen), not a hardcoded string — including the
 *      deterministic tie-break when more than one ContactType shares an
 *      esign_role.
 */
final class RentalInventoryPartyRolesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Party Roles Agency', 'slug' => 'party-roles-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        DocumentType::create(['slug' => 'inventory_list', 'label' => 'Inventory List', 'is_active' => true]);

        // The 4 canonical ContactType parents (Lessor/Lessee/Seller/Buyer)
        // are meant to be backfilled by migration
        // 2026_09_12_000001_reseed_base_contact_type_parents_for_fresh_bootstraps
        // — but that migration is now itself baked into
        // database/schema/mysql-schema.sql's migrations-table snapshot as
        // "already run," so RefreshDatabase SKIPS it and a fresh test DB
        // gets zero of these rows (the exact AT-392 class of gap that
        // migration's own docblock warns will recur once the snapshot is
        // regenerated again — confirmed via a real test failure while
        // building this file, flagged to the conductor, not fixed here —
        // regenerating the snapshot is shared infra outside this task).
        // Seeded directly here, same convention the DocumentType rows above
        // already use for the identical reason.
        foreach (ContactType::CANONICAL as $esignRole => $name) {
            ContactType::firstOrCreate(['esign_role' => $esignRole, 'name' => $name], ['is_active' => true, 'sort_order' => 0]);
        }

        Storage::fake('public');
    }

    private function makeRoom(Property $property): PropertyRoom
    {
        return PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id,
            'type' => 'lounge', 'label' => 'Lounge', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    // ── #2 regression: rental unchanged — tenant + landlord + agent ────

    public function test_rental_inventory_still_requires_tenant_and_landlord(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Rental Regression Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $tenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Tenant', 'email' => 'tenant-' . uniqid() . '@example.test',
            'created_by_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lindiwe', 'last_name' => 'Landlord', 'email' => 'landlord-' . uniqid() . '@example.test',
            'created_by_user_id' => $this->agent->id,
        ]);
        $property->contacts()->attach($landlord->id, ['role' => 'landlord']);

        $inventory = RentalInventory::start($property, $lease, $this->agent);
        self::assertSame(RentalInventorySignature::PARTY_LANDLORD, $inventory->ownerPartyRole());

        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $this->makeRoom($property)->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Sofa', 'created_by_user_id' => $this->agent->id,
        ]);

        $outstanding = $inventory->outstandingSignatories();
        self::assertCount(2, $outstanding, 'A rental inventory must still require both the tenant and the landlord.');
        self::assertTrue($outstanding->contains(fn ($s) => $s['party_role'] === RentalInventorySignature::PARTY_TENANT));
        self::assertTrue($outstanding->contains(fn ($s) => $s['party_role'] === RentalInventorySignature::PARTY_LANDLORD));

        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_TENANT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $tenant->id, 'party_signature_path' => '/fake/sig.png',
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_LANDLORD, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $landlord->id, 'party_signature_path' => '/fake/sig2.png',
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/sig3.png',
        ]);

        $inventory->markCompleted();
        self::assertSame(RentalInventory::STATUS_COMPLETED, $inventory->fresh()->status);
    }

    public function test_rental_inventory_rejects_a_seller_role_signature(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Rental Reject Seller Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lindiwe', 'last_name' => 'Landlord', 'email' => 'landlord2-' . uniqid() . '@example.test',
            'created_by_user_id' => $this->agent->id,
        ]);
        $property->contacts()->attach($landlord->id, ['role' => 'landlord']);
        $inventory = RentalInventory::start($property, $lease, $this->agent);

        $this->expectException(\InvalidArgumentException::class);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_SELLER, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $landlord->id, 'party_signature_path' => '/fake/sig.png',
        ]);
    }

    // ── #3: sale = seller + agent only, no tenant, no buyer ─────────────

    public function test_sale_inventory_requires_only_seller_and_agent(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Sale Party Roles Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $seller = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Seller', 'email' => 'seller-' . uniqid() . '@example.test',
            'created_by_user_id' => $this->agent->id,
        ]);
        $property->contacts()->attach($seller->id, ['role' => 'seller']);

        $inventory = RentalInventory::startForProperty($property, $this->agent);
        self::assertNull($inventory->lease_id);
        self::assertSame(RentalInventorySignature::PARTY_SELLER, $inventory->ownerPartyRole());

        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $this->makeRoom($property)->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Sofa', 'created_by_user_id' => $this->agent->id,
        ]);

        $outstanding = $inventory->outstandingSignatories();
        self::assertCount(1, $outstanding, 'A sale inventory must require ONLY the seller — no tenant, no buyer.');
        self::assertSame(RentalInventorySignature::PARTY_SELLER, $outstanding->first()['party_role']);

        // The owner must sign as SELLER for real — 'landlord' is refused outright.
        $this->expectExceptionMessage("does not match this inventory's resolved owner role");
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_LANDLORD, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $seller->id, 'party_signature_path' => '/fake/sig.png',
        ]);
    }

    public function test_sale_inventory_completes_with_seller_and_agent_signatures_only(): void
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Sale Complete Property', 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $seller = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Nomsa', 'last_name' => 'Seller', 'email' => 'seller2-' . uniqid() . '@example.test',
            'created_by_user_id' => $this->agent->id,
        ]);
        $property->contacts()->attach($seller->id, ['role' => 'seller']);
        $inventory = RentalInventory::startForProperty($property, $this->agent);
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $inventory->id,
            'property_room_id' => $this->makeRoom($property)->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Sofa', 'created_by_user_id' => $this->agent->id,
        ]);

        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_SELLER, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_contact_id' => $seller->id, 'party_signature_path' => '/fake/sig.png',
        ]);
        RentalInventorySignature::capture($inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/sig2.png',
        ]);

        $inventory->markCompleted();
        self::assertSame(RentalInventory::STATUS_COMPLETED, $inventory->fresh()->status);
        self::assertSame('seller', $inventory->fresh()->signatures()->where('party_role', 'seller')->first()->party_role);
    }

    public function test_property_level_inventory_on_a_vacant_rental_still_uses_landlord_not_seller(): void
    {
        // §15/§18 distinction: lease_id null does NOT by itself mean "sale" —
        // a RENTAL property currently between tenancies is also lease_id
        // null, and must stay 'landlord'.
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Vacant Rental Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $inventory = RentalInventory::startForProperty($property, $this->agent);
        self::assertNull($inventory->lease_id);
        self::assertSame(RentalInventorySignature::PARTY_LANDLORD, $inventory->ownerPartyRole());
    }

    // ── #1: labels read from the real ContactType catalogue ────────────

    public function test_party_role_label_reads_the_canonical_contact_type_name(): void
    {
        // Seeded by migration 2026_09_12_000001 — Lessor/Lessee/Seller/Buyer
        // already exist with their real names, no test setup needed.
        self::assertSame('Lessor', PartyRoleLabel::for($this->agency->id, RentalInventorySignature::PARTY_LANDLORD));
        self::assertSame('Lessee', PartyRoleLabel::for($this->agency->id, RentalInventorySignature::PARTY_TENANT));
        self::assertSame('Seller', PartyRoleLabel::for($this->agency->id, RentalInventorySignature::PARTY_SELLER));
        self::assertSame('Agent', PartyRoleLabel::for($this->agency->id, RentalInventorySignature::PARTY_AGENT));
    }

    public function test_party_role_label_prefers_the_canonical_row_when_two_share_an_esign_role(): void
    {
        // Johan's own named case: Agency 1 carries BOTH 'Tenant' and
        // 'Lessee' for esign_role=lessee. Deterministic pick: the canonical
        // row wins regardless of the other row's sort_order.
        ContactType::create(['name' => 'Tenant', 'esign_role' => 'lessee', 'sort_order' => 0, 'is_active' => true]);

        self::assertSame('Lessee', PartyRoleLabel::for($this->agency->id, RentalInventorySignature::PARTY_TENANT));
    }

    public function test_party_role_label_falls_back_to_lowest_sort_order_when_canonical_row_is_absent(): void
    {
        // Proves the resolver is genuinely settings-driven, not hardcoded:
        // deactivate the canonical 'Lessor' row (simulating an agency that
        // no longer uses it) and confirm a custom-named replacement is what
        // displays everywhere.
        ContactType::where('esign_role', 'lessor')->where('name', 'Lessor')->update(['is_active' => false]);
        ContactType::create(['name' => 'Property Owner', 'esign_role' => 'lessor', 'sort_order' => 50, 'is_active' => true]);

        self::assertSame('Property Owner', PartyRoleLabel::for($this->agency->id, RentalInventorySignature::PARTY_LANDLORD));
    }
}
