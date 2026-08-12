<?php

namespace Tests\Feature\Onboarding;

use App\Events\AgencyCreated;
use App\Mail\AgencyOnboardingSetupMail;
use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Agency Onboarding Setup Wizard — feature coverage.
 * Spec: .ai/specs/agency-onboarding-setup.md §12.
 *
 * Note: a fresh test DB has no role_permissions, so PermissionService falls back
 * to "allow all" — a plain admin passes every permission gate without seeding.
 */
class AgencySetupWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function agency(string $name = 'Coastal Realty'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name)]);
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function setupFor(Agency $agency, array $attrs = []): AgencyOnboardingSetup
    {
        $s = new AgencyOnboardingSetup();
        $s->agency_id       = $agency->id;
        $s->token           = AgencyOnboardingSetup::generateToken();
        $s->slug            = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $s->current_step    = 1;
        $s->completed_steps = [];
        $s->expires_at      = now()->addDays(30);
        $s->forceFill($attrs);
        $s->save();
        return $s;
    }

    private function ownerUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();
        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    // ── Token gate ──────────────────────────────────────────────────────────

    public function test_unknown_token_returns_404(): void
    {
        $this->get('/agency-setup/does-not-exist')->assertNotFound();
    }

    public function test_revoked_link_returns_410(): void
    {
        $agency = $this->agency();
        $setup  = $this->setupFor($agency, ['revoked_at' => now()->subDay()]);
        $this->get('/agency-setup/' . $setup->urlKey())->assertStatus(410);
    }

    public function test_expired_link_returns_410(): void
    {
        $agency = $this->agency();
        $setup  = $this->setupFor($agency, ['expires_at' => now()->subDay()]);
        $this->get('/agency-setup/' . $setup->urlKey())->assertStatus(410);
    }

    // ── Login gate ──────────────────────────────────────────────────────────

    public function test_login_gate_rejects_non_admin(): void
    {
        $agency = $this->agency();
        $setup  = $this->setupFor($agency);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);

        $resp = $this->post('/agency-setup/' . $setup->urlKey() . '/login', [
            'email' => $agent->email, 'password' => 'password',
        ]);

        $resp->assertOk()->assertSee('cannot run its setup');
        $this->assertGuest();
    }

    public function test_login_gate_rejects_wrong_agency_admin(): void
    {
        $agencyA = $this->agency('Agency A');
        $agencyB = $this->agency('Agency B');
        $setupA  = $this->setupFor($agencyA);
        $adminB  = $this->admin($agencyB);

        $resp = $this->post('/agency-setup/' . $setupA->urlKey() . '/login', [
            'email' => $adminB->email, 'password' => 'password',
        ]);

        $resp->assertOk()->assertSee('cannot run its setup');
        $this->assertGuest();
    }

    public function test_login_gate_accepts_correct_admin(): void
    {
        $agency = $this->agency();
        $setup  = $this->setupFor($agency);
        $admin  = $this->admin($agency);

        $resp = $this->post('/agency-setup/' . $setup->urlKey() . '/login', [
            'email' => $admin->email, 'password' => 'password',
        ]);

        $resp->assertRedirect(route('corex.agency-setup.index'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_gate_accepts_owner(): void
    {
        $agency = $this->agency();
        $setup  = $this->setupFor($agency);
        $owner  = $this->ownerUser();

        $resp = $this->post('/agency-setup/' . $setup->urlKey() . '/login', [
            'email' => $owner->email, 'password' => 'password',
        ]);

        $resp->assertRedirect(route('corex.agency-setup.index'));
        $this->assertAuthenticatedAs($owner);
    }

    // ── Wizard writes through the canonical settings store ───────────────────

    public function test_identity_step_writes_agency_via_canonical_path(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $setup  = $this->setupFor($agency);

        $resp = $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'identity']), [
            'trading_name' => 'Coastal Realty (Pty) Ltd',
            'email'        => 'hello@coastal.co.za',
            'ffc_no'       => 'FFC123456',
        ]);

        // Step 2 is now the feature switchboard (capabilities), inserted between
        // identity and branding (switchboard spec §5).
        $resp->assertRedirect(route('corex.agency-setup.step', ['step' => 'capabilities']));

        $agency->refresh();
        $this->assertSame('Coastal Realty (Pty) Ltd', $agency->trading_name);
        $this->assertSame('hello@coastal.co.za', $agency->email);
        $this->assertSame('FFC123456', $agency->ffc_no);

        $setup->refresh();
        $this->assertContains('identity', $setup->completed_steps);
        $this->assertSame(2, $setup->current_step);
    }

    public function test_branding_step_renders_and_writes_colours_and_logo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $agency = $this->agency();
        $agency->update(['trading_name' => 'Coastal Realty (Pty) Ltd']);
        $admin = $this->admin($agency);
        $this->setupFor($agency);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'branding']))
            ->assertOk()
            ->assertSee('Your four brand colours')
            ->assertSee('Dark Preview')
            ->assertSee('Light Preview')
            ->assertSee('Link text');

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'branding']), [
            'sidebar_color' => '#d81b60',
            'icon_color'    => '#d81b60',
            'default_color' => '#1a237e',
            'button_color'  => '#d81b60',
            'logo'          => \Illuminate\Http\UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertRedirect(route('corex.agency-setup.step', ['step' => 'branches']));

        $agency->refresh();
        $this->assertSame('#d81b60', $agency->sidebar_color);
        $this->assertSame('#1a237e', $agency->default_color);
        $this->assertSame('#d81b60', $agency->button_color);
        $this->assertNotNull($agency->logo_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($agency->logo_path);

        // The canonical branding saver must NOT wipe sibling company fields.
        $this->assertSame('Coastal Realty (Pty) Ltd', $agency->trading_name);
    }

    public function test_matches_step_writes_performance_settings_via_canonical_path(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'matches']), [
            'matches_enabled'          => '1',
            'matches_show_on_properties' => '1',
            'matches_visibility_scope' => 'branch',
            'matches_wa_message'       => 'Hi, a new listing matches your search.',
        ])->assertRedirect(route('corex.agency-setup.step', ['step' => 'contacts']));

        $this->assertSame('branch', PerformanceSetting::get('matches_visibility_scope'));
        $this->assertSame('Hi, a new listing matches your search.', PerformanceSetting::get('matches_wa_message'));
    }

    public function test_commission_step_renders_and_writes_inline(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // Renders the real inline form (no deep-link / new tab).
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'commission']))
            ->assertOk()
            ->assertSee('Commission split')
            ->assertDontSee('Open Commission');

        $payload = [
            'commission_split_agent' => 70, 'annual_cap' => 1000000,
            'post_cap_transaction_fee' => 500, 'post_cap_fee_cap' => 5000, 'post_cap_reduced_fee' => 250,
            'monthly_platform_fee' => 1000, 'risk_management_fee' => 100, 'risk_management_cap' => 2000,
            'mentor_extra_split' => 10, 'mentor_transactions' => 5,
            'revenue_share_enabled' => 1, 'revenue_share_pool_percent' => 5,
            'tier_1_percent' => 5, 'tier_2_percent' => 4, 'tier_3_percent' => 3, 'tier_4_percent' => 2,
            'tier_5_percent' => 1, 'tier_6_percent' => 1, 'tier_7_percent' => 1,
            'tier_4_flqa_requirement' => 1, 'tier_5_flqa_requirement' => 2,
            'tier_6_flqa_requirement' => 3, 'tier_7_flqa_requirement' => 4,
        ];

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'commission']), $payload)
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'properties']));

        $c = \App\Models\CommissionSetting::forAgency($agency->id)->refresh();
        $this->assertSame(70, (int) $c->commission_split_agent);
        $this->assertSame(30, (int) $c->commission_split_agency);
        $this->assertSame(5, (int) $c->revenue_share_pool_percent);
    }

    public function test_no_step_deep_links_out_of_the_wizard(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // Every step renders inline — no "Open ... editor" deep-links anywhere.
        foreach (\App\Models\AgencyOnboardingSetup::STEPS as $step) {
            $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => $step]))
                ->assertOk()
                ->assertDontSee('Open Commission')
                ->assertDontSee('Manage property types')
                ->assertDontSee('Manage contact types');
        }
    }

    public function test_branches_step_adds_and_archives_inline(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);   // also creates branch "Main", admin assigned to it
        $this->setupFor($agency);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'branches']))
            ->assertOk()
            ->assertSee('Existing branches')
            ->assertSee('Main');

        // Add a branch through the wizard (canonical createBranch).
        $this->actingAs($admin)->post(route('corex.agency-setup.collection.add', ['collection' => 'branch']), [
            'name' => 'Seabreeze Bay', 'code' => 'SBB',
        ])->assertRedirect(route('corex.agency-setup.step', ['step' => 'branches']));

        $new = \App\Models\Branch::where('name', 'Seabreeze Bay')->first();
        $this->assertNotNull($new);
        $this->assertSame($agency->id, (int) $new->agency_id);

        // Archive it (no users attached) — soft delete, never a hard delete.
        $this->actingAs($admin)->delete(route('corex.agency-setup.collection.remove', ['collection' => 'branch', 'id' => $new->id]))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'branches']));

        $this->assertNull(\App\Models\Branch::find($new->id));
        $this->assertNotNull(\App\Models\Branch::withTrashed()->find($new->id));
    }

    public function test_branch_with_assigned_users_cannot_be_archived(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);   // admin sits on branch "Main"
        $this->setupFor($agency);

        $main = \App\Models\Branch::where('name', 'Main')->firstOrFail();

        $this->actingAs($admin)->delete(route('corex.agency-setup.collection.remove', ['collection' => 'branch', 'id' => $main->id]))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'branches']))
            ->assertSessionHasErrors();

        // The refusal must hold — the branch is still live.
        $this->assertNotNull(\App\Models\Branch::find($main->id));
    }

    public function test_contacts_step_shows_lead_sources_not_fixed_contact_types(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // Contact types are the six fixed signing roles — the wizard must not
        // render them as if they were configurable.
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'contacts']))
            ->assertOk()
            ->assertSee('Lead sources')
            ->assertDontSee('Contact types');
    }

    public function test_every_step_explains_itself_before_asking_for_config(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // Every step carries a plain-English "what is this" explainer card.
        foreach (\App\Models\AgencyOnboardingSetup::STEPS as $step) {
            $what = config("agency-onboarding-copy.$step.what");
            $this->assertNotEmpty($what, "Step [$step] must explain itself before asking for config.");
            $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => $step]))
                ->assertOk()
                ->assertSee($what['title']);
        }

        // The jargon case that prompted this: Matches defines itself first.
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'matches']))
            ->assertSee('What Core Matches is')
            ->assertSee('What this changes:');
    }

    public function test_notifications_step_writes_inline(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'notifications']))->assertOk();

        // notifications (11) advances to the 'roles' explainer (12), then 'access' (13).
        // (Stale assertion fix — 'roles' was inserted before 'access' pre-registry.)
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'notifications']), [
            'dashboard_settings_mode' => 'agency',
            'idle_alerts_enabled'     => '1',
            'notify_email'            => '1',
        ])->assertRedirect(route('corex.agency-setup.step', ['step' => 'roles']));

        $agency->refresh();
        $this->assertSame('agency', $agency->dashboard_settings_mode);
        $this->assertDatabaseHas('agency_dashboard_settings', [
            'agency_id' => $agency->id, 'idle_alerts_enabled' => 1, 'notify_email' => 1,
        ]);
    }

    public function test_compliance_step_writes_inline(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'compliance']), [
            'whistleblow_compliance_officer_email' => 'compliance@coastal.co.za',
        ])->assertRedirect(route('corex.agency-setup.step', ['step' => 'notifications']));

        $agency->refresh();
        $this->assertSame('compliance@coastal.co.za', $agency->whistleblow_compliance_officer_email);
    }

    public function test_inline_collection_add_and_remove(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // Add a lead source through the wizard's inline editor (canonical CRUD).
        // (The property-list editors were removed from the wizard on 2026-07-11;
        // contact sources are now the collection that exercises this mechanism.)
        $this->actingAs($admin)->post(route('corex.agency-setup.collection.add', ['collection' => 'contact_source']), [
            'name' => 'Show Day',
        ])->assertRedirect(route('corex.agency-setup.step', ['step' => 'contacts']));

        $item = \App\Models\ContactSource::where('name', 'Show Day')->first();
        $this->assertNotNull($item);

        // Remove it inline.
        $this->actingAs($admin)->delete(route('corex.agency-setup.collection.remove', ['collection' => 'contact_source', 'id' => $item->id]))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'contacts']));

        $this->assertNull(\App\Models\ContactSource::where('id', $item->id)->first());
    }

    /**
     * The property-list editors, the portal-credentials block and the
     * "Invite your team" step were removed from the wizard on 2026-07-11
     * (Johan). Guard the removal so they don't creep back in.
     */
    public function test_removed_sections_are_gone(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        $this->assertNotContains('team', \App\Models\AgencyOnboardingSetup::STEPS);
        // 11 original steps + the 'roles' explainer + the 'capabilities' feature
        // switchboard inserted at position 2 (switchboard spec §5/§7).
        $this->assertSame(13, \App\Models\AgencyOnboardingSetup::totalSteps());

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'properties']))
            ->assertOk()
            ->assertDontSee('Your property lists')
            ->assertDontSee('Portal credentials')
            ->assertDontSee('Advanced portal settings')
            // The master feature toggles moved to the switchboard — one home per
            // switch (switchboard spec §3.2). They must be GONE from the
            // properties detail step.
            ->assertDontSee('Publish listings to Property24')
            ->assertDontSee('Marketing tools');

        // The collections that backed those sections no longer resolve.
        $this->actingAs($admin)->post(route('corex.agency-setup.collection.add', ['collection' => 'property_type']), [
            'name' => 'Beachfront Villa',
        ])->assertNotFound();

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'team']))
            ->assertNotFound();
    }

    public function test_validation_error_keeps_user_on_step(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // Presentations requires the coverage thresholds; omit them → validation
        // fails and the step does NOT advance.
        $resp = $this->actingAs($admin)->from(route('corex.agency-setup.step', ['step' => 'presentations']))
            ->post(route('corex.agency-setup.step.save', ['step' => 'presentations']), []);

        $resp->assertRedirect(route('corex.agency-setup.step', ['step' => 'presentations']));
        $resp->assertSessionHasErrors();
    }

    // ── Resume / progress / skip ─────────────────────────────────────────────

    public function test_index_resumes_at_current_step(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        // capabilities is now step 2, so branches is step 4 (switchboard spec §5).
        $this->setupFor($agency, ['current_step' => 4, 'completed_steps' => ['identity', 'capabilities', 'branding']]);

        $this->actingAs($admin)->get(route('corex.agency-setup.index'))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'branches']));
    }

    public function test_skip_advances_without_marking_complete(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $setup  = $this->setupFor($agency);

        $this->actingAs($admin)->post(route('corex.agency-setup.step.skip', ['step' => 'identity']))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'capabilities']));

        $setup->refresh();
        $this->assertNotContains('identity', (array) $setup->completed_steps);
        $this->assertSame(2, $setup->current_step);
    }

    public function test_finish_marks_complete(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $setup  = $this->setupFor($agency);

        $this->actingAs($admin)->post(route('corex.agency-setup.finish'))
            ->assertRedirect(route('dashboard'));

        $setup->refresh();
        $this->assertNotNull($setup->completed_at);
        $this->assertTrue($setup->isComplete());
    }

    // ── Event / listener ─────────────────────────────────────────────────────

    // AT §R1a/§R1b (2026-08-12): the mail send moved OUT of this listener and
    // onto the Admin's first real login — see the "First-login trigger"
    // section below. This listener now only creates the record.
    public function test_listener_creates_setup_without_sending_mail(): void
    {
        Mail::fake();
        $agency = $this->agency();

        event(new AgencyCreated(agency: $agency, adminUser: null, adminEmail: 'admin@coastal.co.za', createdByUserId: null));

        $this->assertDatabaseHas('agency_onboarding_setups', ['agency_id' => $agency->id]);
        Mail::assertNothingSent();
    }

    public function test_listener_is_idempotent(): void
    {
        Mail::fake();
        $agency = $this->agency();

        event(new AgencyCreated(agency: $agency, adminUser: null, adminEmail: 'admin@coastal.co.za', createdByUserId: null));
        event(new AgencyCreated(agency: $agency, adminUser: null, adminEmail: 'admin@coastal.co.za', createdByUserId: null));

        $this->assertSame(1, AgencyOnboardingSetup::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
        Mail::assertNothingSent();
    }

    // ── First-login trigger (AgencyAdminFirstLoginService, spec §R1b) ────────

    public function test_first_login_sends_onboarding_mail_and_flags_welcome_popup(): void
    {
        Mail::fake();
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $setup  = $this->setupFor($agency, ['admin_user_id' => $admin->id]);

        $this->assertNull($admin->first_login_at);

        $this->post(route('login'), [
            'email'    => $admin->email,
            'password' => 'password', // UserFactory default
        ])->assertRedirect(route('dashboard', absolute: false));

        $admin->refresh();
        $setup->refresh();
        $this->assertNotNull($admin->first_login_at);
        $this->assertNotNull($setup->invite_email_sent_at);
        Mail::assertSent(AgencyOnboardingSetupMail::class, 1);
        $this->assertTrue(session('show_welcome_onboarding_popup'));
        $this->assertSame($setup->publicUrl(), session('welcome_onboarding_url'));
    }

    public function test_second_login_does_not_resend_onboarding_mail(): void
    {
        Mail::fake();
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency, ['admin_user_id' => $admin->id]);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password']);
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password']);

        Mail::assertSent(AgencyOnboardingSetupMail::class, 1);
    }

    // Regression test for the bug this replaced Event::listen(Login::class) for:
    // Auth::login() inside ImpersonateController ALSO fires Illuminate\Auth\Events\Login,
    // which would consume the target Admin's first-login trigger from the OWNER's
    // action — before the fix, the Admin's own later real login was silently a no-op.
    public function test_impersonation_does_not_trigger_first_login_onboarding(): void
    {
        Mail::fake();
        $owner  = $this->ownerUser();
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency, ['admin_user_id' => $admin->id]);

        $this->actingAs($owner)->post(route('impersonate.start', $admin));

        $admin->refresh();
        $this->assertNull($admin->first_login_at, 'Impersonation must not consume the Admin\'s own first-login trigger.');
        Mail::assertNothingSent();

        // The Admin's OWN real login afterwards must still work normally.
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password']);
        $admin->refresh();
        $this->assertNotNull($admin->first_login_at);
        Mail::assertSent(AgencyOnboardingSetupMail::class, 1);
    }

    // The wizard's own login gate lands the Admin IN the wizard already, so it
    // deliberately sends the mail but skips the redundant "go start onboarding"
    // pop-up (AgencyAdminFirstLoginService::handle($user, showWelcomePopup: false)).
    public function test_agency_setup_gate_login_sends_mail_but_not_popup(): void
    {
        Mail::fake();
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $setup  = $this->setupFor($agency, ['admin_user_id' => $admin->id]);

        $this->post(route('agency-setup.login', $setup->urlKey()), [
            'email'    => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('corex.agency-setup.index'));

        $admin->refresh();
        $this->assertNotNull($admin->first_login_at);
        Mail::assertSent(AgencyOnboardingSetupMail::class, 1);
        $this->assertNull(session('show_welcome_onboarding_popup'));
    }

    // ── Controller hook: live fires, demo does not ───────────────────────────

    public function test_store_fires_event_for_live_agency(): void
    {
        Event::fake();
        $owner = $this->ownerUser();

        // No admin_password — email-only invite (spec §R1a), the field no
        // longer exists on the form or in validation.
        $this->actingAs($owner)->post(route('agencies.store'), [
            'name'        => 'New Live Agency',
            'is_demo'     => '0',
            'admin_name'  => 'Ann Admin',
            'admin_email' => 'ann@newlive.co.za',
        ])->assertRedirect(route('agencies.index'));

        Event::assertDispatched(AgencyCreated::class);
    }

    public function test_store_does_not_fire_event_for_demo_agency(): void
    {
        Event::fake();
        $owner = $this->ownerUser();

        $this->actingAs($owner)->post(route('agencies.store'), [
            'name'    => 'Demo Agency',
            'is_demo' => '1',
        ])->assertRedirect(route('agencies.index'));

        Event::assertNotDispatched(AgencyCreated::class);
    }

    // ── Backfill for pre-existing agencies ───────────────────────────────────

    public function test_backfill_creates_records_for_existing_live_agencies_only(): void
    {
        Mail::fake();
        $live  = $this->agency('Established Realty');
        $admin = $this->admin($live);
        $demo  = Agency::create(['name' => 'Demo Co', 'slug' => 'demo-co', 'is_demo' => true]);

        $this->artisan('agency:backfill-onboarding-setups')->assertSuccessful();

        // Live agency gets a record linked to its admin; demo does not.
        $setup = AgencyOnboardingSetup::withoutGlobalScopes()->where('agency_id', $live->id)->first();
        $this->assertNotNull($setup);
        $this->assertSame($admin->id, $setup->admin_user_id);
        $this->assertSame(0, AgencyOnboardingSetup::withoutGlobalScopes()->where('agency_id', $demo->id)->count());

        // No email on the default (deploy) path — existing agencies aren't blasted.
        Mail::assertNothingSent();
    }

    public function test_backfill_is_idempotent(): void
    {
        Mail::fake();
        $live = $this->agency('Established Realty');
        $this->admin($live);

        $this->artisan('agency:backfill-onboarding-setups')->assertSuccessful();
        $this->artisan('agency:backfill-onboarding-setups')->assertSuccessful();

        $this->assertSame(1, AgencyOnboardingSetup::withoutGlobalScopes()->where('agency_id', $live->id)->count());
    }

    public function test_backfill_emails_only_with_flag(): void
    {
        Mail::fake();
        $live  = $this->agency('Established Realty');
        $admin = $this->admin($live);

        $this->artisan('agency:backfill-onboarding-setups', ['--email' => true])->assertSuccessful();

        Mail::assertSent(AgencyOnboardingSetupMail::class, 1);

        // Regression (found in review 2026-08-12): this send used to NOT
        // stamp invite_email_sent_at, so the same admin's later first login
        // would find the setup still "unsent" and email them a second time.
        $setup = AgencyOnboardingSetup::withoutGlobalScopes()->where('agency_id', $live->id)->first();
        $this->assertNotNull($setup->invite_email_sent_at);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password']);
        Mail::assertSent(AgencyOnboardingSetupMail::class, 1); // still 1, not 2
    }

    // ── Resend (owner tracking page) ──────────────────────────────────────────

    // Regression (found in review 2026-08-12): $setup->admin resolved via the
    // `admin()` relation, which returns a User — and User's OWN AgencyScope
    // applied to it. An owner who had entered the agency switcher (session
    // active_agency_id set to a DIFFERENT agency than the one being resent)
    // got a silent null admin email for every agency but the one in context.
    public function test_resend_works_for_an_agency_other_than_the_owners_switched_context(): void
    {
        Mail::fake();
        $owner = $this->ownerUser();

        $otherAgency = $this->agency('Other Agency');
        $targetAgency = $this->agency('Target Agency');
        $targetAdmin  = $this->admin($targetAgency);
        $setup        = $this->setupFor($targetAgency, ['admin_user_id' => $targetAdmin->id]);

        $this->actingAs($owner)
            ->withSession(['active_agency_id' => $otherAgency->id])
            ->post(route('admin.agency-setup-progress.resend', $setup->id))
            ->assertSessionHas('success');

        Mail::assertSent(AgencyOnboardingSetupMail::class, 1);
        $setup->refresh();
        $this->assertNotNull($setup->invite_email_sent_at);
    }

    // Same root cause as the resend test above, found while self-reviewing
    // the fix: index()'s ->with('admin') eager load had the identical bug —
    // the tracking board's Admin column would blank out for every agency
    // except whichever one the owner had switched into.
    public function test_tracking_page_shows_admin_for_agency_other_than_owners_switched_context(): void
    {
        $owner = $this->ownerUser();

        $otherAgency  = $this->agency('Other Agency');
        $targetAgency = $this->agency('Target Agency');
        $targetAdmin  = $this->admin($targetAgency);
        $this->setupFor($targetAgency, ['admin_user_id' => $targetAdmin->id]);

        $this->actingAs($owner)
            ->withSession(['active_agency_id' => $otherAgency->id])
            ->get(route('admin.agency-setup-progress'))
            ->assertSee($targetAdmin->email);
    }
}
