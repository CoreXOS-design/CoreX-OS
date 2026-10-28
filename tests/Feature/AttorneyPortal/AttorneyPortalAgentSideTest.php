<?php

namespace Tests\Feature\AttorneyPortal;

use App\Mail\AttorneyPortalInviteMail;
use App\Models\Agency;
use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalSetting;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DocumentType;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Services\PermissionService;
use Database\Seeders\AttorneyDistributionDefaultsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** .ai/specs/attorney-portal.md §5, §6, §10 - the agent's side: who may send a link, the panel, the settings, the shipped defaults. */
class AttorneyPortalAgentSideTest extends TestCase
{
    use RefreshDatabase, AttorneyPortalFixtures;

    private function grant($agent, array $keys): void
    {
        foreach ($keys as $key) {
            RolePermission::create(['role' => $agent->role, 'permission_key' => $key, 'scope' => 'agency']);
        }
        PermissionService::clearCache();
    }

    public function test_sending_a_portal_link_needs_the_invite_permission(): void
    {
        $w = $this->world();
        // grants exist (so the permission check is real) but not the invite key
        $this->grant($w['agent'], ['deals_v2.view']);
        $this->actingAs($w['agent']);

        $this->post(route('deals-v2.attorney-portal.invite', $w['a']))->assertForbidden();
        Mail::assertNothingSent();

        $this->grant($w['agent'], ['deals_v2.invite_attorney']);
        $this->post(route('deals-v2.attorney-portal.invite', $w['a']))->assertRedirect();
        Mail::assertSent(AttorneyPortalInviteMail::class, fn ($m) => $m->hasTo('botha@bbb.co.za'));
        $this->assertDatabaseHas('attorney_portal_logs', ['event' => 'invited']);
    }

