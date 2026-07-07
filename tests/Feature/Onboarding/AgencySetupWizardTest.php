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

        $resp->assertRedirect(route('corex.agency-setup.step', ['step' => 'commission']));

        $agency->refresh();
        $this->assertSame('Coastal Realty (Pty) Ltd', $agency->trading_name);
        $this->assertSame('hello@coastal.co.za', $agency->email);
        $this->assertSame('FFC123456', $agency->ffc_no);

        $setup->refresh();
        $this->assertContains('identity', $setup->completed_steps);
        $this->assertSame(2, $setup->current_step);
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
        $this->setupFor($agency, ['current_step' => 3, 'completed_steps' => ['identity', 'commission']]);

        $this->actingAs($admin)->get(route('corex.agency-setup.index'))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'properties']));
    }

    public function test_skip_advances_without_marking_complete(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $setup  = $this->setupFor($agency);

        $this->actingAs($admin)->post(route('corex.agency-setup.step.skip', ['step' => 'identity']))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'commission']));

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

    public function test_listener_creates_setup_and_sends_mail(): void
    {
        Mail::fake();
        $agency = $this->agency();

        event(new AgencyCreated(agency: $agency, adminUser: null, adminEmail: 'admin@coastal.co.za', createdByUserId: null));

        $this->assertDatabaseHas('agency_onboarding_setups', ['agency_id' => $agency->id]);
        Mail::assertSent(AgencyOnboardingSetupMail::class);
    }

    public function test_listener_is_idempotent(): void
    {
        Mail::fake();
        $agency = $this->agency();

        event(new AgencyCreated(agency: $agency, adminUser: null, adminEmail: 'admin@coastal.co.za', createdByUserId: null));
        event(new AgencyCreated(agency: $agency, adminUser: null, adminEmail: 'admin@coastal.co.za', createdByUserId: null));

        $this->assertSame(1, AgencyOnboardingSetup::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
        Mail::assertSent(AgencyOnboardingSetupMail::class, 1);
    }

    // ── Controller hook: live fires, demo does not ───────────────────────────

    public function test_store_fires_event_for_live_agency(): void
    {
        Event::fake();
        $owner = $this->ownerUser();

        $this->actingAs($owner)->post(route('agencies.store'), [
            'name'           => 'New Live Agency',
            'is_demo'        => '0',
            'admin_name'     => 'Ann Admin',
            'admin_email'    => 'ann@newlive.co.za',
            'admin_password' => 'secret1234',
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
        $live = $this->agency('Established Realty');
        $this->admin($live);

        $this->artisan('agency:backfill-onboarding-setups', ['--email' => true])->assertSuccessful();

        Mail::assertSent(AgencyOnboardingSetupMail::class, 1);
    }
}
