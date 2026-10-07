<?php

declare(strict_types=1);

namespace Tests\Feature\LeadResponse;

use App\Models\AgencyContactSettings;
use App\Models\AgencyOnboardingSetup;
use App\Support\LeadResponse\BusinessHours;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lead response settings (Johan, 2026-10-07): "respond within N minutes" (default 60) and the per-weekday counting
 * hours (default every day 08:00–20:00, all 7 counted), on the Settings page AND in the Setup Wizard through ONE
 * saver (spec §6.1: only what was rendered is written).
 */
final class LeadResponseSettingsTest extends LeadResponseTestCase
{
    private function hours(array $overrides = []): array
    {
        $h = [];
        foreach (BusinessHours::DAYS as $d) {
            $h[$d] = ['counted' => '1', 'start' => '08:00', 'end' => '20:00'];
        }

        return array_replace_recursive($h, $overrides);
    }

    private function saveSettings(array $payload)
    {
        return $this->actingAs($this->admin)->from('/corex/settings')->put(route('command-center.settings.lead-response.update'), $payload);
    }

    private function settings(): AgencyContactSettings
    {
        return AgencyContactSettings::forAgency($this->agency->id)->fresh();
    }

    public function test_the_defaults_are_60_minutes_and_every_day_0800_to_2000(): void
    {
        $s = $this->settings();
        $this->assertSame(60, $s->leadResponseTargetMinutes());
        $this->assertEquals(BusinessHours::defaults(), $s->leadResponseHours());
    }

