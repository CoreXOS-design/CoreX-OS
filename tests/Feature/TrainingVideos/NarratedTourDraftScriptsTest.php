<?php

namespace Tests\Feature\TrainingVideos;

use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Auto\TrainingScriptValidator;
use Tests\TestCase;

/**
 * AT-526 / AT-525: the tour scripts for the whole Setup Wizard, the essential Settings and Role Manager. Finalised 3 Nov: approved for the video (Johan to spot-check), narrated (Guide me)
 * still off except on the pilot until the voice is agreed. Every one must pass cc1's validator (plain words, a why on every step, two
 * minutes at most) and carry the tour keys.
 */
final class NarratedTourDraftScriptsTest extends TestCase
{
    private const LIVE_PILOTS = ['book-a-viewing', 'agency-setup-identity', 'mic-work'];
    // the main-screen how-tos (FICA, Properties, Contacts, Calendar - 9.26; Deals - 9.27) are agent-recorded and some share a screen, so they have their own test (MainScreenTrainingScriptsTest)
    private const MAIN_SCREENS = ['fica-send-request', 'fica-agent-review', 'fica-client-form', 'properties-load', 'properties-edit', 'properties-photos', 'properties-status',
        'contacts-find', 'contacts-create', 'contacts-page-tabs', 'contacts-link-property', 'calendar-view-diary', 'calendar-book-appointment', 'calendar-viewing-feedback',
        'deals-register-find', 'deals-create', 'deals-pipeline-list', 'deals-pipeline-timeline', 'deals-work-orders'];
    private const WIZARD_STEPS = ['welcome', 'capabilities', 'branding', 'branches', 'commission', 'proforma', 'leases', 'properties', 'presentations', 'matches',
        'market-intelligence', 'road-to-sold', 'contacts', 'compliance', 'outgoing-mail', 'notifications', 'roles', 'access'];

    private function drafts(): array
    {
        config(['training-videos.auto.scripts_dir' => resource_path('training/scripts')]);
        $this->app->forgetInstance(TrainingScriptRepository::class);

        return array_filter(app(TrainingScriptRepository::class)->all(), fn ($s) => ! in_array($s->key(), self::LIVE_PILOTS, true) && ! in_array($s->key(), self::MAIN_SCREENS, true));
    }

    public function test_every_wizard_step_after_the_pilot_has_a_script_plus_settings_and_role_manager(): void
    {
        $keys = array_keys($this->drafts());
        foreach (self::WIZARD_STEPS as $step) {
            $this->assertContains('agency-setup-' . $step, $keys, "wizard step {$step}");
        }
        $this->assertContains('agency-settings-essentials', $keys);
        $this->assertContains('role-manager-create-role', $keys);
        $this->assertCount(20, $keys);
    }

    public function test_every_draft_passes_the_validator_has_a_why_on_every_step_and_fits_two_minutes(): void
    {
        $validator = new TrainingScriptValidator();
        foreach ($this->drafts() as $s) {
            $this->assertSame([], $validator->problems($s), $s->key() . ' must pass the validator (plain words, why, two minutes)');
            $this->assertLessThanOrEqual(120, $validator->estimateSeconds($s), $s->key() . ' is over two minutes');
            foreach ($s->steps() as $i => $step) {
                $this->assertNotSame('', trim((string) $step['why']), $s->key() . ' step ' . ($i + 1) . ' needs a why');
                $this->assertNotSame('', trim((string) $step['selector']), $s->key() . ' step ' . ($i + 1) . ' needs a selector');
                $this->assertNotSame('', trim((string) ($step['done'] ?? '')), $s->key() . ' step ' . ($i + 1) . ' needs a done-condition');
            }
        }
    }

    public function test_the_finalised_scripts_are_approved_for_video_but_the_narrated_tour_stays_off_except_the_pilot(): void
    {
        foreach ($this->drafts() as $s) {
            $this->assertTrue($s->approved(), $s->key() . ' was finalised on 3 Nov and must be approved for the video');
            $this->assertSame($s->key() === 'agency-setup-identity', $s->narrated(), $s->key() . ': Guide me is offered only on the pilot until Johan agrees the voice');
        }
    }

    public function test_every_finalised_script_records_as_the_training_admin_and_follows_the_route_convention(): void
    {
        foreach ($this->drafts() as $s) {
            $this->assertSame('admin', $s->loginAs(), $s->key() . ' opens an admin-only screen, so the recorder must sign in as the training admin');
            if (str_starts_with($s->key(), 'agency-setup-')) {
                $this->assertNull($s->route(), $s->key() . ': Setup Wizard steps share one route name, so they are found by url');
                $this->assertStringStartsWith('/corex/agency-setup/step/', $s->url());
            } else {
                $this->assertNotNull($s->route(), $s->key() . ' needs a route so the Watch how button finds it');
            }
        }
    }

    public function test_the_role_manager_and_settings_anchors_the_scripts_point_at_exist_in_the_views(): void
    {
        $rm = file_get_contents(resource_path('views/corex/role-manager.blade.php'));
        foreach (['rm-tab-roles', 'rm-add-role', 'rm-role-label', 'rm-role-save', 'rm-tab-permissions', 'rm-role-pills', 'rm-matrix', 'rm-tab-users'] as $anchor) {
            $this->assertStringContainsString('data-tour="' . $anchor . '"', $rm, $anchor);
        }
        $st = file_get_contents(resource_path('views/corex/settings.blade.php'));
        $this->assertSame(2, substr_count($st, 'data-tour="settings-rail-{{ $item[\'key\'] }}"'), 'both the section buttons and the page links carry the rail anchor');
    }
}
