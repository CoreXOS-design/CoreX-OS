<?php

namespace Tests\Feature\DemoAccess;

use App\Models\DemoAccessGrant;
use App\Models\DemoTncVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\Demo\DemoAccessService;
use Database\Seeders\DemoTncVersionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The admin surface: owner-only, validation, and archive-not-delete.
 *
 * Spec: .ai/specs/demo-access-control.md §8, §9
 * Input space (§11): R1, R2, R3, R14, R15
 *
 * The access rule under test: this is gated by owner_only with NO permission key.
 * A permission key is GRANTABLE — one mis-click in the Role Manager and an agency
 * admin is reading the list of competitors evaluating CoreX. owner_only has no
 * delegation path.
 */
class DemoAccessAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $agencyAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->seed(DemoTncVersionSeeder::class);

        // owner_only checks the ROLE ROW's is_owner flag — not the user's role
        // string. is_owner is not fillable, so it is set explicitly and the role
        // cache cleared. Same pattern as DemoSidebarCurationTest (the sibling
        // owner_only Dev Settings surface).
        $ownerRole = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();

        $this->owner = User::factory()->create([
            'role'      => 'super_admin',
            'name'      => 'Johan Reichel',
            'agency_id' => null,          // a platform identity, not a tenant member
        ]);

        // A NON-owner with the most privileged agency role available. If the gate
        // leaks, it leaks to someone like this.
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Agency Admin', 'sort_order' => 2]);
        Role::clearCache();

        $this->agencyAdmin = User::factory()->create(['role' => 'admin', 'name' => 'Agency Admin']);
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function assertIsOwner(User $user): void
    {
        $this->assertTrue($user->isOwnerRole(), 'Test setup: this user must be an owner.');
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_an_owner_can_reach_the_grant_list(): void
    {
        $this->assertIsOwner($this->owner);

        $this->actingAs($this->owner)
            ->get(route('admin.demo-access.index'))
            ->assertOk()
            ->assertSee('Demo Access');
    }

    /** A non-owner is refused — this is the whole point of the owner_only gate. */
    public function test_an_agency_admin_cannot_reach_demo_access(): void
    {
        $this->assertFalse($this->agencyAdmin->isOwnerRole());

        $this->actingAs($this->agencyAdmin)
            ->get(route('admin.demo-access.index'))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_reach_demo_access(): void
    {
        $this->get(route('admin.demo-access.index'))->assertRedirect();
    }

    /** Every write path is gated too, not just the read. */
    public function test_a_non_owner_cannot_issue_revoke_or_archive(): void
    {
        [$grant] = app(DemoAccessService::class)->issue([
            'company_name'  => 'Seaside Realty (Pty) Ltd',
            'contact_email' => 'thabo@seasiderealty.co.za',
        ], $this->owner->id);

        $this->actingAs($this->agencyAdmin)
            ->post(route('admin.demo-access.index'), [
                'company_name' => 'Sneaky Co', 'contact_email' => 'x@y.co.za',
            ])->assertForbidden();

        $this->actingAs($this->agencyAdmin)
            ->post(route('admin.demo-access.revoke', $grant))
            ->assertForbidden();

        $this->actingAs($this->agencyAdmin)
            ->delete(route('admin.demo-access.show', $grant))
            ->assertForbidden();

        $this->actingAs($this->agencyAdmin)
            ->post(route('admin.demo-access.tnc'), ['body' => str_repeat('x', 50)])
            ->assertForbidden();
    }

    // ── Issue (validation = the input space) ─────────────────────────────────

    /** R1 — the lazy-but-valid shortcut works through the HTTP layer too. */
    public function test_issuing_with_company_and_email_only_succeeds(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.demo-access.index'), [
                'company_name'  => 'Umhlanga Property Group',
                'contact_email' => 'nadia@upg.co.za',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('demo_access_grants', [
            'company_name'  => 'Umhlanga Property Group',
            'contact_email' => 'nadia@upg.co.za',
        ]);
    }

    /** The plaintext is shown exactly once, on the redirect. */
    public function test_the_access_code_is_flashed_once_after_issuing(): void
    {
        $response = $this->actingAs($this->owner)
            ->post(route('admin.demo-access.index'), [
                'company_name'  => 'Ballito Homes',
                'contact_email' => 'sipho@ballitohomes.co.za',
            ]);

        $response->assertSessionHas('demo_access_code');

        $code  = session('demo_access_code');
        $grant = DemoAccessGrant::where('contact_email', 'sipho@ballitohomes.co.za')->first();

        $this->assertTrue($grant->verifyCode($code));

        // The show page renders it, with the warning that it will not return.
        $this->actingAs($this->owner)
            ->withSession(['demo_access_code' => $code])
            ->get(route('admin.demo-access.show', $grant))
            ->assertOk()
            ->assertSee($code)
            ->assertSee('will not be shown again', false);
    }

    /** R2 — required-but-empty is rejected with a message a human understands. */
    public function test_a_missing_company_name_is_rejected_clearly(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.demo-access.index'), [
                'company_name'  => '',
                'contact_email' => 'someone@example.co.za',
            ])
            ->assertSessionHasErrors(['company_name' => 'Enter the company name.']);

        $this->assertSame(0, DemoAccessGrant::count());
    }

    /** R3 — malformed-but-submitted is rejected, not crashed on. */
    public function test_a_malformed_email_is_rejected_clearly(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.demo-access.index'), [
                'company_name'  => 'Margate Letting Co',
                'contact_email' => 'not-an-email',
            ])
            ->assertSessionHasErrors(['contact_email' => 'Enter a valid email address.']);

        $this->assertSame(0, DemoAccessGrant::count());
    }

    public function test_a_nonsense_expiry_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.demo-access.index'), [
                'company_name'  => 'Port Shepstone Estates',
                'contact_email' => 'ayanda@psestates.co.za',
                'expiry_hours'  => 0,
            ])
            ->assertSessionHasErrors('expiry_hours');
    }

    // ── Archive is not delete ────────────────────────────────────────────────

    /** R15 — "Delete" archives. COUNT(*) never decreases. */
    public function test_the_delete_button_archives_and_the_row_survives(): void
    {
        [$grant] = app(DemoAccessService::class)->issue([
            'company_name'  => 'Shelly Beach Realty',
            'contact_email' => 'mandla@shellybeach.co.za',
        ], $this->owner->id);

        $before = DemoAccessGrant::count();

        $this->actingAs($this->owner)
            ->delete(route('admin.demo-access.show', $grant))
            ->assertRedirect(route('admin.demo-access.index'));

        $this->assertSame($before, DemoAccessGrant::count());
        $this->assertDatabaseHas('demo_access_grants', ['id' => $grant->id]);
        $this->assertNotNull($grant->fresh()->archived_at);

        // Hidden from the default list...
        $this->actingAs($this->owner)
            ->get(route('admin.demo-access.index'))
            ->assertDontSee('Shelly Beach Realty');

        // ...but visible when you ask for archived.
        $this->actingAs($this->owner)
            ->get(route('admin.demo-access.index') . '?archived=1')
            ->assertSee('Shelly Beach Realty');
    }

    /** Revoking tells the truth about the ≤60s latency. */
    public function test_the_revoke_response_states_the_real_latency(): void
    {
        [$grant] = app(DemoAccessService::class)->issue([
            'company_name'  => 'Uvongo Letting',
            'contact_email' => 'fatima@uvongoletting.co.za',
        ], $this->owner->id);

        $this->actingAs($this->owner)
            ->post(route('admin.demo-access.revoke', $grant))
            ->assertRedirect();

        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertStringContainsString('60 seconds', session('status'));
    }

    // ── Deleted related records ──────────────────────────────────────────────

    /**
     * R14 — a grant whose linked Contact was deleted must still render.
     *
     * BUILD_STANDARD §4: "Deleted-related-record renders gracefully — never a
     * crash." We have hit this class of bug repeatedly.
     */
    public function test_a_grant_with_a_deleted_contact_still_renders(): void
    {
        // No factories exist for Agency/Contact — hand-build the world, and supply
        // every NOT-NULL column (contacts.branch_id has no default; BUILD_STANDARD
        // §2: "read the migration, every NOT-NULL column gets a value").
        $agency = \App\Models\Agency::create(['name' => 'Seaside Realty', 'slug' => 'seaside-realty']);
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);

        $contact = \App\Models\Contact::create([
            'agency_id'  => $agency->id,
            'branch_id'  => $branch->id,
            'first_name' => 'Thabo',
            'last_name'  => 'Nkosi',
            'email'      => 'thabo@seasiderealty.co.za',
        ]);

        [$grant] = app(DemoAccessService::class)->issue([
            'company_name'  => 'Seaside Realty (Pty) Ltd',
            'contact_email' => 'thabo@seasiderealty.co.za',
            'contact_id'    => $contact->id,
        ], $this->owner->id);

        $contact->delete();   // soft delete — the FK still points at a hidden row

        $this->actingAs($this->owner)
            ->get(route('admin.demo-access.show', $grant))
            ->assertOk()
            ->assertSee('Seaside Realty (Pty) Ltd');

        $this->actingAs($this->owner)
            ->get(route('admin.demo-access.index'))
            ->assertOk();
    }

    // ── T&C admin ────────────────────────────────────────────────────────────

    public function test_an_owner_publishes_a_new_tnc_version_rather_than_editing(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.demo-access.tnc'), [
                'body' => 'Version two of the CoreX demo terms. This text is materially different.',
            ])
            ->assertRedirect(route('admin.demo-access.tnc'));

        $this->assertSame(2, DemoTncVersion::count());
        $this->assertSame(2, DemoTncVersion::current()->version);
        $this->assertSame($this->owner->id, DemoTncVersion::current()->published_by_user_id);

        // v1 survives, unedited.
        $this->assertNotNull(DemoTncVersion::where('version', 1)->first());
    }

    public function test_publishing_empty_terms_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.demo-access.tnc'), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertSame(1, DemoTncVersion::count());
    }

    /** "Reset now" from PRIMARY explains itself rather than silently doing nothing. */
    public function test_reset_now_on_primary_explains_why_it_cannot_run(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.demo-access.reset'))
            ->assertSessionHasErrors('reset');
    }
}