    public function test_it_refuses_politely_when_the_agency_switch_is_off_or_there_is_no_email(): void
    {
        $w = $this->world(false);
        $this->grant($w['agent'], ['deals_v2.invite_attorney']);
        $this->actingAs($w['agent']);

        $this->from('/back')->post(route('deals-v2.attorney-portal.invite', $w['a']))->assertRedirect('/back')->assertSessionHasErrors('portal');
        Mail::assertNothingSent();

        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['attorney_portal_enabled' => true]);
        $w['a']->forceFill(['email' => null])->save();
        $this->from('/back')->post(route('deals-v2.attorney-portal.invite', $w['a']))->assertSessionHasErrors('portal');
    }

    public function test_another_agencys_contact_or_access_is_a_404(): void
    {
        $w = $this->world();
        $this->grant($w['agent'], ['deals_v2.invite_attorney']);
        $access = app(\App\Services\AttorneyPortal\AttorneyPortalAccessService::class)->invite($w['a'], $w['agent'])['access'];

        // a second agency's user (own contact, own settings)
        $agency2 = Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);
        $branch2 = \App\Models\Branch::create(['agency_id' => $agency2->id, 'name' => 'B']);
        $user2 = \App\Models\User::factory()->create(['agency_id' => $agency2->id, 'branch_id' => $branch2->id, 'role' => $w['agent']->role]);

        $this->actingAs($user2);
        $this->post(route('deals-v2.attorney-portal.invite', $w['a']))->assertNotFound();
        $this->post(route('deals-v2.attorney-portal.revoke', $access))->assertNotFound();
        $this->assertNull($access->fresh()->revoked_at);
    }

    public function test_the_deal_panel_shows_who_has_access_and_the_actions(): void
    {
        $w = $this->world();
        $d = $this->deal($w, $this->transferTo($w, $w['a']) + ['bond_firm' => $w['firm']->id]);   // bond: firm chosen, no person
        $this->grant($w['agent'], ['deals_v2.invite_attorney']);

        $svc = app(\App\Services\AttorneyPortal\AttorneyPortalAccessService::class);
        $rows = collect($svc->panelFor($d['deal']))->keyBy('role');
        $this->assertSame('Adv Botha', $rows['transfer_attorney']['person']);
        $this->assertNull($rows['transfer_attorney']['access']);
        $this->assertNull($rows['bond_attorney']['contact'], 'a bond attorney firm with no person grants nobody');

        $svc->invite($w['a'], $w['agent']);
        $rows = collect($svc->panelFor($d['deal']->fresh()))->keyBy('role');
        $this->assertSame('live', $rows['transfer_attorney']['status']);

        $html = view('dr2._attorney-portal-access', ['deal' => $d['deal']->fresh()])->render();
        $this->actingAs($w['agent']);
        $html = view('dr2._attorney-portal-access', ['deal' => $d['deal']->fresh()])->render();
        $this->assertStringContainsString('data-attorney-portal-panel', $html);
        $this->assertStringContainsString('Resend link', $html);
        $this->assertStringContainsString('Revoke access', $html);
        $this->assertStringContainsString("Choose the attorney's contact person", $html);
    }

    public function test_the_panel_is_silent_when_the_portal_is_off(): void
    {
        $w = $this->world(false);
        $d = $this->deal($w, $this->transferTo($w, $w['a']));
        $this->actingAs($w['agent']);
        $this->assertSame('', trim(view('dr2._attorney-portal-access', ['deal' => $d['deal']])->render()));
    }

    public function test_settings_save_only_the_fields_posted_and_reach_the_page(): void
    {
        $w = $this->world(false);
        $this->grant($w['agent'], ['attorney_portal.manage_settings']);
        $this->actingAs($w['agent']);

        $this->get(route('corex.settings.attorney-portal.edit'))->assertOk()->assertSee('Attorney portal');

        $this->post(route('corex.settings.attorney-portal.update'), ['attorney_portal_access_grace_days' => 45])->assertRedirect();
        $s = AttorneyPortalSetting::forAgency($w['agency']->id);
        $this->assertSame(45, $s->graceDays());
        $this->assertFalse($s->isEnabled(), 'a post that did not carry the switch leaves it alone (onboarding 6.1)');

        $this->post(route('corex.settings.attorney-portal.update'), ['attorney_portal_enabled' => '1'])->assertRedirect();
        $s = AttorneyPortalSetting::forAgency($w['agency']->id);
        $this->assertTrue($s->isEnabled());
        $this->assertSame(45, $s->graceDays());
        $this->assertSame(30, $s->linkValidDays(), 'untouched = the default');

        $this->post(route('corex.settings.attorney-portal.update'), ['attorney_portal_link_valid_days' => 0])->assertSessionHasErrors('attorney_portal_link_valid_days');
    }

    public function test_the_setup_wizard_carries_the_three_settings(): void
    {
        $controls = collect(config('agency-onboarding-copy.properties.controls'))->where('source', 'attorney_portal')->pluck('key')->all();
        $this->assertEqualsCanonicalizing(['attorney_portal_enabled', 'attorney_portal_access_grace_days', 'attorney_portal_link_valid_days'], $controls);
        $savers = collect(config('agency-onboarding-copy.properties.savers'))->pluck('controller')->all();
        $this->assertContains(\App\Http\Controllers\CoreX\AttorneyPortalSettingsController::class, $savers);
        foreach (config('agency-onboarding-copy.properties.controls') as $c) {
            if (($c['source'] ?? '') === 'attorney_portal') {
                $this->assertNotEmpty($c['explain']);
                $this->assertNotEmpty($c['affects']);
            }
        }
    }

    public function test_the_permission_keys_exist_in_the_registry(): void
    {
        $keys = collect(config('corex-permissions.permissions'))->pluck('key')->all();
        $this->assertContains('deals_v2.invite_attorney', $keys);
        $this->assertContains('attorney_portal.manage_settings', $keys);
    }

    public function test_shipped_defaults_add_the_attorney_documents_and_respect_a_removal(): void
    {
        $w = $this->world();
        foreach (['otp', 'sale_agreement', 'mandate', 'fica', 'ids', 'por', 'rates_taxes', 'levy_statement'] as $slug) {
            DocumentType::firstOrCreate(['slug' => $slug], ['label' => $slug, 'sort_order' => 1, 'is_active' => true]);
        }
        // the agency deliberately removed "mandate" from the transferring attorney earlier
        $mandate = DocumentType::where('slug', 'mandate')->first();
        $rule = DealStageDocumentRule::create(['agency_id' => $w['agency']->id, 'pipeline_step_id' => null, 'document_type_id' => $mandate->id,
            'party_role' => 'transfer_attorney', 'delivery_mode' => 'secure_link', 'auto_on_stage_tick' => false, 'is_active' => true]);
        $rule->delete();

        (new AttorneyDistributionDefaultsSeeder())->run();
        (new AttorneyDistributionDefaultsSeeder())->run();   // idempotent

        $types = fn (string $role) => DealStageDocumentRule::withoutGlobalScope(AgencyScope::class)->where('agency_id', $w['agency']->id)
            ->whereNull('pipeline_step_id')->where('party_role', $role)->where('deal_stage_document_rules.is_active', true)
            ->join('document_types', 'document_types.id', '=', 'deal_stage_document_rules.document_type_id')->pluck('document_types.slug')->all();

        $this->assertEqualsCanonicalizing(['otp', 'sale_agreement', 'fica', 'ids', 'por', 'rates_taxes', 'levy_statement'], $types('transfer_attorney'), 'mandate stays removed');
        $this->assertEqualsCanonicalizing(['otp', 'sale_agreement', 'fica', 'ids', 'por', 'bond_grant'], $types('bond_attorney'));
        $this->assertContains('mandate', $types('conveyancer'));
        $this->assertTrue(DocumentType::where('slug', 'bond_grant')->exists(), 'the Bond Grant type is created');
        $this->assertSame(1, DealStageDocumentRule::withTrashed()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $w['agency']->id)
            ->where('party_role', 'bond_attorney')->where('document_type_id', DocumentType::where('slug', 'fica')->value('id'))->count(), 'no duplicate rows');
    }
}
