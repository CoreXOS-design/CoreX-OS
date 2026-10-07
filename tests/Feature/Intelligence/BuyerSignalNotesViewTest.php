<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactNote;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PropertyIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buyer Interest Signals — "Notes (n)" per row (Johan, 2026-10-07). The agent viewing a
 * property can READ the notes recorded on a signal buyer's contact (newest first, author +
 * date) — nothing else: no add / edit / delete from there, only notes the viewer may see
 * under the contact's own visibility rules, and never on the seller's public live link.
 *
 * Spec: .ai/specs/core-matches.md ("Buyer Interest Signals — view-only notes").
 */
final class BuyerSignalNotesViewTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $listingAgent;
    private User $buyerAgent;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        Bus::fake();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin', 'name' => 'Ada Admin',
        ]);
        $this->listingAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Linda Listing',
        ]);
        $this->buyerAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Brian Buyeragent',
        ]);
    }

    // ── the control + the read-only fragment ─────────────────────────────

    public function test_signal_row_shows_notes_count_and_fragment_lists_newest_first_with_author_and_date(): void
    {
        [$property, $buyer] = $this->propertyWithMatchingBuyer($this->admin);
        $this->note($buyer, $this->buyerAgent, 'Viewed twice, keen.', now()->subDays(5));
        $this->note($buyer, $this->admin, 'Went cold, not responding.', now()->subDay());

        $html = $this->actingAs($this->admin)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('Notes (2)', $html);
        $this->assertStringContainsString(route('corex.buyer-notes.show', $buyer->id), $html);
        $this->assertStringContainsString('data-buyer-notes', $html);

        $frag = $this->actingAs($this->admin)->get(route('corex.buyer-notes.show', $buyer))->assertOk()->getContent();
        $this->assertStringContainsString('Went cold, not responding.', $frag);
        $this->assertStringContainsString('Viewed twice, keen.', $frag);
        $this->assertStringContainsString('Ada Admin', $frag, 'author shown');
        $this->assertStringContainsString('Brian Buyeragent', $frag);
        $this->assertStringContainsString(now()->subDay()->format('d M Y'), $frag, 'date shown');
        $this->assertLessThan(
            strpos($frag, 'Viewed twice, keen.'),
            strpos($frag, 'Went cold, not responding.'),
            'newest first'
        );
    }

    public function test_fragment_is_view_only_no_add_edit_or_delete(): void
    {
        [, $buyer] = $this->propertyWithMatchingBuyer($this->admin);
        $this->note($buyer, $this->admin, 'A note.', now());

        $frag = $this->actingAs($this->admin)->get(route('corex.buyer-notes.show', $buyer))->assertOk()->getContent();

        $this->assertStringNotContainsString('<form', $frag);
        $this->assertStringNotContainsString('Delete', $frag);
        $this->assertStringNotContainsString('Edit', $frag);
        $this->assertStringNotContainsString('<textarea', $frag);
    }

    public function test_buyer_with_no_notes_shows_a_plain_zero_not_a_button(): void
    {
        [$property] = $this->propertyWithMatchingBuyer($this->admin);

        $html = $this->actingAs($this->admin)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('Notes (0)', $html);
        $this->assertStringNotContainsString("open-signal-notes', {", $html);
    }

    public function test_deleted_notes_are_not_counted(): void
    {
        [$property, $buyer] = $this->propertyWithMatchingBuyer($this->admin);
        $this->note($buyer, $this->admin, 'Keep.', now());
        $this->note($buyer, $this->admin, 'Archived.', now())->delete();

        $this->actingAs($this->admin);
        $svc = app(PropertyIntelligenceService::class);
        $counts = $svc->getBuyerNoteCounts($svc->getBuyerInterestSignals($property->id));

        $this->assertSame([$buyer->id => 1], $counts);
    }

    // ── visibility: only what the contact's own rules allow ──────────────

    public function test_own_level_agent_who_is_not_the_buyers_primary_agent_gets_no_control_and_no_notes(): void
    {
        $this->ownScopeForAgents();
        // The buyer's primary agent is someone else (even though the listing agent captured them).
        [$property, $buyer] = $this->propertyWithMatchingBuyer($this->listingAgent, $this->buyerAgent);
        $this->note($buyer, $this->buyerAgent, 'Private to the buyer agent: went cold.', now());

        $html = $this->actingAs($this->listingAgent)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-buyer-notes', $html);
        $this->assertStringNotContainsString('went cold', $html);
        $this->actingAs($this->listingAgent)->get(route('corex.buyer-notes.show', $buyer))->assertNotFound();
    }

    public function test_own_level_agent_who_is_the_primary_agent_sees_the_notes_even_if_someone_else_captured_the_buyer(): void
    {
        $this->ownScopeForAgents();
        // Captured by the admin; the listing agent is the primary agent — the rule is the Role Manager scope.
        [$property, $buyer] = $this->propertyWithMatchingBuyer($this->admin, $this->listingAgent);
        $this->note($buyer, $this->buyerAgent, 'Not responding.', now());

        $html = $this->actingAs($this->listingAgent)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('Notes (1)', $html);
        $this->actingAs($this->listingAgent)->get(route('corex.buyer-notes.show', $buyer))
            ->assertOk()->assertSee('Not responding.');
    }

    public function test_a_buyer_in_another_agency_is_never_counted(): void
    {
        [$property, $buyer] = $this->propertyWithMatchingBuyer($this->admin);
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . uniqid()]);
        $otherAdmin = User::factory()->create(['agency_id' => $other->id, 'role' => 'admin']);

        $this->actingAs($otherAdmin);
        $counts = app(PropertyIntelligenceService::class)->getBuyerNoteCounts(collect([['id' => $buyer->id]]));

        $this->assertSame([], $counts);
    }

    // ── the seller's public live link ────────────────────────────────────

    public function test_seller_live_link_never_carries_notes(): void
    {
        [$property, $buyer] = $this->propertyWithMatchingBuyer($this->admin);
        $this->note($buyer, $this->admin, 'Secret buyer note XYZ.', now());
        $link = $this->sellerLink($property);

        $html = $this->get('/property/live/' . $link->token)->assertOk()->getContent();

        $this->assertStringNotContainsString('Secret buyer note XYZ.', $html);
        $this->assertStringNotContainsString('Notes (', $html);
        $this->assertStringNotContainsString('data-buyer-notes', $html);
        $this->assertStringNotContainsString('buyer-notes', $html);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Agents may open properties and read buyer notes at the "own" level (primary agent = the viewer). */
    private function ownScopeForAgents(): void
    {
        foreach (['access_properties' => null, 'properties.view' => 'own', 'buyer_notes.view' => 'own'] as $key => $scope) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
    }

    private function note(Contact $contact, User $author, string $body, $at): ContactNote
    {
        $note = ContactNote::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'contact_id' => $contact->id, 'user_id' => $author->id, 'body' => $body,
        ]);
        DB::table('contact_notes')->where('id', $note->id)->update(['created_at' => $at, 'updated_at' => $at]);

        return $note->fresh();
    }

    /** @return array{0:Property,1:Contact} a listing (agent = listingAgent) + a buyer whose wishlist fits it */
    private function propertyWithMatchingBuyer(User $capturedBy, ?User $primary = null): array
    {
        $suburbId = $this->seedP24Suburb();
        $property = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->listingAgent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active',
            'price' => 1_800_000, 'beds' => 3, 'published_at' => now(), 'p24_suburb_id' => $suburbId,
        ]);

        $buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $capturedBy->id, 'agent_id' => ($primary ?? $capturedBy)->id,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Bea', 'last_name' => 'Buyer ' . Str::random(3),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'bea-' . Str::random(5) . '@example.co.za',
        ]);
        ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'contact_id' => $buyer->id,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'price_min' => 1_500_000, 'price_max' => 2_000_000, 'beds_min' => 3,
            'p24_suburb_ids' => [$suburbId],
        ]);

        return [$property, $buyer];
    }

    private function sellerLink(Property $property): PropertySellerLink
    {
        $seller = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sam', 'last_name' => 'Seller' . Str::random(3),
            'phone' => '083' . random_int(1000000, 9999999),
            'email' => 'sam-' . Str::random(5) . '@example.co.za',
        ]);

        return PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id, 'contact_id' => $seller->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $this->listingAgent->id,
            'generated_at' => now(),
        ]);
    }

    private function seedP24Suburb(): int
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => random_int(1, 999999), 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_province_id' => $provinceId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('p24_suburbs')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_city_id' => $cityId, 'name' => 'Uvongo',
            'slug' => 'uvongo-' . Str::random(5), 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
