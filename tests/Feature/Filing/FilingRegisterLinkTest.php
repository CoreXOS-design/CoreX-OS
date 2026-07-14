<?php

declare(strict_types=1);

namespace Tests\Feature\Filing;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\DocumentFiling;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Filing\FilingPropertyLinker;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-238 — NEW filing entries point at the real records instead of retyping them.
 *
 * Three things this must get right, and they pull against each other:
 *   1. Linking is an UPGRADE, never a gate. A new entry whose property CoreX does not hold
 *      must still save, edit and list, exactly as before.
 *   2. Linking must never FALSIFY what was filed. The expiry is per-row: suggested from the
 *      property's mandate on link, and never silently rewritten. An OA and an EA on one
 *      property can genuinely expire on different dates (68 addresses on qa1 carry more than
 *      one mandate document).
 *   3. The 2,069 HISTORICAL rows are not touched. They were deliberately never backfilled —
 *      a lone address match is not a correct one, and a confidently wrong link on a legal
 *      filing record is worse than the free text it replaced. They stay free text, viewable
 *      and editable exactly as they always were.
 *
 * Plus the security hole this build closes: update()/destroy() were unscoped and
 * unpermissioned while store() was correct all along.
 */
final class FilingRegisterLinkTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private Branch $branchA;
    private Branch $branchB;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // The 403/404 error pages render through the app layout, which calls @vite —
        // without this a legitimate abort() surfaces as a 500 and hides what we're testing.
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchA = Branch::create(['agency_id' => $this->agencyId, 'name' => 'Margate']);
        $this->branchB = Branch::create(['agency_id' => $this->agencyId, 'name' => 'Southbroom']);

        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'admin',
        ]);
    }

    // ── the linkage ──────────────────────────────────────────────────────

    public function test_a_filing_can_be_captured_linked_to_a_real_property_and_seller(): void
    {
        $property = $this->property('14 Marine Drive', 'Shelly Beach', '2026-12-31');
        $seller   = $this->contact('Thandi', 'Mkhize');
        $property->contacts()->attach($seller->id, ['role' => 'seller']);

        $this->actingAs($this->admin)->post(route('filing-register.store'), $this->payload([
            'property_id'       => $property->id,
            'property_address'  => '14 Marine Drive, Shelly Beach',
            'seller_contact_id' => $seller->id,
            'seller_name'       => 'Thandi Mkhize',
            'expiry_date'       => '2026-12-31',
        ]))->assertRedirect();

        $filing = DocumentFiling::latest('id')->firstOrFail();
        $this->assertSame($property->id, $filing->property_id);
        $this->assertSame($seller->id, $filing->seller_contact_id);

        // The linked record wins for display — the register stops disagreeing with the property page.
        $this->assertStringContainsString('Marine Drive', $filing->property_display);
        $this->assertSame('Thandi Mkhize', $filing->seller_display);
        $this->assertTrue($filing->is_linked);
    }

    /** THE fallback path: ~42% of real rows. A property CoreX never heard of must still file. */
    public function test_a_filing_with_no_matching_property_still_saves_as_free_text(): void
    {
        $this->actingAs($this->admin)->post(route('filing-register.store'), $this->payload([
            'property_address' => '3 Forset Walk, Manaba Beach', // 2020 file, typo and all
            // no property_id, no seller_contact_id
        ]))->assertSessionHasNoErrors();

        $filing = DocumentFiling::latest('id')->firstOrFail();
        $this->assertNull($filing->property_id);
        $this->assertSame('3 Forset Walk, Manaba Beach', $filing->property_display);
        $this->assertFalse($filing->is_linked);
    }

    /** The register records what was FILED. Linking must never rewrite an expiry a human set. */
    public function test_linking_a_property_does_not_rewrite_an_expiry_that_was_already_filed(): void
    {
        $property = $this->property('9 Dee Road', 'Uvongo', '2027-01-31'); // property says Jan
        $filing = $this->filing(['expiry_date' => '2026-06-30', 'property_address' => '9 Dee Road']);

        // The clerk links the property but keeps the date the document actually carries.
        $this->actingAs($this->admin)->put(route('filing-register.update', $filing->id), $this->payload([
            'property_id'      => $property->id,
            'property_address' => '9 Dee Road, Uvongo',
            'expiry_date'      => '2026-06-30',   // unchanged — this is what the paper says
        ]))->assertRedirect();

        $filing->refresh();
        $this->assertSame($property->id, $filing->property_id);
        $this->assertSame('2026-06-30', $filing->expiry_date->format('Y-m-d'),
            'the filed expiry survives the link — the property does not overwrite the document');
    }

    /** An OA and an EA on the SAME property may carry different expiries. Both must persist. */
    public function test_two_mandate_documents_on_one_property_keep_their_own_expiries(): void
    {
        $property = $this->property('673 Dennis Road', 'Margate', '2027-03-01');

        $oa = $this->filing(['document_type' => 'OA', 'property_id' => $property->id, 'expiry_date' => '2026-09-30']);
        $ea = $this->filing(['document_type' => 'EA', 'property_id' => $property->id, 'expiry_date' => '2027-03-01']);

        $this->assertSame('2026-09-30', $oa->fresh()->expiry_date->format('Y-m-d'));
        $this->assertSame('2027-03-01', $ea->fresh()->expiry_date->format('Y-m-d'));
        $this->assertNotSame(
            $oa->fresh()->expiry_date->format('Y-m-d'),
            $ea->fresh()->expiry_date->format('Y-m-d'),
            'the register holds separate documents with separate lifespans, not one mirrored date'
        );
    }

    public function test_unlinking_keeps_the_address_and_expiry_that_were_filed(): void
    {
        $property = $this->property('21 Dee Road', 'Uvongo', '2027-05-05');
        $filing = $this->filing(['property_id' => $property->id, 'property_address' => '21 Dee Road, Uvongo', 'expiry_date' => '2026-08-01']);

        $this->actingAs($this->admin)->put(route('filing-register.update', $filing->id), $this->payload([
            'property_address' => '21 Dee Road, Uvongo',
            'expiry_date'      => '2026-08-01',
            // property_id omitted → unlink
        ]))->assertRedirect();

        $filing->refresh();
        $this->assertNull($filing->property_id);
        $this->assertSame('21 Dee Road, Uvongo', $filing->property_address, 'unlinking is not forgetting');
        $this->assertSame('2026-08-01', $filing->expiry_date->format('Y-m-d'));
    }

    /**
     * The scope-cut guarantee: a historical free-text row is NOT migrated, NOT linked, and
     * NOT degraded. It lists, it edits, it saves — exactly as it did before this build. The
     * absence of a link is a permanent, first-class state, not a to-do.
     */
    public function test_a_historical_free_text_row_is_untouched_and_still_fully_editable(): void
    {
        // A property that WOULD match by address, to prove nothing links it behind our back.
        $this->property('56 Colin Street', 'Uvongo', '2027-01-01');

        $legacy = $this->filing([
            'property_address' => '56 Colin Street, Uvongo',
            'seller_name'      => null,
            'expiry_date'      => '2020-03-12',
        ]);

        $this->assertNull($legacy->property_id, 'nothing links a historical row on its own');

        // ...and it still edits, as free text, with no property picked.
        $this->actingAs($this->admin)->put(route('filing-register.update', $legacy->id), $this->payload([
            'property_address' => '56 Colin Street, Uvongo',
            'expiry_date'      => '2021-03-12',
            'notes'            => 'renewed',
        ]))->assertRedirect();

        $legacy->refresh();
        $this->assertNull($legacy->property_id, 'editing an old row does not force a link on it');
        $this->assertSame('56 Colin Street, Uvongo', $legacy->property_display);
        $this->assertSame('2021-03-12', $legacy->expiry_date->format('Y-m-d'));
        $this->assertSame('renewed', $legacy->notes);
    }

    // ── Johan's flow: the property leads, everything derivable derives ───

    /**
     * The clerk supplies a property and a file number. Branch, agent, seller and expiry all
     * come from the property's own listing context — they are never things they must know.
     */
    public function test_picking_a_property_derives_branch_agent_seller_and_expiry(): void
    {
        $listingAgent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchB->id, 'role' => 'agent',
        ]);
        $property = Property::create([
            'agency_id' => $this->agencyId, 'agent_id' => $listingAgent->id,
            'branch_id' => $this->branchB->id,           // the property is listed at Southbroom
            'title' => '14 Marine Drive, Shelly Beach', 'address' => '14 Marine Drive',
            'suburb' => 'Shelly Beach', 'status' => 'active', 'property_type' => 'House',
            'price' => 2_150_000, 'expiry_date' => '2026-12-31',
        ]);
        $seller = $this->contact('Thandi', 'Mkhize');
        $property->contacts()->attach($seller->id, ['role' => 'seller']);

        // The API the picker calls must hand back everything the form needs in one go.
        $this->actingAs($this->admin)
            ->getJson(route('filing-register.search.property-suggestions', ['property' => $property->id]))
            ->assertOk()
            ->assertJsonPath('suggestions.branch_id', $this->branchB->id)
            ->assertJsonPath('suggestions.agent_id', $listingAgent->id)
            ->assertJsonPath('suggestions.expiry_date', '2026-12-31')
            ->assertJsonPath('sellers.0.id', $seller->id);

        // ...and a save that carries ONLY the property + the file number still lands complete.
        $this->actingAs($this->admin)->post(route('filing-register.store'), [
            'document_type'    => 'EA',
            'file_reference'   => 'File 3',
            'sequence_number'  => '0042',
            'property_id'      => $property->id,
            'property_address' => '14 Marine Drive, Shelly Beach',
            // NO branch_id. NO agent_id. The clerk never touched them.
        ])->assertSessionHasNoErrors();

        $filing = DocumentFiling::latest('id')->firstOrFail();
        $this->assertSame($this->branchB->id, $filing->branch_id, 'branch came from the property');
        $this->assertSame($listingAgent->id, $filing->agent_id, 'agent came from the property');
    }

    /** An explicit choice always beats the derivation — the derived fields stay overridable. */
    public function test_an_explicit_branch_or_agent_overrides_the_property(): void
    {
        $listingAgent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchB->id, 'role' => 'agent',
        ]);
        $property = Property::create([
            'agency_id' => $this->agencyId, 'agent_id' => $listingAgent->id,
            'branch_id' => $this->branchB->id,
            'title' => '9 Dee Road, Uvongo', 'address' => '9 Dee Road', 'suburb' => 'Uvongo',
            'status' => 'active', 'property_type' => 'House', 'price' => 1_000_000,
        ]);

        $this->actingAs($this->admin)->post(route('filing-register.store'), $this->payload([
            'property_id'      => $property->id,
            'property_address' => '9 Dee Road, Uvongo',
            'branch_id'        => $this->branchA->id,   // deliberately NOT the property's branch
            'agent_id'         => $this->admin->id,
        ]))->assertSessionHasNoErrors();

        $filing = DocumentFiling::latest('id')->firstOrFail();
        $this->assertSame($this->branchA->id, $filing->branch_id, 'the human overrode the derivation');
        $this->assertSame($this->admin->id, $filing->agent_id);
    }

    /** No property (the free-text path): branch/agent fall back to the clerk, not to a 500. */
    public function test_a_free_text_filing_falls_back_to_the_clerks_own_branch_and_agent(): void
    {
        $this->actingAs($this->admin)->post(route('filing-register.store'), [
            'document_type'    => 'Other',
            'file_reference'   => 'File 9',
            'sequence_number'  => '0101',
            'property_address' => 'Krizaan 14, Margate',  // no property record exists
        ])->assertSessionHasNoErrors();

        $filing = DocumentFiling::latest('id')->firstOrFail();
        $this->assertNull($filing->property_id);
        $this->assertSame($this->branchA->id, $filing->branch_id, "the clerk's own branch");
        $this->assertSame($this->admin->id, $filing->agent_id);
    }

    // ── ONE SELLER FACT (Johan, qa1: the auto-fill was double-entering) ──

    /**
     * Linking a seller must NOT also store their typed name. Two copies of the same fact is a
     * duplicate waiting to drift the moment the contact is renamed — and it gave the clerk a
     * second box to type a seller into.
     */
    public function test_linking_a_seller_stores_the_contact_and_NOT_a_copy_of_their_name(): void
    {
        $property = $this->property('14 Marine Drive', 'Shelly Beach', null);
        $seller   = $this->contact('Marius', 'van Rensburg');
        $property->contacts()->attach($seller->id, ['role' => 'seller']);

        // The browser is not the guarantee: post BOTH, as the buggy form did.
        $this->actingAs($this->admin)->post(route('filing-register.store'), $this->payload([
            'property_id'       => $property->id,
            'property_address'  => '14 Marine Drive, Shelly Beach',
            'seller_contact_id' => $seller->id,
            'seller_name'       => 'Marius van Rensburg',   // the mirrored copy
        ]))->assertSessionHasNoErrors();

        $filing = DocumentFiling::latest('id')->firstOrFail();

        $this->assertSame($seller->id, $filing->seller_contact_id, 'the contact IS the seller fact');
        $this->assertNull($filing->seller_name, 'the free-text copy must NOT be stored alongside the link');

        // ...and the row still reads correctly, through the link.
        $this->assertSame('Marius van Rensburg', $filing->seller_display);
    }

    /** The typed name survives when there is NO linked contact — it is the fallback, not a mirror. */
    public function test_a_typed_seller_name_is_kept_when_no_contact_is_linked(): void
    {
        $this->actingAs($this->admin)->post(route('filing-register.store'), $this->payload([
            'property_address' => 'Krizaan 14, Margate',
            'seller_name'      => 'Estate late J. Ellis',   // a seller CoreX does not hold
        ]))->assertSessionHasNoErrors();

        $filing = DocumentFiling::latest('id')->firstOrFail();
        $this->assertNull($filing->seller_contact_id);
        $this->assertSame('Estate late J. Ellis', $filing->seller_name);
        $this->assertSame('Estate late J. Ellis', $filing->seller_display);
    }

    /** Switching an existing row from a typed name to a linked contact drops the stale copy. */
    public function test_linking_a_seller_on_an_existing_row_clears_the_stale_typed_name(): void
    {
        $property = $this->property('9 Dee Road', 'Uvongo', null);
        $seller   = $this->contact('Hannelie', 'Botha');
        $property->contacts()->attach($seller->id, ['role' => 'seller']);

        $filing = $this->filing(['seller_name' => 'H. Botha (typed)', 'property_address' => '9 Dee Road']);

        $this->actingAs($this->admin)->put(route('filing-register.update', $filing->id), $this->payload([
            'property_id'       => $property->id,
            'property_address'  => '9 Dee Road, Uvongo',
            'seller_contact_id' => $seller->id,
            'seller_name'       => 'H. Botha (typed)',   // the stale copy the form used to resend
        ]))->assertRedirect();

        $filing->refresh();
        $this->assertSame($seller->id, $filing->seller_contact_id);
        $this->assertNull($filing->seller_name, 'the old typed name does not linger beside the link');
        $this->assertSame('Hannelie Botha', $filing->seller_display);
    }

    // ── the property record shows WHERE THE PAPER LIVES ──────────────────

    /**
     * An agent on the property record can see which physical file to pull. It is a READ-THROUGH:
     * the property stores no copy of the reference, so a re-numbered file cannot leave a stale
     * one behind. Several filings are normal (an OA and an EA are separate documents), so all
     * of them show.
     */
    public function test_the_property_record_shows_the_filing_references_of_its_register_entries(): void
    {
        $property = $this->property('1 Alamien Avenue', 'Uvongo', null);

        $this->filing([
            'property_id' => $property->id, 'document_type' => 'EA',
            'file_reference' => 'File 3', 'sequence_number' => '0042',
        ]);
        $this->filing([
            'property_id' => $property->id, 'document_type' => 'OA',
            'file_reference' => 'File 7', 'sequence_number' => '0119',
        ]);

        $refs = $property->fresh()->filings->map->full_reference->all();
        $this->assertCount(2, $refs, 'both documents are shown — an OA and an EA are separate files');
        $this->assertContains('File 3 / 0042', $refs);
        $this->assertContains('File 7 / 0119', $refs);

        // The reference is read live from the register — nothing is copied onto the property.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('properties', 'file_reference'),
            'the property must NOT carry its own copy of the file reference'
        );

        // ...and it renders on the property page.
        $this->actingAs($this->admin)
            ->get(route('corex.properties.show', $property))
            ->assertOk()
            ->assertSee('File 3 / 0042')
            ->assertSee('File 7 / 0119');
    }

    /** A property with no filing shows nothing — absence is the answer, an empty chip is clutter. */
    public function test_a_property_with_no_filing_entry_shows_no_reference(): void
    {
        $property = $this->property('99 Empty Road', 'Margate', null);

        $this->assertCount(0, $property->filings);

        $this->actingAs($this->admin)
            ->get(route('corex.properties.show', $property))
            ->assertOk()
            ->assertDontSee('Physically filed as');
    }

    // ── the pickers ──────────────────────────────────────────────────────

    public function test_the_property_search_endpoint_serves_filing_users_and_suggests_the_mandate_expiry(): void
    {
        $property = $this->property('14 Marine Drive', 'Shelly Beach', '2026-12-31');
        $seller = $this->contact('Thandi', 'Mkhize');
        $property->contacts()->attach($seller->id, ['role' => 'seller']);

        $this->actingAs($this->admin)
            ->getJson(route('filing-register.search.properties', ['q' => 'marine drive']))
            ->assertOk()
            ->assertJsonPath('results.0.id', $property->id)
            ->assertJsonPath('results.0.expiry_date', '2026-12-31');

        $this->actingAs($this->admin)
            ->getJson(route('filing-register.search.property-suggestions', ['property' => $property->id]))
            ->assertOk()
            ->assertJsonPath('suggestions.expiry_date', '2026-12-31')
            ->assertJsonPath('sellers.0.id', $seller->id)
            ->assertJsonPath('sellers.0.name', 'Thandi Mkhize');
    }

    /**
     * THE BUG JOHAN HIT, and the test that would have caught it.
     *
     * An owner/super-admin carries `agency_id = NULL`. The picker used to filter properties on
     * that raw value, which cast to the sentinel 0 — so it searched a tenant that does not
     * exist, returned nothing, and the dropdown silently never opened. The search was not
     * "broken"; it was correctly answering the wrong question. My own tests all ran as an
     * agency-bound admin, so none of them saw it. (STANDARDS Rule 17 — the AT-253 class.)
     */
    public function test_a_super_admin_with_no_agency_can_still_search_properties(): void
    {
        $this->property('14 Marine Drive', 'Shelly Beach', '2026-12-31');

        $superAdmin = User::factory()->create([
            'agency_id' => null,           // exactly Johan's real account
            'branch_id' => null,
            'role'      => 'super_admin',
        ]);
        $this->assertNull($superAdmin->effectiveAgencyId(), 'precondition: no agency context');

        $this->actingAs($superAdmin)
            ->getJson(route('filing-register.search.properties', ['q' => 'marine drive']))
            ->assertOk()
            ->assertJsonCount(1, 'results');

        // ...and the suggestions endpoint must not 404 them out of a property they can see.
        $property = Property::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($superAdmin)
            ->getJson(route('filing-register.search.property-suggestions', ['property' => $property->id]))
            ->assertOk();
    }

    /** The seller comes from the property's link roles — a BUYER must never be offered as one. */
    public function test_only_seller_side_contacts_are_offered_as_the_seller(): void
    {
        $property = $this->property('5 Aloha Park', 'Margate', null);
        $seller = $this->contact('Sue', 'Seller');
        $buyer  = $this->contact('Bob', 'Buyer');
        $property->contacts()->attach($seller->id, ['role' => 'seller']);
        $property->contacts()->attach($buyer->id, ['role' => 'buyer']);

        $sellers = app(FilingPropertyLinker::class)->sellerCandidates($property)->pluck('id')->all();

        $this->assertContains($seller->id, $sellers);
        $this->assertNotContains($buyer->id, $sellers, 'a buyer is not a seller');
    }

    // ── the security hole this build closes ──────────────────────────────

    public function test_a_branch_scoped_user_cannot_edit_another_branchs_filing(): void
    {
        $filingInB = $this->filing(['branch_id' => $this->branchB->id]);

        $bm = $this->branchUser($this->branchA->id, ['access_filing_register', 'filing.view', 'filing.edit']);

        $this->actingAs($bm)
            ->put(route('filing-register.update', $filingInB->id), $this->payload([
                'branch_id'        => $this->branchA->id,
                'property_address' => 'HIJACKED',
            ]))
            ->assertNotFound(); // scoped out of existence, exactly like reading it

        $this->assertNotSame('HIJACKED', $filingInB->fresh()->property_address);
        $this->assertSame($this->branchB->id, $filingInB->fresh()->branch_id, 'and it was not moved between branches');
    }

    public function test_a_branch_scoped_user_cannot_archive_another_branchs_filing(): void
    {
        $filingInB = $this->filing(['branch_id' => $this->branchB->id]);
        $bm = $this->branchUser($this->branchA->id, ['access_filing_register', 'filing.view', 'filing.archive']);

        $this->actingAs($bm)
            ->delete(route('filing-register.destroy', $filingInB->id))
            ->assertNotFound();

        $this->assertNull($filingInB->fresh()->deleted_at, 'the row survives');
    }

    public function test_the_action_permissions_are_actually_enforced(): void
    {
        $filing = $this->filing(['branch_id' => $this->branchA->id]);

        // Reach the register, but hold no create/edit/archive rights.
        $viewer = $this->branchUser($this->branchA->id, ['access_filing_register', 'filing.view']);

        $this->actingAs($viewer)->post(route('filing-register.store'), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->put(route('filing-register.update', $filing->id), $this->payload())->assertForbidden();
        $this->actingAs($viewer)->delete(route('filing-register.destroy', $filing->id))->assertForbidden();

        $this->assertNull($filing->fresh()->deleted_at);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id'        => $this->branchA->id,
            'agent_id'         => $this->admin->id,
            'document_type'    => 'EA',
            'file_reference'   => 'File 3',
            'sequence_number'  => '0042',
            'property_address' => '21 Dee Road, Uvongo',
            'notes'            => '',
        ], $overrides);
    }

    private function filing(array $attrs = []): DocumentFiling
    {
        return DocumentFiling::create(array_merge([
            'agency_id'        => $this->agencyId,
            'branch_id'        => $this->branchA->id,
            'agent_id'         => $this->admin->id,
            'document_type'    => 'EA',
            'file_reference'   => 'File ' . Str::random(3),
            'sequence_number'  => (string) random_int(1000, 9999),
            'property_address' => '21 Dee Road, Uvongo',
            'captured_by'      => $this->admin->id,
        ], $attrs));
    }

    private function property(string $address, string $suburb, ?string $expiry): Property
    {
        return Property::create([
            'agency_id'     => $this->agencyId,
            'agent_id'      => $this->admin->id,
            'branch_id'     => $this->branchA->id,
            'title'         => $address . ', ' . $suburb,
            'address'       => $address,
            'suburb'        => $suburb,
            'status'        => 'active',
            'property_type' => 'House',
            'price'         => 1_950_000,
            'expiry_date'   => $expiry,
        ]);
    }

    private function contact(string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => $first, 'last_name' => $last,
        ]);
    }

    /**
     * A branch-scoped user holding EXACTLY the named permissions — the shape that exposes the
     * hole: they can legitimately reach the register, and used to be able to edit every other
     * branch's rows through it.
     */
    private function branchUser(int $branchId, array $permissions): User
    {
        $role = 'branch_manager_' . Str::random(5); // a role of its own, so grants don't bleed between tests

        Role::create(['name' => $role, 'label' => 'BM', 'agency_id' => $this->agencyId]);
        foreach ($permissions as $key) {
            RolePermission::create([
                'role'           => $role,
                'permission_key' => $key,
                // branch-level sight of the filing module — the scope the hole ignored
                'scope'          => $key === 'filing.view' ? 'branch' : null,
                'agency_id'      => $this->agencyId,
            ]);
        }
        Role::clearCache();
        PermissionService::clearCache();

        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $branchId, 'role' => $role, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        parent::tearDown();
    }
}