    public function test_the_settings_page_has_the_section_with_seven_days_and_copy_monday(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.settings'))->assertOk()->getContent();

        $this->assertStringContainsString("activeSection === 'lead-response'", $html);
        $this->assertStringContainsString('Respond to a new enquiry within (minutes)', $html);
        $this->assertStringContainsString('Copy Monday to all days', $html);
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $this->assertStringContainsString("name=\"lead_response_hours[{$d}][start]\"", $html);
            $this->assertStringContainsString("name=\"lead_response_hours[{$d}][counted]\"", $html);
        }
        $this->assertStringContainsString('name="lead_response_present"', $html);
    }

    public function test_saving_per_day_hours_and_target_is_stored_and_audited(): void
    {
        $this->saveSettings([
            'lead_response_present' => 1,
            'lead_response_target_minutes' => 45,
            'lead_response_hours' => $this->hours([
                'mon' => ['start' => '09:00', 'end' => '17:00'],
                'sat' => ['counted' => '0'],
                'sun' => ['counted' => '0'],
            ]),
        ])->assertSessionHasNoErrors();

        $s = $this->settings();
        $this->assertSame(45, $s->leadResponseTargetMinutes());
        $this->assertSame('09:00', $s->leadResponseHours()['mon']['start']);
        $this->assertFalse($s->leadResponseHours()['sat']['counted']);
        $this->assertTrue($s->leadResponseHours()['tue']['counted']);

        $audit = DB::table('lead_response_setting_audit')->where('agency_id', $this->agency->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame($this->admin->id, (int) $audit->changed_by_user_id);
        $this->assertSame(60, json_decode($audit->old_values, true)['target_minutes']);
        $this->assertSame(45, json_decode($audit->new_values, true)['target_minutes']);

        // saving the identical values again changes nothing and writes no second audit row
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_target_minutes' => 45, 'lead_response_hours' => $this->hours([
            'mon' => ['start' => '09:00', 'end' => '17:00'], 'sat' => ['counted' => '0'], 'sun' => ['counted' => '0'],
        ])]);
        $this->assertSame(1, DB::table('lead_response_setting_audit')->where('agency_id', $this->agency->id)->count());
    }

    public function test_a_post_without_the_marker_never_wipes_anything(): void
    {
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_target_minutes' => 30]);
        $this->saveSettings(['lead_response_target_minutes' => 999, 'lead_response_hours' => $this->hours(['mon' => ['counted' => '0']])]);

        $this->assertSame(30, $this->settings()->leadResponseTargetMinutes());
        $this->assertTrue($this->settings()->leadResponseHours()['mon']['counted']);
    }

    public function test_a_step_that_posts_only_one_field_leaves_the_other_alone(): void
    {
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_target_minutes' => 20, 'lead_response_hours' => $this->hours(['fri' => ['end' => '16:00']])]);

        $this->saveSettings(['lead_response_present' => 1, 'lead_response_target_minutes' => 25]);
        $this->assertSame(25, $this->settings()->leadResponseTargetMinutes());
        $this->assertSame('16:00', $this->settings()->leadResponseHours()['fri']['end'], 'hours untouched by a target-only post');

        $this->saveSettings(['lead_response_present' => 1, 'lead_response_hours' => $this->hours(['fri' => ['end' => '18:00']])]);
        $this->assertSame(25, $this->settings()->leadResponseTargetMinutes(), 'target untouched by an hours-only post');
        $this->assertSame('18:00', $this->settings()->leadResponseHours()['fri']['end']);
    }

    public function test_validation_refuses_bad_hours_and_targets(): void
    {
        // end before start
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_hours' => $this->hours(['tue' => ['start' => '18:00', 'end' => '09:00']])])
            ->assertSessionHasErrors('lead_response_hours.tue');
        // a counted day with no times
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_hours' => $this->hours(['wed' => ['start' => '', 'end' => '']])])
            ->assertSessionHasErrors('lead_response_hours.wed');
        // nothing counted at all
        $none = [];
        foreach (BusinessHours::DAYS as $d) {
            $none[$d] = ['counted' => '0', 'start' => '08:00', 'end' => '20:00'];
        }
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_hours' => $none])->assertSessionHasErrors('lead_response_hours');
        // target out of range
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_target_minutes' => 0])->assertSessionHasErrors('lead_response_target_minutes');
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_target_minutes' => 99999])->assertSessionHasErrors('lead_response_target_minutes');

        $this->assertSame(60, $this->settings()->leadResponseTargetMinutes(), 'nothing was saved');
        $this->assertEquals(BusinessHours::defaults(), $this->settings()->leadResponseHours());
    }

    public function test_start_equal_to_end_is_accepted_and_counts_nothing_for_that_day(): void
    {
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_hours' => $this->hours(['thu' => ['start' => '12:00', 'end' => '12:00']])])
            ->assertSessionHasNoErrors();

        $h = $this->settings()->leadResponseHours();
        $this->assertSame('12:00', $h['thu']['start']);
        $this->assertSame(0, BusinessHours::minutesBetween(
            \Carbon\CarbonImmutable::parse('2026-10-08 09:00:00', 'Africa/Johannesburg'),
            \Carbon\CarbonImmutable::parse('2026-10-08 18:00:00', 'Africa/Johannesburg'), $h, 'Africa/Johannesburg'));
    }

    public function test_an_agent_without_the_settings_permission_cannot_change_it(): void
    {
        $this->grantReports(); // strict permission posture: agents have no command_center.settings
        DB::table('role_permissions')->where('role', 'agent')->where('permission_key', 'command_center.settings')->delete();
        \App\Services\PermissionService::clearCache();

        $this->actingAs($this->agentA)->put(route('command-center.settings.lead-response.update'), ['lead_response_present' => 1, 'lead_response_target_minutes' => 5])->assertForbidden();
        $this->assertSame(60, $this->settings()->leadResponseTargetMinutes());
    }

    // ── The Setup Wizard ─────────────────────────────────────────────────

    public function test_the_wizard_row_exists_with_explain_affects_and_the_one_saver(): void
    {
        $control = collect(config('agency-onboarding-copy.contacts.controls'))->firstWhere('key', 'lead_response_target_minutes');
        $this->assertNotNull($control);
        $this->assertNotEmpty($control['explain']);
        $this->assertNotEmpty($control['affects']);
        $this->assertSame(60, $control['default']);
        $this->assertSame('agency-setup.steps.lead-response-hours', config('agency-onboarding-copy.contacts.partial'));
        $this->assertContains(
            \App\Http\Controllers\CommandCenter\ContactGovernanceController::class . '@updateLeadResponse',
            collect(config('agency-onboarding-copy.contacts.savers'))->map(fn ($s) => $s['controller'] . '@' . $s['method'])->all()
        );
    }

    public function test_the_wizard_step_shows_the_editor_and_saves_through_the_same_saver(): void
    {
        $s = new AgencyOnboardingSetup();
        $s->agency_id = $this->agency->id;
        $s->token = AgencyOnboardingSetup::generateToken();
        $s->slug = AgencyOnboardingSetup::generateSlug($this->agency->name, $this->agency->id);
        $s->current_step = 1;
        $s->completed_steps = [];
        $s->expires_at = now()->addDays(30);
        $s->save();

        $html = $this->actingAs($this->admin)->get(route('corex.agency-setup.step', ['step' => 'contacts']))->assertOk()->getContent();
        $this->assertStringContainsString('Copy Monday to all days', $html);
        $this->assertStringContainsString('name="lead_response_hours[sun][end]"', $html);
        $this->assertStringContainsString('Respond to a new enquiry within (minutes)', $html);
        $this->assertStringContainsString('What this changes', $html);

        $this->actingAs($this->admin)->post(route('corex.agency-setup.step.save', ['step' => 'contacts']), [
            'contacts_per_page' => 24,
            'lead_response_present' => 1,
            'lead_response_target_minutes' => 90,
            'lead_response_hours' => $this->hours(['sun' => ['counted' => '0']]),
        ])->assertSessionHasNoErrors();

        $this->assertSame(90, $this->settings()->leadResponseTargetMinutes());
        $this->assertFalse($this->settings()->leadResponseHours()['sun']['counted']);
    }

    public function test_the_wizard_saver_ignores_a_post_that_did_not_render_the_controls(): void
    {
        $this->saveSettings(['lead_response_present' => 1, 'lead_response_target_minutes' => 33]);

        $this->actingAs($this->admin);
        app(\App\Http\Controllers\CommandCenter\ContactGovernanceController::class)
            ->updateLeadResponse(Request::create('/x', 'POST', ['lead_response_target_minutes' => 5]));

        $this->assertSame(33, $this->settings()->leadResponseTargetMinutes());
    }
}
