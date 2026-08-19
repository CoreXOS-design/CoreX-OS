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

    // ── R1/R2/R3 reversibility ───────────────────────────────────────────

    /** R1 — selecting a deed auto-links its owners as sellers and follows the deed; reselect replaces. */
    public function test_select_deed_links_owners_and_reselect_replaces(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, 'R1 Portal Road');
        $deedA = $this->seedDeed($agencyId, [['Alpha One', '8001015009087'], ['Alpha Two', '9001010001088']]);
        $deedB = $this->seedDeed($agencyId, [['Beta Solo', '6904050051082']]);
        $url = fn () => route('seller-outreach.entry.link-deed-prospecting', ['prospectingListingId' => $listingId]);

        $this->actingAs(User::find($userId))->postJson($url(), ['tracked_property_id' => $deedA])->assertOk()
            ->assertJsonPath('linked_deed.tracked_property_id', $deedA);
        $pid = (int) DB::table('prospecting_listings')->where('id', $listingId)->value('matched_property_id');
        $this->assertSame(2, DB::table('contact_property')->where('property_id', $pid)->where('role', 'seller')->count());

        // Reselect deed B → sellers REPLACE (deed-sourced Alpha owners dropped, Beta added).
        $this->actingAs(User::find($userId))->postJson($url(), ['tracked_property_id' => $deedB])->assertOk();
        $this->assertSame(1, DB::table('contact_property')->where('property_id', $pid)->where('role', 'seller')->count());
        $this->assertDatabaseHas('contact_property', ['property_id' => $pid, 'role' => 'seller', 'contact_id' => Contact::where('id_number', '6904050051082')->value('id')]);
    }

    /** R1 — unlink the deed drops deed-sourced sellers (keeps manual) and clears the link. */
    public function test_unlink_deed_reverts(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, 'R1u Road');
        $deed = $this->seedDeed($agencyId, [['Deed Owner', '8001015009087']]);
        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.link-deed-prospecting', ['prospectingListingId' => $listingId]), ['tracked_property_id' => $deed])->assertOk();
        $pid = (int) DB::table('prospecting_listings')->where('id', $listingId)->value('matched_property_id');

        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.unlink-deed-prospecting', ['prospectingListingId' => $listingId]), [])->assertOk()
            ->assertJsonPath('linked_deed', null);
        $this->assertSame(0, DB::table('contact_property')->where('property_id', $pid)->where('role', 'seller')->count());
        $this->assertNull(DB::table('prospecting_listings')->where('id', $listingId)->value('linked_deed_tracked_property_id'));
    }

    /** R2 — a removed deed owner stays removed; the deed re-link does not re-add them. */
    public function test_removal_is_sticky_against_deed_relink(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, 'R2 Road');
        $deed = $this->seedDeed($agencyId, [['Keep Me', '8001015009087'], ['Remove Me', '9001010001088']]);
        $url = fn () => route('seller-outreach.entry.link-deed-prospecting', ['prospectingListingId' => $listingId]);
        $this->actingAs(User::find($userId))->postJson($url(), ['tracked_property_id' => $deed])->assertOk();
        $pid = (int) DB::table('prospecting_listings')->where('id', $listingId)->value('matched_property_id');
        $removeMe = Contact::where('id_number', '9001010001088')->value('id');

        // Remove, then re-link the SAME deed — the removed owner must NOT come back.
        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.unlink-seller-prospecting', ['prospectingListingId' => $listingId]), ['contact_id' => $removeMe])->assertOk();
        $this->assertDatabaseHas('prospecting_seller_removals', ['prospecting_listing_id' => $listingId, 'id_number' => '9001010001088']);
        $this->actingAs(User::find($userId))->postJson($url(), ['tracked_property_id' => $deed])->assertOk();
        $this->assertSame(1, DB::table('contact_property')->where('property_id', $pid)->where('role', 'seller')->count());

        // A deliberate re-add clears the sticky removal.
        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]), ['contact_id' => $removeMe])->assertOk();
        $this->assertDatabaseMissing('prospecting_seller_removals', ['prospecting_listing_id' => $listingId, 'id_number' => '9001010001088']);
        $this->assertSame(2, DB::table('contact_property')->where('property_id', $pid)->where('role', 'seller')->count());
    }

    /** R3 — remove one number, and set which is primary. */
    public function test_remove_and_primary_number(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, 'R3 Road');
        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]),
            ['first_name' => 'Num', 'last_name' => 'Seller', 'id_number' => '8001015009087'])->assertOk();
        $contact = Contact::withoutGlobalScopes()->where('id_number', '8001015009087')->first();
        $contact->phones()->create(['agency_id' => $agencyId, 'phone' => '0820000001', 'label' => 't']);
        $contact->phones()->create(['agency_id' => $agencyId, 'phone' => '0820000002', 'label' => 't']);

        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.primary-number-prospecting', ['prospectingListingId' => $listingId]),
            ['contact_id' => $contact->id, 'type' => 'phone', 'value' => '0820000002'])->assertOk();
        $this->assertTrue((bool) $contact->phones()->where('phone', '0820000002')->value('is_primary'));
        $this->assertFalse((bool) $contact->phones()->where('phone', '0820000001')->value('is_primary'));

        $this->actingAs(User::find($userId))->postJson(route('seller-outreach.entry.remove-number-prospecting', ['prospectingListingId' => $listingId]),
            ['contact_id' => $contact->id, 'type' => 'phone', 'value' => '0820000001'])->assertOk();
        $this->assertSame(1, $contact->phones()->count());
        $this->assertDatabaseMissing('contact_phones', ['contact_id' => $contact->id, 'phone' => '0820000001']);
    }

    // ── Entity (company / CC) seller linking (Johan 2026-08-14) ──────────

    /** The company ENTITY owner links as a seller via its resolved contact_id — no SA ID, no silent fail. */
    public function test_entity_owner_links_as_seller_via_contact_id(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1502 Beaumont Drive');
        $entity = $this->makeEntity($agencyId, 'Beaumont Prop Cc 1502', '2010/017928/23');

        $this->actingAs(User::find($userId))
            ->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]),
                ['contact_id' => $entity->id])
            ->assertOk()
            ->assertJsonPath('sellers.0.is_entity', true)
            ->assertJsonPath('sellers.0.display_name', 'Beaumont Prop Cc 1502')
            ->assertJsonPath('sellers.0.entity_reg_no', '2010/017928/23');

        $propertyId = (int) DB::table('prospecting_listings')->where('id', $listingId)->value('matched_property_id');
        $this->assertDatabaseHas('contact_property', ['property_id' => $propertyId, 'contact_id' => $entity->id, 'role' => 'seller']);
    }

    /** An entity owner with no resolved contact links off its REGISTRATION number (never a 13-digit SA ID) and dedupes. */
    public function test_entity_owner_links_via_entity_payload_and_dedupes_on_reg_no(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1502 Beaumont Drive');
        $url = route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]);

        $this->actingAs(User::find($userId))
            ->postJson($url, ['entity' => true, 'entity_name' => 'Beaumont Prop Cc 1502', 'entity_reg_no' => '2010/017928/23'])
            ->assertOk()->assertJsonPath('sellers.0.is_entity', true);

        $entity = Contact::withoutGlobalScopes()->where('entity_reg_no', '2010/017928/23')->first();
        $this->assertNotNull($entity);
        $this->assertSame(Contact::TYPE_ENTITY, $entity->contact_kind);
        $this->assertTrue($entity->id_number === null || $entity->id_number === '', 'entity keys on reg number, never a fake SA ID');

        // Re-linking the same reg number resolves onto the SAME entity contact — no clone.
        $this->actingAs(User::find($userId))
            ->postJson($url, ['entity' => true, 'entity_name' => 'Beaumont Prop Cc 1502', 'entity_reg_no' => '2010/017928/23'])->assertOk();
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('entity_reg_no', '2010/017928/23')->count());
    }

    /** Sellers list shows the company (entity) + its director together; the entity never blocks continue. */
    public function test_sellers_list_shows_entity_with_director_and_entity_never_blocks_continue(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedListing($agencyId, '1502 Beaumont Drive');
        $link = route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingId]);

        // Company entity links...
        $entity = $this->makeEntity($agencyId, 'Beaumont Prop Cc 1502', '2010/017928/23');
        $this->actingAs(User::find($userId))->postJson($link, ['contact_id' => $entity->id])->assertOk();

        // ...and its director (natural person) links, with cc6's representative link + a number.
        $this->actingAs(User::find($userId))->postJson($link, ['first_name' => 'HA', 'last_name' => 'Pretorius', 'id_number' => '7004065141082'])->assertOk();
        $director = Contact::withoutGlobalScopes()->where('id_number', '7004065141082')->first();
        DB::table('contact_representatives')->insert([
            'entity_contact_id' => $entity->id, 'representative_contact_id' => $director->id,
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $director->phones()->create(['agency_id' => $agencyId, 'phone' => '0820000001', 'label' => 'TVA']);

        // Payload: BOTH are sellers; the entity carries its director as a representative.
        $listing = DB::table('prospecting_listings')->where('id', $listingId)->first();
        $payload = app(\App\Services\Prospecting\ComposeSellerService::class)->payload($agencyId, $listing);
        $isEntityFlags = collect($payload['sellers'])->pluck('is_entity')->all();
        $this->assertContains(true, $isEntityFlags, 'the company entity is a seller');
        $this->assertContains(false, $isEntityFlags, 'the director is a seller');
        $entityRow = collect($payload['sellers'])->firstWhere('is_entity', true);
        $this->assertSame('Beaumont Prop Cc 1502', $entityRow['display_name']);
        $this->assertSame([['contact_id' => $director->id, 'name' => 'HA Pretorius']], $entityRow['representatives']);
        $this->assertTrue($entityRow['contactable'], 'an entity with a representative director counts as contactable');

        // Continue passes: the director has a number; the entity is exempt (reached through the director).
        $this->actingAs(User::find($userId))
            ->post(route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $listingId]), [])
            ->assertRedirect(route('seller-outreach.entry.pitch-ready-prospecting', ['prospectingListingId' => $listingId]));
    }

    /** An entity Contact for the tests (contact_kind=entity, reg-no, no SA ID) — cc6's capture shape. */
    private function makeEntity(int $agencyId, string $name, string $regNo): Contact
    {
        return Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => $name, 'entity_reg_no' => $regNo,
            'first_name' => $name, 'last_name' => '', 'phone' => '',
        ]);
    }

    /** Seed a deeds tracked_property with owners; returns its id. */
    private function seedDeed(int $agencyId, array $owners): int
    {
        $tpId = (int) DB::table('tracked_properties')->insertGetId([
            'agency_id' => $agencyId, 'capture_kind' => 'deeds_capture', 'erf_number' => '659',
            'street_number' => '20', 'street_name' => 'Lilliecrona Drive', 'suburb' => 'Manaba Beach',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($owners as $i => [$name, $idn]) {
            DB::table('tracked_property_owners')->insert([
                'tracked_property_id' => $tpId, 'name' => $name, 'id_number' => $idn, 'id_type' => 'sa_id',
                'is_primary' => $i === 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $tpId;
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
