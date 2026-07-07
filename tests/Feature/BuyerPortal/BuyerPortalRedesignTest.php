<?php

namespace Tests\Feature\BuyerPortal;

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
 * AT-204 — Buyer Portal redesign ("Your Property Matches", public, token-gated).
 *
 * Locks: the page renders with photo cards + an HONEST match-% basis, the three
 * buyer actions work and — critically — the response write STAMPS agency_id
 * (buyer_property_responses.agency_id is NOT NULL with no default; a raw insert
 * that omits it 500s every buyer on live). Also: zero-matches state, revoked 410,
 * idempotent responses, deleted-related-property renders, actioned states.
 */
class BuyerPortalRedesignTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        // Public Blade page pulls hashed assets via bunny/CDN, not @vite, but stub
        // vite defensively to keep the test exercising the controller.
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role' => 'agent',
            'name' => 'Elize Agent',
            'cell' => '0821234567',
            'email' => 'elize@hfc.co.za',
            'ffc_number' => 'FFC12345',
        ]);
    }

    private function makeContact(array $extra = []): Contact
    {
        return Contact::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'agent_id' => $this->agent->id,
            'is_buyer' => true,
            'buyer_state' => 'new',
            'first_name' => 'Thabo',
            'last_name' => Str::random(4),
            'phone' => '083' . random_int(1000000, 9999999),
            'email' => 'thabo-' . Str::random(5) . '@example.co.za',
        ], $extra));
    }

    private function makeProperty(array $extra = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id,
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'Sea-view family home',
            'suburb' => 'Uvongo',
            'city' => 'Margate',
            'property_type' => 'house',
            'status' => 'active',
            'price' => 850000,
            'beds' => 3,
            'baths' => 2,
            'garages' => 2,
            'gallery_images_json' => ['/storage/properties/1/photo.jpg'],
            'published_at' => now(),
        ], $extra));
    }

    private function wishlist(int $contactId, array $criteria): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id,
            'contact_id' => $contactId,
            'status' => ContactMatch::STATUS_ACTIVE,
            'listing_type' => 'sale',
            'is_primary' => true,
        ], $criteria));
    }

    private function cacheMatch(int $contactId, int $propertyId, int $score, string $tier): void
    {
        DB::table('property_buyer_matches')->insert([
            'property_id' => $propertyId,
            'contact_id' => $contactId,
            'agency_id' => $this->agency->id,
            'score' => $score,
            'tier' => $tier,
            'breakdown' => json_encode(['engine' => 'canonical']),
            'missing_features' => json_encode([]),
            'computed_at' => now(),
        ]);
    }

    private function link(int $contactId): string
    {
        $token = bin2hex(random_bytes(16));
        DB::table('buyer_portal_links')->insert([
            'contact_id' => $contactId,
            'agency_id' => $this->agency->id,
            'token' => $token,
            'generated_by_user_id' => $this->agent->id,
            'generated_at' => now(),
            'access_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $token;
    }

    /** RICH: renders 200 with greeting, photo card, agent card, footer. */
    public function test_rich_buyer_page_renders_with_photos_and_agent(): void
    {
        $contact = $this->makeContact();
        $this->wishlist($contact->id, ['price_min' => 650000, 'price_max' => 900000]);
        $prop = $this->makeProperty();
        $this->cacheMatch($contact->id, $prop->id, 100, 'perfect');
        $token = $this->link($contact->id);

        $res = $this->get("/buyer/portal/{$token}");

        $res->assertStatus(200);
        $res->assertSee('Hi Thabo', false);
        $res->assertSee('Sea-view family home', false);
        $res->assertSee('/storage/properties/1/photo.jpg', false); // photo thumbnail source
        $res->assertSee('Elize Agent', false);                     // agent card
        $res->assertSee('tel:0821234567', false);                  // tap-to-call
        $res->assertSee('wa.me/27821234567', false);               // whatsapp
        $res->assertSee('Home Finders Coastal', false);            // footer / branding
    }

    /** HONESTY: a budget-only buyer sees the basis, never a context-free 100%. */
    public function test_budget_only_buyer_shows_honest_basis(): void
    {
        $contact = $this->makeContact();
        $this->wishlist($contact->id, ['price_min' => 650000, 'price_max' => 900000]);
        $prop = $this->makeProperty();
        $this->cacheMatch($contact->id, $prop->id, 100, 'perfect');
        $token = $this->link($contact->id);

        $res = $this->get("/buyer/portal/{$token}");

        $res->assertStatus(200);
        $res->assertSee('Matched on your budget', false);          // per-card honesty line
        $res->assertSee('Budget', false);                          // brief row
        $res->assertSee('R 650,000', false);
        $res->assertSee('the preference you\'ve shared so far', false); // one-criterion nudge
    }

    /** Multi-criteria buyer: the basis names the criteria (not a bare number). */
    public function test_multi_criteria_basis_text(): void
    {
        $contact = $this->makeContact();
        $wl = $this->wishlist($contact->id, [
            'price_min' => 650000, 'price_max' => 900000, 'beds_min' => 3,
        ]);
        // budget + bedrooms => "your budget & bedrooms"
        $this->assertSame('your budget & bedrooms', $wl->matchBasisText());

        $prop = $this->makeProperty();
        $this->cacheMatch($contact->id, $prop->id, 88, 'strong');
        $token = $this->link($contact->id);

        $this->get("/buyer/portal/{$token}")
            ->assertStatus(200)
            ->assertSee('Matched on your budget &amp; bedrooms', false);
    }

    /** ZERO matches: designed empty state, not a broken page. Agent still shown. */
    public function test_zero_matches_state(): void
    {
        $contact = $this->makeContact();
        $this->wishlist($contact->id, ['price_min' => 650000, 'price_max' => 900000]);
        $token = $this->link($contact->id);

        $res = $this->get("/buyer/portal/{$token}");

        $res->assertStatus(200);
        $res->assertSee('No matches just yet', false);
        $res->assertSee('Elize Agent', false); // who to call is still present
    }

    /** REVOKED link → 410 with the friendly page. */
    public function test_revoked_link_returns_410(): void
    {
        $contact = $this->makeContact();
        $token = $this->link($contact->id);
        DB::table('buyer_portal_links')->where('token', $token)->update(['revoked_at' => now()]);

        $this->get("/buyer/portal/{$token}")
            ->assertStatus(410)
            ->assertSee('no longer active', false);
    }

    /**
     * CRITICAL: a buyer response stamps agency_id (live 500 regression). All three
     * actions are the buyer-loop heartbeat.
     */
    public function test_respond_stamps_agency_id(): void
    {
        $contact = $this->makeContact();
        $prop = $this->makeProperty();
        $token = $this->link($contact->id);

        $res = $this->post("/buyer/portal/{$token}/respond", [
            'property_id' => $prop->id,
            'response' => 'interested',
        ]);

        $res->assertRedirect();
        $this->assertDatabaseHas('buyer_property_responses', [
            'contact_id' => $contact->id,
            'property_id' => $prop->id,
            'response' => 'interested',
            'agency_id' => $this->agency->id,
        ]);
    }

    /** Each of the three responses is accepted and stamped. */
    public function test_all_three_actions_work(): void
    {
        foreach (['interested', 'not_interested', 'viewing_requested'] as $action) {
            $contact = $this->makeContact();
            $prop = $this->makeProperty();
            $token = $this->link($contact->id);

            $this->post("/buyer/portal/{$token}/respond", [
                'property_id' => $prop->id,
                'response' => $action,
            ])->assertRedirect();

            $this->assertDatabaseHas('buyer_property_responses', [
                'contact_id' => $contact->id,
                'property_id' => $prop->id,
                'response' => $action,
                'agency_id' => $this->agency->id,
            ]);
        }
    }

    /** IDEMPOTENT: changing the response updates the same row, never duplicates. */
    public function test_response_is_idempotent(): void
    {
        $contact = $this->makeContact();
        $prop = $this->makeProperty();
        $token = $this->link($contact->id);

        $this->post("/buyer/portal/{$token}/respond", ['property_id' => $prop->id, 'response' => 'interested']);
        $this->post("/buyer/portal/{$token}/respond", ['property_id' => $prop->id, 'response' => 'viewing_requested']);

        $rows = DB::table('buyer_property_responses')
            ->where('contact_id', $contact->id)->where('property_id', $prop->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('viewing_requested', $rows->first()->response);
    }

    /** An already-actioned card shows the state and hides the action buttons. */
    public function test_actioned_state_is_visible(): void
    {
        $contact = $this->makeContact();
        $this->wishlist($contact->id, ['price_min' => 650000, 'price_max' => 900000]);
        $prop = $this->makeProperty();
        $this->cacheMatch($contact->id, $prop->id, 100, 'perfect');
        DB::table('buyer_property_responses')->insert([
            'contact_id' => $contact->id, 'agency_id' => $this->agency->id, 'property_id' => $prop->id,
            'response' => 'interested', 'source' => 'buyer_portal', 'responded_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = $this->link($contact->id);

        $res = $this->get("/buyer/portal/{$token}");
        $res->assertStatus(200);
        $res->assertSee("You're interested", false);
        $res->assertDontSee('value="viewing_requested"', false); // buttons gone for this card
    }

    /** ROBUST: a cached match whose property was soft-deleted renders without a 500. */
    public function test_deleted_related_property_does_not_break_page(): void
    {
        $contact = $this->makeContact();
        $this->wishlist($contact->id, ['price_min' => 650000, 'price_max' => 900000]);
        $prop = $this->makeProperty(['title' => 'Since-archived home']);
        $this->cacheMatch($contact->id, $prop->id, 95, 'perfect');
        $prop->delete(); // soft-delete — cache row remains, property drops out of whereIn
        $token = $this->link($contact->id);

        $res = $this->get("/buyer/portal/{$token}");
        $res->assertStatus(200);
        $res->assertDontSee('Since-archived home', false); // gracefully skipped
    }

    /** ISOLATION: only this buyer's matched properties appear. */
    public function test_only_this_buyers_matches_shown(): void
    {
        $mine = $this->makeContact(['first_name' => 'Mine']);
        $other = $this->makeContact(['first_name' => 'Other']);
        $this->wishlist($mine->id, ['price_min' => 650000, 'price_max' => 900000]);

        $myProp = $this->makeProperty(['title' => 'My matched home']);
        $otherProp = $this->makeProperty(['title' => 'Other buyer home']);
        $this->cacheMatch($mine->id, $myProp->id, 100, 'perfect');
        $this->cacheMatch($other->id, $otherProp->id, 100, 'perfect');

        $token = $this->link($mine->id);
        $res = $this->get("/buyer/portal/{$token}");

        $res->assertStatus(200);
        $res->assertSee('My matched home', false);
        $res->assertDontSee('Other buyer home', false);
    }
}
