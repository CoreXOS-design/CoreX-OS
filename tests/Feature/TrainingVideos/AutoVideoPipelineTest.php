<?php

namespace Tests\Feature\TrainingVideos;

use App\Models\Agency;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\TrainingVideo;
use App\Models\User;
use App\Services\TrainingVideos\Auto\TrainingAgencySeeder;
use App\Services\TrainingVideos\Auto\TrainingScript;
use App\Services\TrainingVideos\Auto\TrainingSessionService;
use App\Services\TrainingVideos\Auto\TrainingVideoGenerator;
use App\Services\TrainingVideos\Auto\VideoAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * AT-525 (spec .ai/specs/training-videos.md section 9) - the training agency, the training-only sign-in, and the whole make-a-video pipeline with the browser
 * replaced by a stand-in that writes a real (test-pattern) video and a timeline; the voice is the silent fake, ffmpeg is the real one.
 */
final class AutoVideoPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['training-videos.auto.tts.driver' => 'fake', 'training-videos.auto.work_dir' => sys_get_temp_dir() . '/tv-work-' . uniqid()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('training-videos.auto.work_dir'));
        parent::tearDown();
    }

    private function script(array $over = []): TrainingScript
    {
        return new TrainingScript(array_replace([
            'key' => 'book-a-viewing', 'title' => 'Book a viewing', 'route' => 'command-center.calendar', 'url' => '/corex/command-center/calendar', 'approved' => true,
            'intro' => 'In this video you book a viewing.', 'outro' => 'Next, capture the buyer feedback.',
            'steps' => [
                ['selector' => '[data-tour="cal-cockpit"]', 'action' => 'highlight', 'narration' => 'This is your calendar.', 'why' => 'Every viewing lives here and feeds the buyer feedback.'],
                ['selector' => '[data-tour="cal-views"]', 'action' => 'click', 'narration' => 'Switch to week view.', 'why' => 'It shows the days you are out with buyers.'],
            ],
        ], $over));
    }

    /** A stand-in for the browser: writes a real 14-second test video and the timeline the recorder would. */
    private function fakeRecorder(?float $total = null): \Closure
    {
        return function (array $job) use ($total): array {
            $p = new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=10:duration=14', '-pix_fmt', 'yuv420p', $job['out']]);
            $p->run();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
            $t = 0.0;
            $tl = ['intro' => ['start' => 0.0, 'end' => $job['intro']['seconds']], 'steps' => [], 'outro' => null];
            $t = $job['intro']['seconds'];
            foreach ($job['steps'] as $i => $s) {
                $tl['steps'][] = ['i' => $i, 'start' => $t, 'end' => $t + $s['seconds'], 'found' => true];
                $t += $s['seconds'];
            }
            $tl['outro'] = ['start' => $t, 'end' => $t + $job['outro']['seconds']];
            $tl['total'] = $total ?? 14.0;

            return $tl;
        };
    }

    public function test_the_training_agency_is_seeded_with_invented_data_and_is_idempotent(): void
    {
        $r = app(TrainingAgencySeeder::class)->seed();
        $a = $r['agency'];
        $this->assertTrue((bool) $a->is_training);
        $this->assertSame('corex-training-academy', $a->slug);
        $this->assertCount(3, $r['users']);
        foreach ($r['users'] as $u) {
            $this->assertStringEndsWith('@training.corexos.invalid', $u->email, 'no address that could ever receive real mail');
        }
        $this->assertGreaterThanOrEqual(6, Property::withoutGlobalScope(AgencyScope::class)->where('agency_id', $a->id)->count());
        $this->assertSame(1, DB::table('calendar_events')->where('agency_id', $a->id)->where('event_type', 'viewing')->count());

        app(TrainingAgencySeeder::class)->seed();
        $this->assertSame(1, Agency::withoutGlobalScopes()->where('is_training', true)->count());
        $this->assertSame(1, DB::table('calendar_events')->where('agency_id', $a->id)->count(), 'seeding again adds nothing');

        app(TrainingAgencySeeder::class)->reset();
        $this->assertSame(1, DB::table('calendar_events')->where('agency_id', $a->id)->count(), 'reset puts the demo rows back, once');
    }

    public function test_the_recorder_can_only_sign_in_as_a_training_agency_user(): void
    {
        $r = app(TrainingAgencySeeder::class)->seed();
        $sessions = app(TrainingSessionService::class);

        $cookie = $sessions->issue($r['users']['agent']);
        $this->assertNotSame('', $cookie['value']);
        $this->assertSame($r['users']['agent']->id, $cookie['user_id']);
        $this->assertSame($r['users']['manager']->id, $sessions->userFor('manager')->id);

        $real = Agency::create(['name' => 'Real Agency', 'slug' => 'real-' . uniqid()]);
        $stranger = User::withoutAgencyStamping(fn () => User::factory()->create(['agency_id' => $real->id, 'role' => 'agent']));
        $this->assertSame($real->id, (int) $stranger->agency_id);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only ever recorded as a user of the training agency');
        $sessions->issue($stranger);
    }

    public function test_a_screen_script_becomes_a_ready_private_video_with_captions_chapters_and_a_hash(): void
    {
        app(TrainingAgencySeeder::class)->seed();

        $v = app(TrainingVideoGenerator::class)->generate($this->script(), null, $this->fakeRecorder());

        $this->assertSame('ready', $v->status);
        $this->assertSame('generated', $v->source);
        $this->assertSame('book-a-viewing', $v->screen_key);
        $this->assertSame('fake', $v->tts_voice);
        $this->assertNotNull($v->source_hash);
        $this->assertNotNull($v->generated_at);
        $this->assertCount(2, $v->chapters);
        $this->assertSame(640, $v->width);
        $this->assertGreaterThan(8, $v->duration_seconds);
        $disk = Storage::disk('local');
        foreach (['mp4', 'jpg', 'vtt'] as $ext) {
            $disk->assertExists('training-videos-auto/' . $v->file_name . '.' . $ext);
        }
        $vtt = $disk->get('training-videos-auto/' . $v->captions_file);
        $this->assertStringStartsWith('WEBVTT', $vtt);
        $this->assertStringContainsString('feeds the buyer feedback.', str_replace("\n", ' ', $vtt), 'the WHY is in the captions (wrapped across lines)');
        $this->assertSame([], TrainingVideo::query()->publiclyVisible()->pluck('id')->all(), 'never on the public page');
        $this->assertDirectoryDoesNotExist((string) config('training-videos.auto.work_dir') . '/book-a-viewing-' . now()->format('Ymd'), 'working files are removed');
    }

    public function test_the_recorders_clock_is_mapped_onto_the_videos_own_length(): void
    {
        app(TrainingAgencySeeder::class)->seed();
        // the recorder says it ran 28 s but the screencast file is only 14 s long: every cue moves to half its time
        $v = app(TrainingVideoGenerator::class)->generate($this->script(), null, $this->fakeRecorder(28.0));

        $vtt = Storage::disk('local')->get('training-videos-auto/' . $v->captions_file);
        $this->assertStringContainsString('00:00:00.150 -->', $vtt, 'the intro line (0.3 s on the recorder clock) starts at 0.15 s of the video');
    }

    public function test_regenerating_replaces_the_files_and_a_failed_regeneration_keeps_the_old_video_playing(): void
    {
        app(TrainingAgencySeeder::class)->seed();
        $gen = app(TrainingVideoGenerator::class);
        $first = $gen->generate($this->script(), null, $this->fakeRecorder());
        $old = $first->file_name;

        $second = $gen->generate($this->script(), null, $this->fakeRecorder());
        $this->assertSame($first->id, $second->id, 'the same row');
        $this->assertNotSame($old, $second->file_name);
        Storage::disk('local')->assertMissing('training-videos-auto/' . $old . '.mp4');
        Storage::disk('local')->assertExists('training-videos-auto/' . $second->file_name . '.mp4');

        try {
            $gen->generate($this->script(), null, fn () => throw new \RuntimeException('The recorder failed: boom'));
            $this->fail('expected the failure');
        } catch (\RuntimeException) {
        }
        $kept = $second->fresh();
        $this->assertSame('ready', $kept->status, 'the old video still plays');
        $this->assertSame($second->file_name, $kept->file_name);
        $this->assertSame('The recorder failed: boom', $kept->failure_reason);
        Storage::disk('local')->assertExists('training-videos-auto/' . $kept->file_name . '.mp4');
    }

    public function test_a_first_failure_marks_the_new_video_failed_with_the_reason(): void
    {
        app(TrainingAgencySeeder::class)->seed();
        try {
            app(TrainingVideoGenerator::class)->generate($this->script(), null, fn () => throw new \RuntimeException('The screen no longer matches the script: step(s) 2 could not find their element.'));
        } catch (\RuntimeException) {
        }
        $v = TrainingVideo::where('screen_key', 'book-a-viewing')->first();
        $this->assertSame('failed', $v->status);
        $this->assertStringContainsString('could not find their element', $v->failure_reason);
    }

    public function test_an_invalid_script_is_refused_before_anything_is_recorded(): void
    {
        app(TrainingAgencySeeder::class)->seed();
        $bad = $this->script(['steps' => [['selector' => 'a', 'action' => 'highlight', 'narration' => 'x', 'why' => '']]]);
        $called = false;
        try {
            app(TrainingVideoGenerator::class)->generate($bad, null, function () use (&$called) { $called = true; return []; });
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('say WHY it matters', $e->getMessage());
        }
        $this->assertFalse($called);
        $this->assertSame(0, TrainingVideo::count());
    }

    public function test_subtitles_are_written_as_srt_and_webvtt(): void
    {
        $a = new VideoAssembler();
        $cues = [['file' => 'x', 'start' => 1.5, 'end' => 4.25, 'text' => 'Click Add Event.']];
        $this->assertStringContainsString("1\n00:00:01,500 --> 00:00:04,250\nClick Add Event.", $a->subtitles($cues, false));
        $this->assertStringContainsString("WEBVTT\n\n00:00:01.500 --> 00:00:04.250\nClick Add Event.", $a->subtitles($cues, true));
    }

    public function test_the_generate_command_validates_a_script_without_recording(): void
    {
        $dir = sys_get_temp_dir() . '/tv-cmd-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/book-a-viewing.php', '<?php return ' . var_export($this->script()->data, true) . ';');
        config(['training-videos.auto.scripts_dir' => $dir]);
        $this->app->forgetInstance(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);

        $this->artisan('training-videos:generate', ['key' => 'book-a-viewing', '--check' => true])->expectsOutputToContain('book-a-viewing: ok')->assertExitCode(0);
        $this->artisan('training-videos:check-outdated')->expectsOutputToContain('no video yet')->assertExitCode(0);
        File::deleteDirectory($dir);
    }
}
