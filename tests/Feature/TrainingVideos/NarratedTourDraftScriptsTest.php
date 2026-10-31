<?php

namespace Tests\Feature\TrainingVideos;

use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Auto\TrainingScriptValidator;
use Tests\TestCase;

/**
 * AT-526 prep (31 Oct): the draft tour scripts for the whole Setup Wizard, the essential Settings and Role Manager. They are written and checked but INERT - narrated and approved are both
 * false, so there is no Guide me button, no voice clip and no video until Johan approves the pilot and the voice. Every one must pass cc1's validator (plain words, a why on every step, two
 * minutes at most) and carry the tour keys.
 */
final class NarratedTourDraftScriptsTest extends TestCase
{
    private const LIVE_PILOTS = ['book-a-viewing', 'agency-setup-identity', 'mic-work'];
    private const WIZARD_STEPS = ['welcome', 'capabilities', 'branding', 'branches', 'commission', 'proforma', 'leases', 'properties', 'presentations', 'matches',
        'market-intelligence', 'road-to-sold', 'contacts', 'compliance', 'outgoing-mail', 'notifications', 'roles', 'access'];

    private function drafts(): array
    {
        config(['training-videos.auto.scripts_dir' => resource_path('training/scripts')]);
        $this->app->forgetInstance(TrainingScriptRepository::class);

        return array_filter(app(TrainingScriptRepository::class)->all(), fn ($s) => ! in_array($s->key(), self::LIVE_PILOTS, true));
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

    public function test_the_drafts_are_inert_until_johan_approves_no_button_no_clips_no_video(): void
    {
        foreach ($this->drafts() as $s) {
            $this->assertFalse($s->narrated(), $s->key() . ' must not offer Guide me yet');
            $this->assertFalse($s->approved(), $s->key() . ' must not be generated as a video yet');
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
