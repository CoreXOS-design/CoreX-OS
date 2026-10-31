<?php

namespace Tests\Feature\TrainingVideos;

use App\Models\TrainingVideo;
use App\Services\TrainingVideos\Auto\SourceHasher;
use App\Services\TrainingVideos\Auto\TrainingScript;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Auto\TrainingScriptValidator;
use App\Services\TrainingVideos\Tts\FakeTtsProvider;
use App\Services\TrainingVideos\Tts\PiperTtsProvider;
use App\Services\TrainingVideos\Tts\TtsManager;
use App\Services\TrainingVideos\Tts\WavDuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AT-525 (spec .ai/specs/training-videos.md section 9) - the script format, its validation, the out-of-date hash and the swappable voice.
 */
final class AutoVideoScriptTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/tv-scripts-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function write(string $key, array $over = []): TrainingScript
    {
        $data = array_replace_recursive([
            'key' => $key, 'title' => 'Book a viewing', 'route' => 'command-center.calendar', 'url' => '/corex/command-center/calendar', 'approved' => true,
            'intro' => 'Welcome.', 'outro' => 'That is it.',
            'steps' => [
                ['selector' => '[data-tour="cal-cockpit"]', 'action' => 'highlight', 'narration' => 'This is the calendar.', 'why' => 'Every viewing you book lives here and feeds the buyer feedback.'],
                ['selector' => '[data-tour="cal-views"]', 'action' => 'click', 'narration' => 'Switch to week view.', 'why' => 'It shows the days you are out with buyers.'],
            ],
        ], $over);
        if (isset($over['steps'])) {
            $data['steps'] = $over['steps'];   // a replaced step list is replaced whole, not merged into the default one
        }
        $path = $this->dir . '/' . $key . '.php';
        file_put_contents($path, '<?php return ' . var_export($data, true) . ';');

        return new TrainingScript($data, $path);
    }

    public function test_a_good_script_has_no_problems(): void
    {
        $this->assertSame([], (new TrainingScriptValidator())->problems($this->write('book-a-viewing')));
    }

    public function test_every_step_must_say_why_it_matters(): void
    {
        $s = $this->write('no-why', ['steps' => [['selector' => 'a', 'action' => 'highlight', 'narration' => 'x', 'why' => 'y'], ['selector' => 'b', 'action' => 'highlight', 'narration' => 'x', 'why' => '']]]);
        $p = (new TrainingScriptValidator())->problems($s);
        $this->assertStringContainsString('Step 2: say WHY it matters', implode("\n", $p));
    }

    public function test_bad_actions_missing_selectors_missing_routes_and_file_name_mismatch_are_plain_sentences(): void
    {
        $s = $this->write('bad-one', ['route' => 'no.such.route', 'steps' => [['action' => 'dance', 'narration' => 'x', 'why' => 'y'], ['action' => 'click', 'narration' => 'x', 'why' => 'y']]]);
        $text = implode("\n", (new TrainingScriptValidator())->problems($s));
        $this->assertStringContainsString("route 'no.such.route' does not exist", $text);
        $this->assertStringContainsString("action 'dance'", $text);
        $this->assertStringContainsString("'click' needs a selector", $text);
        $renamed = new TrainingScript($s->data, $this->dir . '/other-name.php');
        $this->assertStringContainsString('file name must match the key', implode("\n", (new TrainingScriptValidator())->problems($renamed)));
    }

    public function test_a_video_over_the_two_minute_cap_is_refused_before_anything_is_recorded(): void
    {
        $long = str_repeat('This sentence is here to make the narration long. ', 40);
        $s = $this->write('too-long', ['steps' => [['narration' => $long]]]);
        $this->assertStringContainsString('over the 120 second limit', implode("\n", (new TrainingScriptValidator())->problems($s)));
    }

    public function test_spoken_text_is_what_to_do_then_why(): void
    {
        $this->assertSame('Do it. Because.', TrainingScript::spoken(['narration' => 'Do it.', 'why' => 'Because.']));
        $s = $this->write('lines');
        $this->assertSame('Welcome.', $s->spokenLines()[0]);
        $this->assertSame('That is it.', array_values(array_slice($s->spokenLines(), -1))[0]);
    }

    public function test_the_repository_finds_scripts_by_key_and_by_route(): void
    {
        $this->write('book-a-viewing');
        $repo = new TrainingScriptRepository($this->dir);
        $this->assertSame(['book-a-viewing'], array_keys($repo->all()));
        $this->assertNotNull($repo->find('book-a-viewing'));
        $this->assertSame('book-a-viewing', $repo->forRoute('command-center.calendar')->key());
        $this->assertNull($repo->forRoute('nope'));
    }

    public function test_the_hash_changes_when_the_script_a_spec_or_a_tour_changes_and_the_video_is_flagged_out_of_date(): void
    {
        $hasher = new SourceHasher();
        $a = $this->write('hash-me', ['specs' => ['.ai/specs/training-videos.md'], 'tour' => 'calendar']);
        $h1 = $hasher->hash($a);

        $video = new TrainingVideo(['source' => 'generated', 'source_hash' => $h1]);
        $this->assertFalse($hasher->isOutOfDate($video, $a));

        $b = $this->write('hash-me', ['specs' => ['.ai/specs/training-videos.md'], 'tour' => 'calendar', 'title' => 'A different title']);
        $this->assertNotSame($h1, $hasher->hash($b));
        $this->assertTrue($hasher->isOutOfDate($video, $b), 'an edited script is flagged');

        // a changed spec
        $spec = base_path('.ai/specs/tv-hash-test.md');
        file_put_contents($spec, 'v1');
        try {
            $c = $this->write('hash-me', ['specs' => ['.ai/specs/tv-hash-test.md']]);
            $hc = $hasher->hash($c);
            file_put_contents($spec, 'v2');
            $this->assertNotSame($hc, $hasher->hash($c), 'an edited spec is flagged');
        } finally {
            @unlink($spec);
        }
        $this->assertFalse($hasher->isOutOfDate(new TrainingVideo(['source_hash' => null]), $a), 'a video with no recorded hash is never flagged');
    }

    public function test_the_fake_voice_writes_a_real_wav_of_the_right_length_and_the_manager_picks_it(): void
    {
        config(['training-videos.auto.tts.driver' => 'fake']);
        $voice = (new TtsManager())->provider();
        $this->assertInstanceOf(FakeTtsProvider::class, $voice);
        $out = sys_get_temp_dir() . '/tv-fake-' . uniqid() . '.wav';
        $secs = $voice->synthesize(str_repeat('word ', 25), $out);
        $this->assertEqualsWithDelta(10.0, $secs, 0.1);
        $this->assertEqualsWithDelta(10.0, WavDuration::of($out), 0.1);
        @unlink($out);
    }

    public function test_an_unknown_voice_driver_is_refused(): void
    {
        config(['training-videos.auto.tts.driver' => 'nope']);
        $this->expectException(\InvalidArgumentException::class);
        (new TtsManager())->provider();
    }

    public function test_the_real_piper_voice_speaks_when_it_is_installed_on_this_box(): void
    {
        $cfg = (array) config('training-videos.auto.tts.piper');
        if (! is_file($cfg['python'] ?? '') || ! is_file(($cfg['data_dir'] ?? '') . '/' . ($cfg['voice'] ?? '') . '.onnx')) {
            $this->markTestSkipped('Piper is not installed here.');
        }
        $out = sys_get_temp_dir() . '/tv-piper-' . uniqid() . '.wav';
        $secs = (new PiperTtsProvider($cfg))->synthesize('Booking a viewing links the buyer to the property.', $out);
        $this->assertGreaterThan(2.0, $secs);
        $this->assertLessThan(10.0, $secs);
        @unlink($out);
    }

    public function test_generated_videos_never_appear_on_the_public_page_scope(): void
    {
        TrainingVideo::create(['source' => 'generated', 'status' => 'ready', 'title' => 'Private how-to', 'screen_key' => 'x']);
        TrainingVideo::create(['source' => 'youtube', 'status' => 'ready', 'title' => 'Public', 'youtube_url' => 'https://youtu.be/abcdefghijk', 'youtube_video_id' => 'abcdefghijk']);
        $this->assertSame(['Public'], TrainingVideo::query()->publiclyVisible()->pluck('title')->all());
    }
}
