<?php

namespace Tests\Feature\TrainingVideos;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\TrainingVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-525 (spec .ai/specs/training-videos.md section 9) - the Training library, the authenticated file routes, Regenerate, the out-of-date flag and the Watch-how
 * button.
 */
final class TrainingLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->dir = sys_get_temp_dir() . '/tv-lib-' . uniqid();
        mkdir($this->dir);
        $this->script('book-a-viewing', 'Book a viewing', ['route' => 'command-center.calendar']);
        config(['training-videos.auto.scripts_dir' => $this->dir]);
        $this->app->forgetInstance(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);

        $agency = Agency::create(['name' => 'Lib', 'slug' => 'lib-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'HQ']);
        // grants must exist, otherwise the test suite's "nothing seeded = allow all" posture would let the agent do everything
        foreach (['admin' => ['training_videos.view', 'training_videos.generate'], 'agent' => ['training_videos.view']] as $role => $keys) {
            Role::create(['name' => $role, 'label' => ucfirst($role), 'agency_id' => $agency->id]);
            foreach ($keys as $k) {
                RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $k, 'agency_id' => $agency->id], []);
            }
        }
        $this->admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function script(string $key, string $title, array $over = []): void
    {
        $data = array_replace(['key' => $key, 'title' => $title, 'route' => null, 'url' => '/corex/x', 'approved' => true, 'related' => [],
            'steps' => [['selector' => 'a', 'action' => 'highlight', 'narration' => 'x', 'why' => 'y']]], $over);
        file_put_contents($this->dir . '/' . $key . '.php', '<?php return ' . var_export($data, true) . ';');
    }

    private function video(string $key = 'book-a-viewing', array $over = []): TrainingVideo
    {
        Storage::disk('local')->put('training-videos-auto/gen-x.mp4', 'MP4DATA');
        Storage::disk('local')->put('training-videos-auto/gen-x.jpg', 'JPGDATA');
        Storage::disk('local')->put('training-videos-auto/gen-x.vtt', "WEBVTT\n");

        return TrainingVideo::create(array_replace(['source' => 'generated', 'status' => 'ready', 'screen_key' => $key, 'title' => 'Book a viewing', 'file_name' => 'gen-x',
            'duration_seconds' => 84, 'generated_at' => now(), 'tts_voice' => 'fake', 'chapters' => [['at' => 7, 'title' => 'The diary']]], $over));
    }

    public function test_a_signed_in_agent_sees_the_library_with_the_video_and_no_generate_button(): void
    {
        $this->video();
        $html = $this->actingAs($this->agent)->get(route('corex.training-library.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-training-card="book-a-viewing"', $html);
        $this->assertStringContainsString('Watch (1:24)', $html);
        $this->assertStringNotContainsString('data-training-generate', $html);
    }

    public function test_a_script_without_a_video_is_hidden_from_an_agent_and_offered_to_a_generator(): void
    {
        $this->actingAs($this->agent)->get(route('corex.training-library.index'))->assertOk()->assertSee('data-training-empty', false);
        $html = $this->actingAs($this->admin)->get(route('corex.training-library.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-training-card="book-a-viewing"', $html);
        $this->assertStringContainsString('>Generate<', $html);
    }

    public function test_an_ordinary_agency_with_the_training_lms_switched_off_still_gets_the_library_and_the_video(): void
    {
        // Found 31 Oct on QA1: HFC has the Training (LMS) feature OFF and the two keys sit in the 'training' permission module, so every library URL 404'd for HFC
        // users (the feature gate refuses a permission whose owning feature is off) while the training-agency proof session, which has it on, got 200.
        // This agency is NOT a training agency (is_training is false) and has 'training' off and 'guided-tours' on.
        $this->assertFalse((bool) Agency::withoutGlobalScopes()->find($this->agent->agency_id)->is_training);
        AgencyFeature::create(['agency_id' => $this->agent->agency_id, 'feature_key' => 'training', 'enabled' => false]);
        AgencyFeature::create(['agency_id' => $this->agent->agency_id, 'feature_key' => 'guided-tours', 'enabled' => true]);
        $this->video();

        foreach (['index' => [], 'show' => ['book-a-viewing']] as $name => $args) {
            $this->actingAs($this->agent)->get(route('corex.training-library.' . $name, $args))->assertOk();
        }
        $this->actingAs($this->agent)->get(route('corex.training-library.file', ['book-a-viewing', 'mp4']))->assertOk();
        $this->actingAs($this->agent)->get(route('corex.training-library.file', ['book-a-viewing', 'vtt']))->assertOk();
        $html = $this->actingAs($this->agent)->get(route('corex.training-library.index'))->getContent();
        $this->assertStringContainsString('data-training-card="book-a-viewing"', $html);
        $this->actingAs($this->admin)->get(route('corex.training-library.index'))->assertOk();
    }

    public function test_switching_guided_tours_off_hides_the_library_like_the_rest_of_that_family(): void
    {
        AgencyFeature::create(['agency_id' => $this->agent->agency_id, 'feature_key' => 'guided-tours', 'enabled' => false]);
        $this->video();
        $this->actingAs($this->agent)->get(route('corex.training-library.index'))->assertNotFound();
    }

    public function test_the_files_are_streamed_only_to_signed_in_users_and_never_public(): void
    {
        $this->video();
        $this->get(route('corex.training-library.file', ['book-a-viewing', 'mp4']))->assertRedirect();
        $r = $this->actingAs($this->agent)->get(route('corex.training-library.file', ['book-a-viewing', 'mp4']));
        $r->assertOk();
        $this->assertSame('video/mp4', $r->headers->get('Content-Type'));
        $this->actingAs($this->agent)->get(route('corex.training-library.file', ['book-a-viewing', 'vtt']))->assertOk();
        $this->actingAs($this->agent)->get('/corex/training-library/book-a-viewing/file/exe')->assertNotFound();
        $this->assertSame([], TrainingVideo::query()->publiclyVisible()->pluck('id')->all());
    }

    public function test_the_audience_of_a_script_limits_who_may_watch_it(): void
    {
        $this->script('managers-only', 'Managers only', ['audience' => ['admin']]);
        $this->video('managers-only');
        $this->app->forgetInstance(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);

        $this->actingAs($this->agent)->get(route('corex.training-library.show', 'managers-only'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('corex.training-library.show', 'managers-only'))->assertOk();
    }

    public function test_the_out_of_date_flag_shows_when_the_script_changed_after_the_video_was_made(): void
    {
        $repo = app(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);
        $hash = (new \App\Services\TrainingVideos\Auto\SourceHasher())->hash($repo->find('book-a-viewing'));
        $this->video('book-a-viewing', ['source_hash' => $hash]);
        $this->actingAs($this->agent)->get(route('corex.training-library.show', 'book-a-viewing'))->assertOk()->assertDontSee('data-out-of-date', false);

        $this->script('book-a-viewing', 'Book a viewing (new wording)', ['route' => 'command-center.calendar']);
        $this->app->forgetInstance(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);
        $this->actingAs($this->agent)->get(route('corex.training-library.show', 'book-a-viewing'))->assertOk()->assertSee('data-out-of-date', false);
        $this->actingAs($this->agent)->get(route('corex.training-library.index'))->assertSee('data-out-of-date', false);
    }

    public function test_only_a_generator_can_regenerate_and_it_queues_the_job_on_the_training_lane(): void
    {
        $this->video();
        Bus::fake();

        $this->actingAs($this->agent)->post(route('corex.training-library.generate', 'book-a-viewing'))->assertForbidden();
        Bus::assertNothingDispatched();

        $this->actingAs($this->admin)->post(route('corex.training-library.generate', 'book-a-viewing'))->assertSessionHas('success');
        Bus::assertDispatched(\App\Jobs\TrainingVideos\GenerateTrainingVideoJob::class, fn ($j) => $j->screenKey === 'book-a-viewing' && $j->queue === 'training-videos' && $j->byUserId === $this->admin->id);
    }

    public function test_an_unapproved_script_cannot_be_generated(): void
    {
        $this->script('draft-one', 'Draft', ['approved' => false]);
        $this->app->forgetInstance(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);
        Bus::fake();
        $this->actingAs($this->admin)->post(route('corex.training-library.generate', 'draft-one'))->assertSessionHasErrors('training');
        Bus::assertNothingDispatched();
    }

    public function test_the_watch_how_button_shows_on_the_screen_that_has_a_video_and_nowhere_else(): void
    {
        $partial = 'layouts.partials.watch-how';
        $this->video();
        $route = new \Illuminate\Routing\Route('GET', '/corex/command-center/calendar', []);
        $route->name('command-center.calendar');
        request()->setRouteResolver(fn () => $route);
        $this->actingAs($this->agent);
        $with = view($partial)->render();
        $this->assertStringContainsString('data-watch-how="book-a-viewing"', $with);
        $this->assertStringContainsString('Watch how (1:24)', $with);
        $this->assertStringContainsString(route('corex.training-library.file', ['book-a-viewing', 'mp4']), $with);

        $other = new \Illuminate\Routing\Route('GET', '/corex/somewhere-else', []);
        $other->name('corex.somewhere-else');
        request()->setRouteResolver(fn () => $other);
        $this->assertStringNotContainsString('data-watch-how', view($partial)->render());
    }

    public function test_the_library_is_in_the_sidebar_and_the_permissions_exist(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));
        $this->assertStringContainsString("route('corex.training-library.index')", $sidebar);
        $keys = array_column(config('corex-permissions.permissions') ?? config('corex-permissions.keys') ?? [], 'key');
        if ($keys === []) {
            $this->assertStringContainsString("'training_videos.generate'", file_get_contents(config_path('corex-permissions.php')));
        } else {
            $this->assertContains('training_videos.view', $keys);
            $this->assertContains('training_videos.generate', $keys);
        }
    }
}
