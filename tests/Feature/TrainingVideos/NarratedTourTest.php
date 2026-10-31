<?php

namespace Tests\Feature\TrainingVideos;

use App\Jobs\TrainingVideos\MakeTourAudioJob;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\TrainingVideos\Auto\TourAudio;
use App\Services\TrainingVideos\Auto\TrainingScript;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Auto\TrainingScriptValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-526 (spec .ai/specs/training-videos.md 9.23) - the narrated guided tour: the extra script fields, the voice clips (one per step, re-made only when the words
 * change), the tour API (who may take it, what it returns, how the clips are served) and the "Guide me" button. The in-page player's done-condition advancing and its
 * off-path pause are proven in a real browser by scripts/guide-me-check.mjs (NarratedTourBrowserTest runs it).
 */
final class NarratedTourTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private User $agent;
    private User $noPerm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['training-videos.auto.tts.driver' => 'fake']);
        $this->dir = sys_get_temp_dir() . '/tv-tour-' . uniqid();
        mkdir($this->dir);
        $this->script('book-a-viewing', ['narrated' => true, 'route' => 'command-center.calendar']);
        $this->script('plain-video-only', ['narrated' => false]);
        $this->script('boss-only', ['narrated' => true, 'audience' => ['admin']]);
        config(['training-videos.auto.scripts_dir' => $this->dir]);
        $this->app->forgetInstance(TrainingScriptRepository::class);

        $agency = Agency::create(['name' => 'Tour', 'slug' => 'tour-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'HQ']);
        foreach (['agent' => ['training_tours.use', 'training_videos.view'], 'viewer' => ['training_videos.view']] as $role => $keys) {
            Role::create(['name' => $role, 'label' => ucfirst($role), 'agency_id' => $agency->id]);
            foreach ($keys as $k) {
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $k, 'agency_id' => $agency->id], []);
            }
        }
        $this->agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->noPerm = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'viewer']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function script(string $key, array $over = []): void
    {
        $data = array_replace([
            'key' => $key, 'title' => 'Book a viewing', 'route' => null, 'url' => '/corex/x', 'approved' => true, 'related' => [], 'audience' => [],
            'intro' => 'Here is how to book a viewing.', 'outro' => 'Well done.',
            'steps' => [
                ['selector' => '#add', 'action' => 'click', 'done' => 'click', 'narration' => 'Press Add Event.', 'why' => 'The viewing is tied to the buyer.'],
                ['selector' => '#title', 'action' => 'type', 'value' => 'Viewing with Bianca', 'done' => 'input', 'wait_for' => '#title', 'narration' => 'Type a name.', 'why' => 'Your team sees it in the diary.'],
                ['selector' => '#kind', 'action' => 'select', 'value' => 'viewing', 'narration' => 'Choose Viewing.', 'why' => 'Then we ask for feedback.'],
                ['action' => 'wait', 'narration' => 'Wait a moment.', 'why' => 'Video only.'],
                ['selector' => '#save', 'action' => 'highlight', 'narration' => 'Press Create Event.', 'why' => 'It saves.'],
            ],
        ], $over);
        file_put_contents($this->dir . '/' . $key . '.php', '<?php return ' . var_export($data, true) . ';');
    }

    private function repo(): TrainingScriptRepository
    {
        $this->app->forgetInstance(TrainingScriptRepository::class);

        return app(TrainingScriptRepository::class);
    }

    private function voice(): string
    {
        return app(\App\Services\TrainingVideos\Tts\TrainingVoices::class)->defaultKey();
    }

    public function test_a_step_without_a_done_condition_waits_for_what_its_action_naturally_ends_with(): void
    {
        $s = $this->repo()->find('book-a-viewing');
        $steps = collect($s->tourSteps())->keyBy('n');

        $this->assertSame('click', $steps[1]['done']);
        $this->assertSame('input', $steps[2]['done']);
        $this->assertSame('change', $steps[3]['done'], 'a select waits for the change');
        $this->assertFalse($steps->has(4), 'a video-only wait step is not part of the tour');
        $this->assertSame('none', $steps[5]['done'], 'a highlight is just read');
        $this->assertSame([1, 2, 3, 5], $steps->keys()->all(), 'the clip numbers stay the script step numbers');
        $this->assertSame('#title', $steps[2]['wait_for']);
        $this->assertSame(['appear', '#panel'], TrainingScript::doneOf(['action' => 'highlight', 'done' => 'appear:#panel']));
    }

    public function test_the_validator_checks_the_tour_fields_and_applies_the_plain_words_rule_to_the_tour_text(): void
    {
        $this->script('bad-done', ['steps' => [
            ['selector' => '#a', 'action' => 'highlight', 'done' => 'wave', 'narration' => 'Look.', 'why' => 'Because.'],
            ['selector' => '#b', 'action' => 'highlight', 'done' => 'appear', 'narration' => 'Look.', 'why' => 'Because.'],
            ['selector' => '#c', 'action' => 'highlight', 'narration' => 'Check your valuation here.', 'why' => 'Because.'],
        ]]);
        $problems = implode(' | ', (new TrainingScriptValidator())->problems($this->repo()->find('bad-done')));

        $this->assertStringContainsString("done 'wave' is not one of", $problems);
        $this->assertStringContainsString("done 'appear' needs something after the colon", $problems);
        $this->assertStringContainsString('"valuation" is a software word - say "evaluation"', $problems);
        $this->assertSame([], array_filter((new TrainingScriptValidator())->problems($this->repo()->find('book-a-viewing')), fn ($p) => str_contains($p, 'done')), 'a good script has no done problems');
    }

    public function test_the_two_pilot_scripts_pass_the_validator(): void
    {
        config(['training-videos.auto.scripts_dir' => resource_path('training/scripts')]);
        $repo = $this->repo();
        foreach (['book-a-viewing', 'agency-setup-identity'] as $key) {
            $s = $repo->find($key);
            $this->assertNotNull($s, $key);
            $this->assertTrue($s->narrated(), $key);
            $this->assertSame([], (new TrainingScriptValidator())->problems($s), $key . ' must pass the validator, plain words included');
        }
    }

    public function test_clips_are_made_once_per_step_and_remade_only_when_the_words_change(): void
    {
        $audio = app(TourAudio::class);
        $s = $this->repo()->find('book-a-viewing');
        $voice = $this->voice();

        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $audio->missing($s, $voice), 'intro, five steps, outro');
        $made = $audio->make($s, $voice);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $made);
        $this->assertTrue($audio->ready($s, $voice));
        $this->assertSame([], $audio->make($s, $voice), 'a second run makes nothing');
        $before = $audio->pathFor($s, 1, $voice);
        $this->assertStringEndsWith('.mp3', $before);
        $this->assertTrue(Storage::disk('local')->exists($before));
        $this->assertGreaterThan(100, Storage::disk('local')->size($before), 'a real mp3, not an empty file');
        $this->assertTrue(Storage::disk('local')->exists(dirname($before) . '/manifest.json'));

        // one line changes -> only that clip is out of date, and the stale file goes
        $this->script('book-a-viewing', ['narrated' => true, 'route' => 'command-center.calendar', 'steps' => [
            ['selector' => '#add', 'action' => 'click', 'done' => 'click', 'narration' => 'Press Add Event now.', 'why' => 'The viewing is tied to the buyer.'],
            ['selector' => '#title', 'action' => 'type', 'value' => 'Viewing with Bianca', 'done' => 'input', 'narration' => 'Type a name.', 'why' => 'Your team sees it in the diary.'],
            ['selector' => '#kind', 'action' => 'select', 'value' => 'viewing', 'narration' => 'Choose Viewing.', 'why' => 'Then we ask for feedback.'],
            ['action' => 'wait', 'narration' => 'Wait a moment.', 'why' => 'Video only.'],
            ['selector' => '#save', 'action' => 'highlight', 'narration' => 'Press Create Event.', 'why' => 'It saves.'],
        ]]);
        $s2 = $this->repo()->find('book-a-viewing');
        $this->assertSame([1], $audio->missing($s2, $voice), 'only the changed step needs a new clip');
        $this->assertNull($audio->pathFor($s2, 1, $voice));
        $audio->make($s2, $voice);
        $this->assertFalse(Storage::disk('local')->exists($before), 'the old clip is removed');
        $this->assertTrue($audio->ready($s2, $voice));
    }

    public function test_the_tour_api_is_closed_to_anyone_who_may_not_take_the_tour(): void
    {
        $this->getJson(route('api.v1.training-tours.show', 'book-a-viewing'))->assertUnauthorized();
        $this->actingAs($this->noPerm)->getJson(route('api.v1.training-tours.show', 'book-a-viewing'))->assertForbidden();
        $this->actingAs($this->agent)->getJson(route('api.v1.training-tours.show', 'plain-video-only'))->assertNotFound();   // not marked narrated
        $this->actingAs($this->agent)->getJson(route('api.v1.training-tours.show', 'no-such-screen'))->assertNotFound();
        $this->actingAs($this->agent)->getJson(route('api.v1.training-tours.show', 'boss-only'))->assertNotFound();            // audience: admin
    }

    public function test_the_agency_can_switch_narrated_tours_off_and_the_button_goes_with_it(): void
    {
        AgencyFeature::create(['agency_id' => $this->agent->agency_id, 'feature_key' => 'narrated-tours', 'enabled' => false]);
        $this->actingAs($this->agent)->getJson(route('api.v1.training-tours.show', 'book-a-viewing'))->assertNotFound();   // a switched-off feature answers like a screen that does not exist
        $this->assertStringNotContainsString('data-guide-me', $this->renderGuideMe());
    }

    public function test_the_api_returns_the_steps_with_done_target_and_a_clip_for_each_once_the_clips_exist(): void
    {
        Bus::fake();
        Cache::flush();
        $res = $this->actingAs($this->agent)->getJson(route('api.v1.training-tours.show', 'book-a-viewing'))->assertOk();
        $res->assertJsonPath('key', 'book-a-viewing')->assertJsonPath('title', 'Book a viewing')->assertJsonPath('audio_ready', false);
        $this->assertCount(4, $res->json('steps'));
        $this->assertSame([1, 2, 3, 5], array_column($res->json('steps'), 'n'));
        $this->assertSame('input', $res->json('steps.1.done'));
        $this->assertSame('#title', $res->json('steps.1.wait_for'));
        $this->assertNull($res->json('steps.0.audio_url'), 'no clip yet: the player shows words and never waits for sound');
        Bus::assertDispatched(MakeTourAudioJob::class);

        app(TourAudio::class)->make($this->repo()->find('book-a-viewing'), $this->voice());
        Bus::fake();
        $res = $this->actingAs($this->agent)->getJson(route('api.v1.training-tours.show', 'book-a-viewing'))->assertOk();
        $this->assertTrue($res->json('audio_ready'));
        $this->assertNotNull($res->json('steps.0.audio_url'));
        $this->assertNotNull($res->json('intro.audio_url'));
        $this->assertNotNull($res->json('outro.audio_url'));
        $this->assertSame('Here is how to book a viewing.', $res->json('intro.narration'));
        Bus::assertNotDispatched(MakeTourAudioJob::class);
    }

    public function test_a_clip_is_streamed_to_a_signed_in_user_as_audio_with_range_support_and_to_nobody_else(): void
    {
        app(TourAudio::class)->make($this->repo()->find('book-a-viewing'), $this->voice());
        $url = $this->actingAs($this->agent)->getJson(route('api.v1.training-tours.show', 'book-a-viewing'))->json('steps.0.audio_url');

        $ok = $this->actingAs($this->agent)->get($url)->assertOk();
        $this->assertSame('audio/mpeg', $ok->headers->get('Content-Type'));
        $range = $this->actingAs($this->agent)->withHeaders(['Range' => 'bytes=0-9'])->get($url);
        $this->assertSame(206, $range->getStatusCode());
        $size = Storage::disk('local')->size(app(TourAudio::class)->pathFor($this->repo()->find('book-a-viewing'), 1, $this->voice()));
        $this->assertSame('bytes 0-9/' . $size, $range->headers->get('Content-Range'));

        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect();                                           // signed out: never served
        $this->actingAs($this->noPerm)->get($url)->assertForbidden();
        $this->actingAs($this->agent)->get(route('api.v1.training-tours.audio', ['screen' => 'book-a-viewing', 'n' => 1, 'voice' => '../../etc']))->assertNotFound();
        $this->actingAs($this->agent)->get(route('api.v1.training-tours.audio', ['screen' => 'book-a-viewing', 'n' => 99, 'voice' => $this->voice()]))->assertNotFound();
        $this->actingAs($this->agent)->get(route('api.v1.training-tours.audio', ['screen' => 'plain-video-only', 'n' => 1, 'voice' => $this->voice()]))->assertNotFound();
    }

    public function test_the_artisan_command_makes_the_clips_for_narrated_screens_only_and_can_just_check(): void
    {
        $this->artisan('training-videos:tour-audio', ['--all' => true, '--check' => true])->assertSuccessful();
        $this->assertFalse(app(TourAudio::class)->ready($this->repo()->find('book-a-viewing'), $this->voice()), 'check makes nothing');

        $this->artisan('training-videos:tour-audio', ['--all' => true])->assertSuccessful();
        $this->assertTrue(app(TourAudio::class)->ready($this->repo()->find('book-a-viewing'), $this->voice()));
        $this->assertFalse(app(TourAudio::class)->ready($this->repo()->find('plain-video-only'), $this->voice()), 'a screen that is not narrated gets no clips');
    }

    public function test_the_guide_me_button_shows_only_where_the_tour_is_allowed(): void
    {
        $this->actingAs($this->agent);
        $html = $this->renderGuideMe();
        $this->assertStringContainsString('data-guide-me="book-a-viewing"', $html);
        $this->assertStringContainsString('Guide me', $html);
        $this->assertStringContainsString('/api/v1/training-tours/book-a-viewing', $html);

        $this->actingAs($this->noPerm);
        $this->assertStringNotContainsString('data-guide-me', $this->renderGuideMe(), 'no permission, no button');

        $this->actingAs($this->agent);
        $other = new \Illuminate\Routing\Route('GET', '/corex/somewhere-else', []);
        $other->name('corex.somewhere-else');
        request()->setRouteResolver(fn () => $other);
        $this->assertStringNotContainsString('data-guide-me', view('layouts.partials.guide-me')->render());
    }

    private function renderGuideMe(): string
    {
        $route = new \Illuminate\Routing\Route('GET', '/corex/command-center/calendar', []);
        $route->name('command-center.calendar');
        request()->setRouteResolver(fn () => $route);

        return view('layouts.partials.guide-me')->render();
    }
}
