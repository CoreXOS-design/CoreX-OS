<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Property↔contact link role (AT-94 root-cause fix). The pivot role must be a
 * required canonical value so the compliance gate's seller/FICA check can read
 * it — the historic free-text/optional field wrote NULL and the gate (which
 * matches owner/seller/landlord/lessor) reported "no sellers linked".
 */
final class PropertyContactRoleTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;
    private int $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Role ' . Str::random(6), 'slug' => 'role-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin',
        ]);
        $this->propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'ROLE-' . Str::random(8), 'title' => 'Role Property',
            'price' => 1_000_000, 'status' => 'active', 'is_demo' => false,
            'listing_type' => 'sale',
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'agent_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeContact(string $phone): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'created_by_user_id' => $this->user->id,
            'first_name' => 'Lindi', 'last_name' => 'Seller', 'phone' => $phone,
        ]);
    }

    private function linkRole(): ?string
    {
        return DB::table('contact_property')->where('property_id', $this->propertyId)->value('role');
    }

    public function test_link_existing_writes_canonical_seller_role(): void
    {
        $c = $this->makeContact('0820000001');

        $this->actingAs($this->user)
            ->postJson(route('corex.properties.contacts.link', $this->propertyId),
                ['contact_id' => $c->id, 'role' => 'seller'])
            ->assertOk();

        $this->assertSame('seller', $this->linkRole());
        // Seller role also materialises the PropertySellerLink side-effect.
        $this->assertDatabaseHas('property_seller_links', [
            'property_id' => $this->propertyId, 'contact_id' => $c->id,
        ]);
    }

    public function test_link_normalizes_odd_case_and_whitespace_role(): void
    {
        $c = $this->makeContact('0820000002');

        $this->actingAs($this->user)
            ->postJson(route('corex.properties.contacts.link', $this->propertyId),
                ['contact_id' => $c->id, 'role' => '  Seller '])
            ->assertOk();

        $this->assertSame('seller', $this->linkRole(), 'odd-case/whitespace role normalised to canonical');
    }

    public function test_link_rejects_blank_role(): void
    {
        $c = $this->makeContact('0820000003');

        $this->actingAs($this->user)
            ->postJson(route('corex.properties.contacts.link', $this->propertyId),
                ['contact_id' => $c->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);

        $this->assertNull($this->linkRole(), 'no NULL-role row written on a rejected link');
    }

    public function test_link_rejects_off_list_role(): void
    {
        $c = $this->makeContact('0820000004');

        $this->actingAs($this->user)
            ->postJson(route('corex.properties.contacts.link', $this->propertyId),
                ['contact_id' => $c->id, 'role' => 'lead'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_buyer_role_is_stored_but_is_not_a_seller_role(): void
    {
        $c = $this->makeContact('0820000005');

        $this->actingAs($this->user)
            ->postJson(route('corex.properties.contacts.link', $this->propertyId),
                ['contact_id' => $c->id, 'role' => 'buyer'])
            ->assertOk();

        $this->assertSame('buyer', $this->linkRole());
        // Buyer is NOT in the gate's seller set → no PropertySellerLink.
        $this->assertDatabaseMissing('property_seller_links', [
            'property_id' => $this->propertyId, 'contact_id' => $c->id,
        ]);
    }

    public function test_create_and_link_requires_role(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('corex.properties.contacts.createAndLink', $this->propertyId),
                ['first_name' => 'New', 'last_name' => 'Seller', 'phone' => '0820000006'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_create_and_link_writes_canonical_role(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('corex.properties.contacts.createAndLink', $this->propertyId),
                ['first_name' => 'New', 'last_name' => 'Seller', 'phone' => '0820000007', 'role' => 'owner'])
            ->assertOk();

        $this->assertSame('owner', $this->linkRole());
    }

    // ── updateRole (FIX 2 — edit role on an existing link) ──

    private function link(Contact $c, string $role): void
    {
        $this->actingAs($this->user)
            ->postJson(route('corex.properties.contacts.link', $this->propertyId),
                ['contact_id' => $c->id, 'role' => $role])
            ->assertOk();
    }

    public function test_update_role_changes_pivot_and_normalizes(): void
    {
        $c = $this->makeContact('0820000010');
        $this->link($c, 'buyer');
        $this->assertSame('buyer', $this->linkRole());

        $this->actingAs($this->user)
            ->putJson(route('corex.properties.contacts.updateRole', [$this->propertyId, $c->id]),
                ['role' => '  Seller '])
            ->assertOk();

        $this->assertSame('seller', $this->linkRole(), 'role updated + normalised without unlink/relink');
        $this->assertDatabaseHas('property_seller_links', [
            'property_id' => $this->propertyId, 'contact_id' => $c->id,
        ]);
    }

    public function test_update_role_rejects_off_list(): void
    {
        $c = $this->makeContact('0820000011');
        $this->link($c, 'seller');

        $this->actingAs($this->user)
            ->putJson(route('corex.properties.contacts.updateRole', [$this->propertyId, $c->id]),
                ['role' => 'lead'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);

        $this->assertSame('seller', $this->linkRole(), 'rejected update leaves the role unchanged');
    }

    public function test_update_role_404_when_contact_not_linked(): void
    {
        $c = $this->makeContact('0820000012'); // never linked

        $this->actingAs($this->user)
            ->putJson(route('corex.properties.contacts.updateRole', [$this->propertyId, $c->id]),
                ['role' => 'seller'])
            ->assertStatus(404);
    }

    /** Regression: the role-select change must not 500 the property page; the
     *  confirm-step "Link as" select + the inline "Edit role" affordance render. */
    public function test_property_show_page_renders_role_ui(): void
    {
        $c = $this->makeContact('0820000013');
        $this->link($c, 'seller');

        $resp = $this->actingAs($this->user)->get(route('corex.properties.show', $this->propertyId));
        $resp->assertOk();
        $resp->assertSee('Link as');               // confirm-step role select
        $resp->assertSee('Edit role');             // inline edit on the linked row
        $resp->assertSee('value="seller"', false); // canonical option, default on a sale listing
    }

    /** The create/new ($isNew) path renders the same view with a fresh
     *  Property() — null-safe default must not blow up either. */
    public function test_property_create_page_renders(): void
    {
        $this->actingAs($this->user)->get(route('corex.properties.create'))->assertOk();
    }
}
