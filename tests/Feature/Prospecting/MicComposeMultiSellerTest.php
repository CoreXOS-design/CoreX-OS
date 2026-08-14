<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MIC compose — multi-seller link (Part A) + per-contact TVA number picker (Part B), Johan 2026-08-14.
 *
 * Property (1) → many seller-links → many STANDALONE Contacts, each keyed on its own SA ID (never
 * merged). The TVA picker writes the agent-chosen scraped numbers onto the RESPECTIVE contact.
 */
final class MicComposeMultiSellerTest extends TestCase
{
    use RefreshDatabase;

    /** Two sellers link to the ONE property as two distinct ID-keyed contacts — never merged. */
    public function test_links_multiple_sellers_to_one_property_as_distinct_contacts(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1486 Beaumont Drive');
        $url = route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]);

        $this->actingAs(User::find($userId))
            ->postJson($url, ['first_name' => 'Marcelle', 'last_name' => 'Petersen', 'id_number' => '8001015009087'])
            ->assertOk()->assertJsonPath('sellers.0.id_number', '8001015009087');

        $this->actingAs(User::find($userId))
            ->postJson($url, ['first_name' => 'Dudley', 'last_name' => 'Petersen', 'id_number' => '9001010001088'])
            ->assertOk();

        $propertyId = (int) DB::table('prospecting_listings')->where('id', $listingId)->value('matched_property_id');
        $this->assertNotSame(0, $propertyId, 'the listing is promoted to a property on first link');

        // Two DISTINCT contacts, both linked as seller to the SAME property.
        $a = Contact::where('id_number', '8001015009087')->first();
        $b = Contact::where('id_number', '9001010001088')->first();
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a->id, $b->id, 'each seller is its own contact — never merged');
        $this->assertDatabaseHas('contact_property', ['property_id' => $propertyId, 'contact_id' => $a->id, 'role' => 'seller']);
        $this->assertDatabaseHas('contact_property', ['property_id' => $propertyId, 'contact_id' => $b->id, 'role' => 'seller']);
        $this->assertSame(2, DB::table('contact_property')->where('property_id', $propertyId)->where('role', 'seller')->count());
    }

    /** Unlinking one seller leaves the other linked; both contacts survive. */
    public function test_unlink_removes_only_that_seller_link(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1486 Beaumont Drive');
        $linkUrl = route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]);
        $unlinkUrl = route('seller-outreach.entry.unlink-seller-prospecting', ['prospectingListingId' => $listingId]);

        $this->actingAs(User::find($userId))->postJson($linkUrl, ['first_name' => 'Marcelle', 'last_name' => 'Petersen', 'id_number' => '8001015009087'])->assertOk();
        $this->actingAs(User::find($userId))->postJson($linkUrl, ['first_name' => 'Dudley', 'last_name' => 'Petersen', 'id_number' => '9001010001088'])->assertOk();

        $a = Contact::where('id_number', '8001015009087')->first();
        $propertyId = (int) DB::table('prospecting_listings')->where('id', $listingId)->value('matched_property_id');

        $this->actingAs(User::find($userId))->postJson($unlinkUrl, ['contact_id' => $a->id])->assertOk();

        $this->assertSame(1, DB::table('contact_property')->where('property_id', $propertyId)->where('role', 'seller')->count());
        $this->assertDatabaseMissing('contact_property', ['property_id' => $propertyId, 'contact_id' => $a->id, 'role' => 'seller']);
        $this->assertNotNull(Contact::find($a->id), 'the unlinked contact still exists');
    }

    /** The TVA picker writes ONLY the picked numbers, onto the RESPECTIVE seller contact. */
    public function test_tva_ingest_writes_picked_numbers_to_the_respective_contact(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1486 Beaumont Drive');
        $linkUrl = route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]);

        // Link one seller.
        $this->actingAs(User::find($userId))->postJson($linkUrl, ['first_name' => 'Marcelle', 'last_name' => 'Petersen', 'id_number' => '8001015009087'])->assertOk();
        $seller = Contact::where('id_number', '8001015009087')->first();

        // A TVA capture for that seller (matched by id_number) with two scraped numbers.
        $captureId = DB::table('tva_contact_captures')->insertGetId([
            'agency_id' => $agencyId, 'captured_by_user_id' => $userId, 'id_number' => '8001015009087',
            'first_name' => 'Marcelle', 'surname' => 'Petersen', 'source' => 'tva', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pickId = DB::table('tva_contact_capture_items')->insertGetId([
            'tva_contact_capture_id' => $captureId, 'type' => 'cell', 'value' => '0832433166', 'opted_out' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $skipId = DB::table('tva_contact_capture_items')->insertGetId([
            'tva_contact_capture_id' => $captureId, 'type' => 'cell', 'value' => '0114729738', 'opted_out' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Pick only the first number.
        $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.entry.tva-ingest-prospecting', ['prospectingListingId' => $listingId]),
                ['contact_id' => $seller->id, 'item_ids' => [$pickId]])
            ->assertOk();

        // Only the picked number landed on the seller; the skipped one did not; the item is marked ingested.
        $this->assertDatabaseHas('contact_phones', ['contact_id' => $seller->id, 'phone' => '0832433166']);
        $this->assertDatabaseMissing('contact_phones', ['contact_id' => $seller->id, 'phone' => '0114729738']);
        $this->assertNotNull(DB::table('tva_contact_capture_items')->where('id', $pickId)->value('ingested_at'));
        $this->assertNull(DB::table('tva_contact_capture_items')->where('id', $skipId)->value('ingested_at'));
        $this->assertSame($seller->id, (int) DB::table('tva_contact_capture_items')->where('id', $pickId)->value('ingested_contact_id'));
    }

    /** TVA numbers can't be written to a contact that isn't a seller on this property. */
    public function test_tva_ingest_rejects_a_non_seller_contact(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1486 Beaumont Drive');
        // Promote the property by linking a real seller first.
        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]),
            ['first_name' => 'Marcelle', 'last_name' => 'Petersen', 'id_number' => '8001015009087'])->assertOk();

        $stranger = Contact::create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'first_name' => 'Not', 'last_name' => 'ASeller', 'phone' => '', 'id_number' => '9001010001088']);
        $captureId = DB::table('tva_contact_captures')->insertGetId(['agency_id' => $agencyId, 'captured_by_user_id' => $userId, 'id_number' => '9001010001088', 'first_name' => 'Not', 'surname' => 'ASeller', 'source' => 'tva', 'created_at' => now(), 'updated_at' => now()]);
        $itemId = DB::table('tva_contact_capture_items')->insertGetId(['tva_contact_capture_id' => $captureId, 'type' => 'cell', 'value' => '0820000000', 'opted_out' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.entry.tva-ingest-prospecting', ['prospectingListingId' => $listingId]),
                ['contact_id' => $stranger->id, 'item_ids' => [$itemId]])
            ->assertStatus(422);

        $this->assertDatabaseMissing('contact_phones', ['contact_id' => $stranger->id, 'phone' => '0820000000']);
    }

    // ── Compose redesign: working-set gate + primary + interstitial ──────

    /** Continue passes on LINKED sellers with numbers even with an EMPTY form, and lands on the interstitial. */
    public function test_continue_uses_linked_sellers_with_numbers_and_empty_form(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1486 Beaumont Drive');
        $link = route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]);

        $this->actingAs(User::find($userId))->postJson($link, ['first_name' => 'Marcelle', 'last_name' => 'Petersen', 'id_number' => '8001015009087'])->assertOk();
        $this->actingAs(User::find($userId))->postJson($link, ['first_name' => 'Dudley', 'last_name' => 'Petersen', 'id_number' => '9001010001088'])->assertOk();
        $propertyId = (int) DB::table('prospecting_listings')->where('id', $listingId)->value('matched_property_id');

        // Each seller gets a number (as if ticked from TVA onto its own contact).
        foreach (['8001015009087' => '0820000001', '9001010001088' => '0820000002'] as $idn => $num) {
            Contact::withoutGlobalScopes()->where('id_number', $idn)->first()->phones()->create(['agency_id' => $agencyId, 'phone' => $num, 'label' => 'TVA']);
        }

        // Create & continue with an EMPTY form — the old phone/email gate is gone.
        $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $listingId]), [])
            ->assertRedirect(route('seller-outreach.entry.pitch-ready-prospecting', ['prospectingListingId' => $listingId]));

        $this->assertSame(2, DB::table('contact_property')->where('property_id', $propertyId)->where('role', 'seller')->count());
        $this->assertSame(1, DB::table('contact_property')->where('property_id', $propertyId)->where('role', 'seller')->where('is_primary', true)->count());
    }

    /** Continue is BLOCKED (back with error) when a linked seller has no number and no dead-end. */
    public function test_continue_blocks_a_seller_with_no_number_and_no_dead_end(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1486 Beaumont Drive');
        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]),
            ['first_name' => 'Marcelle', 'last_name' => 'Petersen', 'id_number' => '8001015009087'])->assertOk();

        $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $listingId]), [])
            ->assertRedirect(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $listingId]))
            ->assertSessionHas('error');
    }

    /** A per-seller "No contact details" acknowledgement lets continue through (seller has nothing to reach). */
    public function test_per_seller_dead_end_unblocks_continue(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1486 Beaumont Drive');
        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]),
            ['first_name' => 'Marcelle', 'last_name' => 'Petersen', 'id_number' => '8001015009087'])->assertOk();
        $contact = Contact::withoutGlobalScopes()->where('id_number', '8001015009087')->first();

        // Mark the seller "No contact details".
        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.dead-end-seller-prospecting', ['prospectingListingId' => $listingId]),
            ['contact_id' => $contact->id, 'reason' => 'not_in_tva'])->assertOk();
        $this->assertDatabaseHas('contact_dead_end_flags', ['contact_id' => $contact->id, 'reason' => 'not_in_tva']);

        // Continue no longer blocks — all sellers dead-end → lands on the property (nothing to pitch).
        $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $listingId]), [])
            ->assertRedirectContains('/properties/');
    }

    /** Click-to-make-primary persists on contact_property. */
    public function test_set_primary_persists(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1486 Beaumont Drive');
        $link = route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]);
        $this->actingAs(User::find($userId))->postJson($link, ['first_name' => 'Marcelle', 'last_name' => 'Petersen', 'id_number' => '8001015009087'])->assertOk();
        $this->actingAs(User::find($userId))->postJson($link, ['first_name' => 'Dudley', 'last_name' => 'Petersen', 'id_number' => '9001010001088'])->assertOk();

        $propertyId = (int) DB::table('prospecting_listings')->where('id', $listingId)->value('matched_property_id');
        $dudley = Contact::withoutGlobalScopes()->where('id_number', '9001010001088')->first();

        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.primary-seller-prospecting', ['prospectingListingId' => $listingId]),
            ['contact_id' => $dudley->id])->assertOk();

        $this->assertSame(1, DB::table('contact_property')->where('property_id', $propertyId)->where('is_primary', true)->count());
        $this->assertTrue((bool) DB::table('contact_property')->where('property_id', $propertyId)->where('contact_id', $dudley->id)->value('is_primary'));
    }

    /** Single-seller case still flows cleanly to the interstitial (with a number). */
    public function test_single_seller_still_flows_to_interstitial(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1486 Beaumont Drive');
        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]),
            ['first_name' => 'Marcelle', 'last_name' => 'Petersen', 'id_number' => '8001015009087'])->assertOk();
        Contact::withoutGlobalScopes()->where('id_number', '8001015009087')->first()->phones()->create(['agency_id' => $agencyId, 'phone' => '0820000009', 'label' => 'TVA']);

        $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $listingId]), [])
            ->assertRedirect(route('seller-outreach.entry.pitch-ready-prospecting', ['prospectingListingId' => $listingId]));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent', 'name' => 'Agency Agent']);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);

        return [$agencyId, $user->id];
    }

    private function seedListing(int $agencyId, string $address): int
    {
        $capturedBy = (int) DB::table('users')->where('agency_id', $agencyId)->orderBy('id')->value('id');

        return (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id' => $agencyId, 'portal_source' => 'p24', 'portal_ref' => 'test-' . Str::random(10),
            'portal_url' => 'https://example.test/' . Str::random(6), 'captured_by_user_id' => $capturedBy,
            'is_active' => true, 'address' => $address, 'suburb' => 'Ramsgate', 'price' => 0,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
