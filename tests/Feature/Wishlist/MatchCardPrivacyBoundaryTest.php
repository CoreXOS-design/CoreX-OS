<?php

namespace Tests\Feature\Wishlist;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Match-card v2 (address, Seller + Access popover, access_notes, copy-address)
 * is AGENT-ONLY (<x-match-card> — Core Matches results + Buyer Pipeline
 * Wishlist accordion). The CLIENT-FACING surfaces — the public shared-match
 * link (shared.match) and the buyer portal (buyer-portal.show) — render their
 * OWN separate Blade files that never include <x-match-card> and never read
 * property.address / sellerOwnerContact() / access_notes. This test proves
 * that boundary holds by seeding a property that WOULD leak every one of
 * those fields if either public page regressed to reusing the agent card.
 */
class MatchCardPrivacyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Contact $buyer;
    private Contact $seller;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
            'name' => 'Elize Agent', 'cell' => '0821234567',
        ]);

        $this->seller = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Secretive', 'last_name' => 'Seller',
            'phone' => '0839998888', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->property = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'Sea-view family home',
            'address' => '17 Secret Close, Uvongo',
            'access_notes' => 'Keys held by managing agency — call Steve on 011 011 0110',
            'suburb' => 'Uvongo', 'city' => 'Margate', 'property_type' => 'house', 'listing_type' => 'sale',
            'status' => 'active', 'price' => 850000, 'beds' => 3, 'baths' => 2,
            'gallery_images_json' => ['/storage/properties/1/photo.jpg'],
            'published_at' => now(),
        ]);
        $this->property->contacts()->attach($this->seller->id, ['role' => 'seller']);

        $this->buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Thabo', 'last_name' => Str::random(4),
            'phone' => '083' . random_int(1000000, 9999999),
            'email' => 'thabo-' . Str::random(5) . '@example.co.za',
        ]);
    }

    private function assertNoAgentOnlyData(\Illuminate\Testing\TestResponse $res): void
    {
        $res->assertStatus(200);
        // Would only appear via <x-match-card>'s address-as-header or copy-address icon.
        $res->assertDontSee('17 Secret Close', false);
        // Would only appear via the Seller + Access popover.
        $res->assertDontSee('Secretive', false);
        $res->assertDontSee('Seller', false);
        $res->assertDontSee('0839998888', false);
        $res->assertDontSee('Keys held by managing agency', false);
    }

    public function test_shared_match_link_renders_no_agent_only_data(): void
    {
        $match = ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'contact_id' => $this->buyer->id,
            'created_by_user_id' => $this->agent->id, 'name' => 'Match ' . Str::random(4),
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            // Budget criteria — isCountable() must be true (an empty wishlist
            // matches nothing, AT-71) for the property to actually surface,
            // otherwise the assertSee sanity check below is a false negative.
            'price_min' => 650000, 'price_max' => 1200000, 'suburb' => 'Uvongo',
        ]);
        $slug = $match->fresh()->share_slug ?: $match->fresh()->share_token;

        $res = $this->get("/shared/match/{$slug}");

        // Sanity: the property itself is actually on the page (not a false-negative
        // from an empty result set) before trusting the assertDontSee assertions.
        $res->assertSee('Sea-view family home', false);
        $res->assertSee('Uvongo', false);

        $this->assertNoAgentOnlyData($res);
    }

    public function test_buyer_portal_renders_no_agent_only_data(): void
    {
        DB::table('property_buyer_matches')->insert([
            'property_id' => $this->property->id, 'contact_id' => $this->buyer->id,
            'agency_id' => $this->agency->id, 'score' => 90, 'tier' => 'strong',
            'breakdown' => json_encode(['engine' => 'canonical']), 'missing_features' => json_encode([]),
            'computed_at' => now(),
        ]);
        $token = bin2hex(random_bytes(16));
        DB::table('buyer_portal_links')->insert([
            'contact_id' => $this->buyer->id, 'agency_id' => $this->agency->id, 'token' => $token,
            'generated_by_user_id' => $this->agent->id, 'generated_at' => now(),
            'access_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->get("/buyer/portal/{$token}");

        $res->assertSee('Sea-view family home', false);

        $this->assertNoAgentOnlyData($res);
    }
}
