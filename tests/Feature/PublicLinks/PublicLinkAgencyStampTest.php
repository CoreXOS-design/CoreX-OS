<?php

namespace Tests\Feature\PublicLinks;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: the public seller live link and the buyer portal link both write
 * to tables whose `agency_id` column is NOT NULL (multi-tenancy migrations
 * 2026_05_23_020800 / 2026_05_23_031000). The write sites are raw DB::table()
 * inserts, so BelongsToAgency does NOT auto-stamp agency_id — the deploy that
 * ran those migrations 500'd every seller view and every buyer-link generation
 * with "1364 Field 'agency_id' doesn't have a default value".
 *
 * These tests lock the fix: agency_id is stamped from the correct pillar, and a
 * public seller page never 500s.
 */
class PublicLinkAgencyStampTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The seller live page is a full public Blade view that pulls hashed assets
        // through @vite; stub the manifest so the test exercises the controller, not
        // the frontend build.
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);
    }

    private function makeProperty(): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000, 'published_at' => now(),
        ]);
    }

    private function makeContact(): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Seller', 'last_name' => Str::random(4),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'seller-' . Str::random(5) . '@example.co.za',
        ]);
    }

    /** The seller live page renders 200 with zero intelligence data and stamps agency_id on the access row. */
    public function test_seller_live_link_renders_and_stamps_agency_id_on_access(): void
    {
        $property = $this->makeProperty();
        $contact = $this->makeContact();
        $link = PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id, 'contact_id' => $contact->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $this->user->id,
            'generated_at' => now(),
        ]);

        $response = $this->get('/property/live/' . $link->token);

        $response->assertStatus(200);
        $this->assertDatabaseHas('property_seller_link_accesses', [
            'link_id' => $link->id,
            'agency_id' => $this->agency->id,
        ]);
    }

    /** A revoked link still short-circuits to 410 without touching the access table. */
    public function test_revoked_seller_link_returns_410(): void
    {
        $property = $this->makeProperty();
        $contact = $this->makeContact();
        $link = PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id, 'contact_id' => $contact->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $this->user->id,
            'generated_at' => now(), 'revoked_at' => now(),
        ]);

        $this->get('/property/live/' . $link->token)->assertStatus(410);
    }

    /** Generating a buyer portal link stamps agency_id from the contact pillar. */
    public function test_buyer_portal_link_generation_stamps_agency_id(): void
    {
        $contact = $this->makeContact();

        $response = $this->actingAs($this->user)
            ->post(route('command-center.buyers.portal-links.generate'), ['contact_id' => $contact->id]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('buyer_portal_links', [
            'contact_id' => $contact->id,
            'agency_id' => $this->agency->id,
        ]);
    }
}
