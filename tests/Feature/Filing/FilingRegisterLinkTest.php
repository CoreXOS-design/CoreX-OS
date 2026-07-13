<?php

declare(strict_types=1);

namespace Tests\Feature\Filing;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\DocumentFiling;
use App\Models\FilingLinkReview;
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
 * AT-238 — the filing register points at the real records instead of retyping them.
 *
 * The two things this must get right, and they pull in opposite directions:
 *   1. Linking must be an UPGRADE, never a gate — ~42% of the historical register names a
 *      property CoreX has never held. Those filings must still save, edit and list.
 *   2. Linking must never FALSIFY what was filed — expiry is per-row, suggested on link,
 *      and never silently rewritten. An OA and an EA on one property can expire on
 *      different dates (68 addresses on qa1 carry more than one mandate doc).
 *
 * Plus the security hole this build closes: update()/destroy() were unscoped and
 * unpermissioned while store() was correct.
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
        $this->assertSame('manual', $filing->link_source, 'a human pointed at it');
        $this->assertSame('exact', $filing->link_confidence);

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
        $this->assertNull($filing->link_source, 'an unlinked row says so, rather than claiming a link');
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
        $this->assertNull($filing->link_source);
        $this->assertSame('21 Dee Road, Uvongo', $filing->property_address, 'unlinking is not forgetting');
        $this->assertSame('2026-08-01', $filing->expiry_date->format('Y-m-d'));
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

    // ── the backfill ─────────────────────────────────────────────────────

    public function test_the_backfill_reports_without_writing_by_default(): void
    {
        $property = $this->property('60 Orange Rocks', 'St Michaels', '2027-02-02');
        $filing = $this->filing(['property_address' => '60 Orange Rocks, St Michaels']);

        $this->artisan('filing:link-properties', ['--agency' => $this->agencyId])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);

        $this->assertNull($filing->fresh()->property_id, 'report-only must write NOTHING');
        $this->assertSame(0, FilingLinkReview::withoutGlobalScopes()->count());
    }

    public function test_the_backfill_links_only_unambiguous_matches_and_queues_the_rest(): void
    {
        // One clean match.
        $clean = $this->property('60 Orange Rocks', 'St Michaels', '2027-02-02');
        $cleanFiling = $this->filing(['property_address' => '60 Orange Rocks St Michaels']);

        // Two properties that both answer to the same words → ambiguous, must NOT be guessed.
        $this->property('12 San Miguel', 'Margate', null);
        $this->property('12 San Miguel', 'Uvongo', null);
        $ambiguousFiling = $this->filing(['property_address' => '12 San Miguel']);

        // Nothing like it exists → stays free text.
        $unmatchedFiling = $this->filing(['property_address' => 'Krizaan 14, Nowhere']);

        $this->artisan('filing:link-properties', ['--agency' => $this->agencyId, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame($clean->id, $cleanFiling->fresh()->property_id);
        $this->assertSame('auto_address_match', $cleanFiling->fresh()->link_source);

        $this->assertNull($ambiguousFiling->fresh()->property_id, 'never guess between candidates');
        $review = FilingLinkReview::withoutGlobalScopes()->where('filing_id', $ambiguousFiling->id)->first();
        $this->assertNotNull($review, 'the ambiguous row goes to a human');
        $this->assertSame('pending', $review->match_status);
        $this->assertCount(2, $review->candidates_json);

        $this->assertNull($unmatchedFiling->fresh()->property_id, 'no match is an honest answer');
        $this->assertNull(FilingLinkReview::withoutGlobalScopes()->where('filing_id', $unmatchedFiling->id)->first());
    }

    public function test_the_backfill_never_overwrites_a_link_a_human_made(): void
    {
        $human  = $this->property('60 Orange Rocks', 'St Michaels', null);
        $other  = $this->property('60 Orange Rocks', 'Margate', null);
        $filing = $this->filing([
            'property_address' => '60 Orange Rocks',
            'property_id'      => $human->id,
            'link_source'      => 'manual',
        ]);

        $this->artisan('filing:link-properties', ['--agency' => $this->agencyId, '--apply' => true])->assertExitCode(0);

        $this->assertSame($human->id, $filing->fresh()->property_id);
        $this->assertSame('manual', $filing->fresh()->link_source, 'a human decision is not re-litigated by a script');
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
