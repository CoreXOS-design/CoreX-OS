<?php

namespace Tests\Feature\Platform;

use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Models\Role;
use App\Models\User;
use App\Support\PlatformEsignMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * AT-447 — Platform E-Sign mode: CoreX's own contracts in the SAME e-sign, owned by no agency.
 * Uses DatabaseTransactions (the test schema is migrated once by any RefreshDatabase run).
 */
class PlatformEsignModeTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    private function platformTemplate(string $name = 'CoreX Subscription Agreement'): Template
    {
        return Template::withoutGlobalScopes()->create([
            'name' => $name, 'template_type' => 'general', 'page_count' => 1, 'is_global' => false,
            'is_platform' => true, 'agency_id' => null,
        ]);
    }

    private function inMode(): array
    {
        return [PlatformEsignMode::SESSION_KEY => true];
    }

    public function test_enter_and_exit_toggle_the_mode_and_are_owner_only(): void
    {
        $agency = Agency::create(['name' => 'Caprivi', 'slug' => 'caprivi-' . uniqid()]);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id]);
        $this->actingAs($admin)->get(route('admin.platform-esign.enter'))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.platform-esign.exit'))->assertForbidden();

        $this->actingAs($this->owner());
        $this->get(route('admin.platform-esign.enter'))->assertRedirect(route('docuperfect.dashboard'));
        $this->assertTrue(session(PlatformEsignMode::SESSION_KEY));
        $this->post(route('admin.platform-esign.exit'))->assertRedirect();
        $this->assertNull(session(PlatformEsignMode::SESSION_KEY));
    }

    public function test_esign_pages_open_in_platform_mode_with_the_banner_and_without_it_otherwise(): void
    {
        $this->actingAs($this->owner());

        foreach (['docuperfect.templates.index', 'docuperfect.create'] as $name) {
            $this->withSession($this->inMode())->get(route($name))->assertOk()->assertSee('Platform E-Sign');
        }
        $this->flushSession()->get(route('docuperfect.templates.index'))->assertOk()->assertDontSee('Nothing here belongs to an agency');
    }

    public function test_platform_templates_are_visible_only_in_platform_mode_to_the_owner(): void
    {
        $t = $this->platformTemplate();
        $agency = Agency::create(['name' => 'Caprivi', 'slug' => 'caprivi-' . uniqid()]);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id]);

        // A customer agency user never sees it — not in lists, not by id.
        $this->actingAs($admin);
        $this->assertFalse(Template::where('id', $t->id)->exists());
        $this->get(route('docuperfect.templates.index'))->assertOk()->assertDontSee('CoreX Subscription Agreement');
        $this->get(route('docuperfect.create'))->assertOk()->assertDontSee('CoreX Subscription Agreement');

        // The owner outside platform mode doesn't see it either.
        $this->actingAs($this->owner());
        $this->flushSession()->get(route('docuperfect.templates.index'))->assertOk()->assertDontSee('CoreX Subscription Agreement');

        // In platform mode they do.
        $this->withSession($this->inMode())->get(route('docuperfect.templates.index'))->assertOk()->assertSee('CoreX Subscription Agreement');
    }

    public function test_agency_templates_are_hidden_inside_platform_mode(): void
    {
        $agency = Agency::create(['name' => 'Caprivi', 'slug' => 'caprivi-' . uniqid()]);
        Template::withoutGlobalScopes()->create([
            'name' => 'Caprivi Own Mandate', 'template_type' => 'general', 'page_count' => 1, 'agency_id' => $agency->id,
        ]);
        $this->actingAs($this->owner());

        $this->withSession($this->inMode())->get(route('docuperfect.templates.index'))->assertOk()->assertDontSee('Caprivi Own Mandate');
    }

    public function test_mode_is_inert_outside_the_esign_pages_and_for_non_owners(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->withSession($this->inMode())->get(route('admin.dev-settings.index'));
        $this->assertFalse(PlatformEsignMode::active(), 'a non-docuperfect URL never counts as platform mode');

        $agency = Agency::create(['name' => 'Caprivi', 'slug' => 'caprivi-' . uniqid()]);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id]);
        $this->actingAs($admin)->withSession($this->inMode())->get(route('docuperfect.templates.index'));
        $this->assertFalse(PlatformEsignMode::active(), 'a non-owner with the flag set is never in platform mode');
    }

    // ── Flow: does the real wizard work with no agency? ──

    private function esignPlatformTemplate(): Template
    {
        return Template::withoutGlobalScopes()->create([
            'name' => 'CoreX Subscription Agreement', 'template_type' => 'general', 'page_count' => 1, 'is_global' => false,
            'is_platform' => true, 'agency_id' => null, 'is_esign' => true, 'render_type' => 'pdf',
            'fields_json' => [], 'owner_id' => null,
        ]);
    }

    public function test_wizard_lists_the_platform_template_and_starts_a_flow_with_no_agency(): void
    {
        $t = $this->esignPlatformTemplate();
        $this->actingAs($this->owner());

        $this->withSession($this->inMode())->get(route('docuperfect.esign.create'))
            ->assertOk()->assertSee('CoreX Subscription Agreement');

        $res = $this->withSession($this->inMode())->postJson(route('docuperfect.esign.store'), ['template_id' => $t->id]);
        $res->assertSuccessful();
        preg_match('#/esign/(\\d+)/step/#', (string) $res->json('redirect'), $m);
        $flowId = $m[1] ?? null;
        $this->assertNotNull($flowId, 'store should return the new flow: ' . $res->getContent());

        foreach ([1, 2, 3] as $step) {
            $r = $this->withSession($this->inMode())->get(route('docuperfect.esign.step', ['flow' => $flowId, 'step' => $step]));
            $this->assertContains($r->getStatusCode(), [200, 302], "step $step: " . $r->getStatusCode());
        }
    }
}
