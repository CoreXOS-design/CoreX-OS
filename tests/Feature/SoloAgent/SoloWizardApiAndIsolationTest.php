<?php

namespace Tests\Feature\SoloAgent;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use App\Services\SoloAgent\SoloAgentProvisioner;
use App\Services\SoloAgent\SoloAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Solo Agent Accounts, build step 6 - the setup guide's solo track (NN #10a), the owner API (NN #7), and the
 * "what does this look like for the SECOND agency" check (project rule 9): two single agents never see each other.
 */
class SoloWizardApiAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    /** An open single-agent account. @return array{user: User, agency: Agency, signup: \App\Models\SoloAgentSignup} */
    private function solo(array $over = []): array
    {
        $this->n++;
        $r = app(SoloAgentProvisioner::class)->provision(array_merge([
            'agency_name' => "Solo {$this->n} Realty", 'name' => "Agent {$this->n}", 'email' => "solo{$this->n}@iso.test",
            'cell' => "082100{$this->n}000", 'ffc_number' => "FFC-{$this->n}", 'ffc_expires_on' => now()->addYear()->toDateString(),
        ], $over));
        app(SoloAgentService::class)->activate($r['signup']->fresh());

        return ['user' => $r['user']->fresh(), 'agency' => $r['agency']->fresh(), 'signup' => $r['signup']->fresh()];
    }

    private function owner(): User
    {
        $role = Role::query()->firstOrNew(['name' => 'super_admin', 'agency_id' => null]);
        $role->forceFill(['label' => 'System Owner', 'is_owner' => true, 'can_be_deleted' => false])->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'is_active' => 1]);
    }

    // ── Setup guide: the solo track ────────────────────────────────────────

    public function test_a_single_agents_setup_guide_is_the_short_track_in_order(): void
    {
        $a = $this->solo();

        $this->assertSame(['welcome', 'identity', 'branding', 'notifications', 'access'], AgencyOnboardingSetup::activeSteps($a['agency']));
        $this->assertSame(config('solo-agent.wizard_steps'), AgencyOnboardingSetup::activeSteps($a['agency']));
    }

    public function test_every_solo_track_step_is_a_real_wizard_step_with_copy(): void
    {
        foreach (config('solo-agent.wizard_steps') as $step) {
            $this->assertContains($step, AgencyOnboardingSetup::STEPS, "'$step' is not a wizard step");
            $this->assertNotEmpty(config("agency-onboarding-copy.$step.title"));
        }
    }

    public function test_a_normal_agencys_setup_guide_is_unchanged(): void
    {
        $agency = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);

        $steps = AgencyOnboardingSetup::activeSteps($agency);

        foreach (['capabilities', 'branches', 'commission', 'properties', 'contacts', 'roles', 'outgoing_mail'] as $teamStep) {
            $this->assertContains($teamStep, $steps, "a normal agency must still get the '$teamStep' step");
        }
        $this->assertGreaterThan(count(config('solo-agent.wizard_steps')), count($steps));
    }

    public function test_the_guide_opens_on_the_welcome_step_with_single_agent_wording_and_skips_team_steps(): void
    {
        $a = $this->solo();

        $this->actingAs($a['user'])->get(route('corex.agency-setup.step', ['step' => 'welcome']))
            ->assertOk()
            ->assertSee('setting up your CoreX account', false)
            ->assertDontSee('copy of CoreX');

        // A team step is stepped past, not a 404: the guide moves on to the next step of the short track that follows it.
        $this->actingAs($a['user'])->get(route('corex.agency-setup.step', ['step' => 'branches']))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'notifications']));
        $this->actingAs($a['user'])->get(route('corex.agency-setup.step', ['step' => 'commission']))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'notifications']));
        $this->actingAs($a['user'])->get(route('corex.agency-setup.step', ['step' => 'capabilities']))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'branding']));
    }

    public function test_a_normal_agency_still_sees_the_original_welcome_wording(): void
    {
        $agency = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id, 'is_active' => 1]);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'welcome']))
            ->assertOk()
            ->assertSee('copy of CoreX', false)
            ->assertDontSee('setting up your CoreX account', false);
    }

    public function test_the_identity_step_saves_what_they_do_and_never_unlocks_a_locked_feature(): void
    {
        $a = $this->solo();

        $res = $this->actingAs($a['user'])->post(route('corex.agency-setup.step.save', ['step' => 'identity']), [
            'trading_name' => 'Solo Trading', 'agency_type' => 'rentals',
        ]);
        $res->assertSessionHasNoErrors();

        // Read the row itself (Agency::find may hand back an instance loaded earlier in this test).
        $row = \Illuminate\Support\Facades\DB::table('agencies')->where('id', $a['agency']->id)->first();
        $this->assertSame('rentals', $row->agency_type);
        $this->assertSame('Solo Trading', $row->trading_name);
        $this->assertSame('solo', $row->account_kind);
        $agency = Agency::find($a['agency']->id);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($agency->id);
        $map = app(\App\Services\Features\AgencyFeatureService::class)->all($agency);
        foreach (config('solo-agent.locked_features') as $key) {
            $this->assertFalse($map[$key], "'$key' must stay off after the guide changes what they do");
        }
    }

    public function test_choosing_what_they_do_in_the_guide_does_not_open_the_features_switchboard(): void
    {
        $a = $this->solo();

        // The type is theirs to choose, and only through their setup guide. The Features page, its switches and the direct
        // save address stay closed to a single agent.
        $this->actingAs($a['user'])->get(route('corex.features.index'))->assertForbidden();
        $this->actingAs($a['user'])->post(route('corex.settings.agency-type.update'), ['agency_type' => 'sales'])->assertForbidden();
        $this->assertSame('both', \Illuminate\Support\Facades\DB::table('agencies')->where('id', $a['agency']->id)->value('agency_type'));
    }

    // ── The owner API ──────────────────────────────────────────────────────

    public function test_the_owner_api_lists_single_agents_with_search_sort_and_paging(): void
    {
        $this->solo(['agency_name' => 'Zebra Properties', 'name' => 'Zed Zebra', 'email' => 'zed@zebra.test']);
        $this->solo(['agency_name' => 'Aardvark Realty']);

        $res = $this->actingAs($this->owner())->getJson('/api/v1/solo-agents?sort=agency&dir=asc')->assertOk();
        $this->assertSame(['Aardvark Realty', 'Zebra Properties'], array_column(array_column($res->json('data'), 'agency'), 'name'));
        $this->assertSame(2, $res->json('meta.total'));

        $row = $res->json('data.1');
        foreach (['id', 'agency', 'agent', 'ffc', 'does', 'created_at', 'contract', 'status', 'last_login_at', 'listings', 'deals', 'ai_templates'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame('zed@zebra.test', $row['agent']['email']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(['used' => 0, 'limit' => 5], $row['ai_templates']);

        $one = $this->actingAs($this->owner())->getJson('/api/v1/solo-agents?q=zebra')->assertOk();
        $this->assertSame(1, $one->json('meta.total'));
        $this->assertSame('Zebra Properties', $one->json('data.0.agency.name'));
    }

    public function test_the_api_never_exposes_the_agents_contacts_or_deals(): void
    {
        $a = $this->solo();

        $payload = json_encode($this->actingAs($this->owner())->getJson('/api/v1/solo-agents')->assertOk()->json());

        foreach (['password', 'remember_token', 'contacts', 'deal_id', 'api_token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }

    public function test_only_the_owner_can_use_the_api(): void
    {
        $a = $this->solo();
        $other = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $other->id, 'is_active' => 1]);

        $this->actingAs($admin)->getJson('/api/v1/solo-agents')->assertForbidden();
        $this->actingAs($a['user'])->getJson('/api/v1/solo-agents')->assertForbidden();
        auth()->logout();
        $this->getJson('/api/v1/solo-agents')->assertUnauthorized();
    }

    public function test_the_endpoint_is_named_in_the_api_catalogue_and_there_is_no_public_signup(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.solo-agents.index');
        $this->assertNotNull($route, 'the endpoint must be named so the Admin -> API catalogue lists it');
        $this->assertSame('api/v1/solo-agents', $route->uri());

        // Nothing but a read lives under /api/v1/solo-agents, and there is no public registration route.
        foreach (Route::getRoutes() as $r) {
            if (str_starts_with($r->uri(), 'api/v1/solo-agents')) {
                $this->assertSame(['GET', 'HEAD'], $r->methods());
            }
        }
        $this->assertFalse(Route::has('register'));

        $this->actingAs($this->owner())->get(route('admin.api.catalog'))->assertOk()->assertSee('/api/v1/solo-agents', false);
    }

    // ── Second agency: two single agents never see each other ─────────────

    public function test_one_single_agent_cannot_reach_anothers_listings_or_contacts_by_direct_id(): void
    {
        $a = $this->solo();
        $b = $this->solo();

        $propertyB = Property::create([
            'agency_id' => $b['agency']->id, 'agent_id' => $b['user']->id, 'branch_id' => $b['user']->branch_id,
            'external_id' => (string) Str::uuid(), 'title' => 'B private listing', 'suburb' => 'Margate',
            'property_type' => 'house', 'status' => 'draft', 'price' => 0,
        ]);
        $contactB = Contact::create([
            'agency_id' => $b['agency']->id, 'branch_id' => $b['user']->branch_id, 'created_by_user_id' => $b['user']->id,
            'first_name' => 'Secret', 'last_name' => 'Contact', 'phone' => '0830000000',
        ]);

        // As agent A, agent B's rows do not exist at all - at the query layer, not just in the menu.
        $this->actingAs($a['user']);
        $this->assertNull(Property::find($propertyB->id));
        $this->assertNull(Contact::find($contactB->id));
        $this->assertSame(0, Property::count());
        $this->assertSame(0, Contact::count());
        $this->assertSame(1, Property::withoutGlobalScopes()->where('agency_id', $b['agency']->id)->count(), 'B still has its listing - A just cannot see it');
    }

    public function test_nothing_in_the_single_agent_build_is_tied_to_one_agency(): void
    {
        // Rule 9: no hardcoded agency ids or house names in the solo code paths.
        $root = base_path();
        $files = array_merge(
            glob($root . '/app/Services/SoloAgent/*.php'),
            glob($root . '/app/Listeners/SoloAgent/*.php'),
            glob($root . '/app/Events/SoloAgent/*.php'),
            [$root . '/app/Http/Controllers/Admin/SoloAgentController.php', $root . '/app/Models/SoloAgentSignup.php', $root . '/config/solo-agent.php'],
        );
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/agency_id[\'"]?\s*(=>|===?)\s*1\b/', $code, basename($file) . ' hardcodes agency 1');
            $this->assertStringNotContainsStringIgnoringCase('home finders', $code, basename($file));
            $this->assertStringNotContainsStringIgnoringCase('hfc', $code, basename($file));
        }
    }
}
