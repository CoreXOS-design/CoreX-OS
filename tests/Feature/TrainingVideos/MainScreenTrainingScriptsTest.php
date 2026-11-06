<?php

namespace Tests\Feature\TrainingVideos;

use App\Services\TrainingVideos\Auto\TrainingAgencySeeder;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Auto\TrainingScriptValidator;
use Tests\TestCase;

/**
 * Training scripts for the screens agents use most (spec .ai/specs/training-videos.md 9.26): FICA (agent side and the client's link), Properties, Contacts, the Diary and Deals (9.27).
 * Every one passes the validator (plain words, a why on every step, two minutes at most), is recorded as the training agent, uses invented sample data only, and no two
 * scripts claim the same route or start url (the Watch how button would pick one by accident).
 */
final class MainScreenTrainingScriptsTest extends TestCase
{
    private const KEYS = ['fica-send-request', 'fica-agent-review', 'fica-client-form', 'properties-load', 'properties-edit', 'properties-photos', 'properties-status',
        'contacts-find', 'contacts-create', 'contacts-page-tabs', 'contacts-link-property', 'calendar-view-diary', 'calendar-book-appointment', 'calendar-viewing-feedback',
        'deals-register-find', 'deals-create', 'deals-pipeline-list', 'deals-pipeline-timeline', 'deals-work-orders'];

    /** @return array<string, \App\Services\TrainingVideos\Auto\TrainingScript> */
    private function all(): array
    {
        config(['training-videos.auto.scripts_dir' => resource_path('training/scripts')]);
        $this->app->forgetInstance(TrainingScriptRepository::class);

        return app(TrainingScriptRepository::class)->all();
    }

    public function test_every_main_screen_script_exists_passes_the_validator_and_records_as_the_agent(): void
    {
        $all = $this->all();
        $validator = new TrainingScriptValidator();
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $all);
            $s = $all[$key];
            $this->assertSame([], $validator->problems($s), $key . ' must pass the validator (plain words, why, two minutes)');
            $this->assertLessThanOrEqual(120, $validator->estimateSeconds($s), $key . ' is over two minutes');
            $this->assertTrue($s->approved(), $key . ' is approved so the batch picks it up');
            $this->assertFalse($s->narrated(), $key . ': Guide me stays off until the voice is agreed');
            $this->assertSame('agent', $s->loginAs(), $key . ' opens screens an agent can use');
            foreach ($s->steps() as $i => $step) {
                $this->assertNotSame('', trim((string) ($step['why'] ?? '')), $key . ' step ' . ($i + 1) . ' needs a why');
                $this->assertNotSame('', trim((string) ($step['done'] ?? '')), $key . ' step ' . ($i + 1) . ' needs a done-condition');
            }
        }
    }

    public function test_the_sample_data_is_invented_and_no_script_names_one_agency(): void
    {
        $all = $this->all();
        foreach (self::KEYS as $key) {
            $text = json_encode($all[$key]->data);
            $this->assertDoesNotMatchRegularExpression('/\bhfc\b|home finders|\bvaluation/i', $text, $key . ' must stay agency-neutral and say "evaluation", never "valuation"');
        }
    }

    public function test_no_two_scripts_claim_the_same_route_or_start_url(): void
    {
        $routes = $urls = [];
        foreach ($this->all() as $key => $s) {
            if ($s->route() !== null) {
                $this->assertArrayNotHasKey($s->route(), $routes, "{$key} and " . ($routes[$s->route()] ?? '') . ' share the route ' . $s->route());
                $routes[$s->route()] = $key;
            }
            $this->assertArrayNotHasKey($s->url(), $urls, "{$key} and " . ($urls[$s->url()] ?? '') . ' share the start url ' . $s->url());
            $urls[$s->url()] = $key;
        }
        foreach (['properties-photos', 'properties-status', 'contacts-create', 'contacts-link-property', 'calendar-view-diary', 'calendar-book-appointment', 'calendar-viewing-feedback', 'deals-work-orders'] as $key) {
            $this->assertNull($this->all()[$key]->route(), $key . ' shares its screen with another video, so the Watch how button must not guess: no route, a ?flow= url');
            $this->assertStringContainsString('?flow=', $this->all()[$key]->url());
        }
    }

    public function test_the_client_fica_video_opens_the_training_agencys_own_fixed_link(): void
    {
        $this->assertSame('/fica/' . TrainingAgencySeeder::FICA_LINK_TOKEN, $this->all()['fica-client-form']->url());
    }

    public function test_the_pipeline_list_carries_the_anchors_the_deal_scripts_point_at(): void
    {
        $blade = (string) file_get_contents(resource_path('views/dr2/pipeline-list.blade.php'));
        foreach (['structure', 'wo', 'docs', 'email', 'comms', 'pi', 'comments'] as $tab) {
            $this->assertStringContainsString('data-tour="pipe-tab-' . $tab . '"', $blade, 'tab ' . $tab);
        }
        $this->assertStringContainsString('data-tour="pipe-wo-panel"', $blade);
        $this->assertStringContainsString('pipe-tab-structure', json_encode($this->all()['deals-pipeline-list']->data));
        $this->assertStringContainsString('pipe-tab-wo', json_encode($this->all()['deals-work-orders']->data));
    }
}
