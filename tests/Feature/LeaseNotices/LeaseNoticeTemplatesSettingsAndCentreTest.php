<?php

declare(strict_types=1);

namespace Tests\Feature\LeaseNotices;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\RentalNoticeTemplate;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\RentalCommandCentreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * lease-notices.md §6/§7/§5.2 - the seeded default templates (per agency, idempotent, neutral), the CPA setting (settings page + Setup Wizard),
 * and the command centre's "Notice received - needs review" tile and queue row. Every second-agency check reads another agency.
 */
final class LeaseNoticeTemplatesSettingsAndCentreTest extends TestCase
{
    use BuildsNoticeFixture;
        use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        $this->withoutVite();
    }

    // ── the older Send notice screen: no seeding, a real empty state ────────

    public function test_with_no_templates_the_send_notice_screen_is_a_real_empty_state_linking_to_where_the_agency_uploads_them(): void
    {
        $this->assertSame(0, RentalNoticeTemplate::withoutGlobalScopes()->count(), 'nothing is seeded');

        $html = $this->actingAs($this->agent)->get(route('corex.leases.notices.create', $this->lease))->assertOk()->getContent();

        $this->assertStringNotContainsString('No active notice templates yet', $html);
        $this->assertStringContainsString('data-qa="notice-no-templates"', $html);
        $this->assertStringContainsString('has not added any notice templates yet', $html);
        $this->assertStringContainsString(route('corex.rental-notice-templates.index'), $html);
        $this->assertSame(0, RentalNoticeTemplate::withoutGlobalScopes()->count(), 'opening the screen creates nothing');
    }

    public function test_opening_the_templates_list_seeds_nothing(): void
    {
        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index'))->assertOk();
        $this->assertSame(0, RentalNoticeTemplate::withoutGlobalScopes()->count());
    }

    public function test_the_send_notice_screen_previews_and_will_not_send_with_a_hole_in_it(): void
    {
        $breach = RentalNoticeTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Our breach', 'notice_type' => 'breach_other', 'is_active' => true,
            'body_html' => '<p>Dear {{tenant_name}}, {{breach_description}} within {{remedy_days_x}}.</p>',
        ]);

        $this->actingAs($this->agent)->post(route('corex.leases.notices.store', $this->lease), ['rental_notice_template_id' => $breach->id, 'send_to_tenant' => '1', 'intent' => 'preview'])->assertRedirect();
        $preview = session('notice_preview');
        $this->assertEqualsCanonicalizing(['breach_description', 'remedy_days_x'], $preview['missing']);
        $this->assertStringContainsString('Tina Tenant', $preview['html']);

        $this->actingAs($this->agent)->post(route('corex.leases.notices.store', $this->lease), ['rental_notice_template_id' => $breach->id, 'send_to_tenant' => '1', 'intent' => 'send'])->assertSessionHasErrors('figures_raw');
        $this->assertSame(0, \App\Models\RentalNotice::withoutGlobalScopes()->count());

        $this->actingAs($this->agent)->post(route('corex.leases.notices.store', $this->lease), [
            'rental_notice_template_id' => $breach->id, 'send_to_tenant' => '1', 'intent' => 'send', 'figures_raw' => "breach_description=Rent unpaid for October\nremedy_days_x=7",
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, \App\Models\RentalNotice::withoutGlobalScopes()->count());
    }

    // ── the CPA setting (settings page + wizard, one saver) ─────────────────

    public function test_the_cpa_business_days_save_from_the_settings_page_and_the_wizard_and_a_partial_post_leaves_them_alone(): void
    {
        $admin = $this->agent;
        $this->grant($admin, ['leases.manage_settings']);
        $this->actingAs($admin)->get(route('corex.settings.leases.edit'))->assertOk()->assertSee('name="cpa_early_cancel_business_days"', false);

        $base = ['expiry_notice_window_days' => 60, 'tenant_notice_period_days' => 30, 'tenant_notice_period_unit' => 'days'];
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $base + ['cpa_early_cancel_business_days' => 18])->assertSessionHasNoErrors();
        $this->assertSame(18, LeaseSetting::cpaEarlyCancelBusinessDaysFor($this->agency->id));

        // a post that never rendered the field leaves it alone (§6.1)
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $base)->assertSessionHasNoErrors();
        $this->assertSame(18, LeaseSetting::cpaEarlyCancelBusinessDaysFor($this->agency->id));

        // blank = back to the default
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $base + ['cpa_early_cancel_business_days' => ''])->assertSessionHasNoErrors();
        $this->assertSame(20, LeaseSetting::cpaEarlyCancelBusinessDaysFor($this->agency->id));

        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $base + ['cpa_early_cancel_business_days' => 0])->assertSessionHasErrors('cpa_early_cancel_business_days');

        // the breach remedy window (rental-notices.md §5): same saver, same has()-guard, blank = the default 20
        $this->actingAs($admin)->get(route('corex.settings.leases.edit'))->assertSee('name="breach_remedy_business_days"', false);
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $base + ['breach_remedy_business_days' => 15])->assertSessionHasNoErrors();
        $this->assertSame(15, LeaseSetting::breachRemedyBusinessDaysFor($this->agency->id));
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $base)->assertSessionHasNoErrors();
        $this->assertSame(15, LeaseSetting::breachRemedyBusinessDaysFor($this->agency->id), 'absent = leave it alone');
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $base + ['breach_remedy_business_days' => ''])->assertSessionHasNoErrors();
        $this->assertSame(20, LeaseSetting::breachRemedyBusinessDaysFor($this->agency->id));
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $base + ['breach_remedy_business_days' => 0])->assertSessionHasErrors('breach_remedy_business_days');
    }

    // ── command centre ────────────────────────────────────────────────────

    public function test_the_command_centre_counts_a_notice_needing_review_and_lists_it_in_the_queue_until_it_is_decided(): void
    {
        RolePermission::create(['role' => $this->agent->role, 'permission_key' => 'rental_command_centre.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();
        $service = app(RentalCommandCentreService::class);
        $this->actingAs($this->agent);

        $this->assertSame(0, $service->tileCounts($this->agent, 'all')['notice_review']);
        $this->assertSame('Notice received - needs review', RentalCommandCentreService::TILES['notice_review']);

        $notice = $this->notice();
        $this->assertSame(1, $service->tileCounts($this->agent, 'all')['notice_review']);
        $row = $service->queueItems($this->agent, 'all')->firstWhere('type', 'notice_needs_review');
        $this->assertNotNull($row);
        $this->assertSame('corex.lease-notices.show', $row['route']);
        $this->assertSame(['id' => $notice->id], $row['route_params']);
        $this->assertStringContainsString('The tenant gave notice', $row['detail']);

        app(\App\Services\Rentals\LeaseNoticeService::class)->reject($notice, $this->agent, 'Wrong date');
        $this->assertSame(0, $service->tileCounts($this->agent, 'all')['notice_review']);
        $this->assertNull($service->queueItems($this->agent, 'all')->firstWhere('type', 'notice_needs_review'));
    }
}
