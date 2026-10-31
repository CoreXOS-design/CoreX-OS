<?php

namespace Tests\Feature\TrainingVideos;

use App\Events\TrainingVideos\AdRenderFinished;
use App\Jobs\TrainingVideos\RenderAdJob;
use App\Models\AdRender;
use App\Services\TrainingVideos\Ads\AdCaptions;
use App\Services\TrainingVideos\Ads\AdComposer;
use App\Services\TrainingVideos\Ads\AdFormat;
use App\Services\TrainingVideos\Ads\AdRenderer;
use App\Services\TrainingVideos\Ads\AdRequestInvalid;
use App\Services\TrainingVideos\Ads\AdRequestValidator;
use App\Services\TrainingVideos\Auto\TrainingAgencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-525 / AT-527 (spec 9.16.1 / 9.16.2) - the render side of Ad Studio's video ads: the request check, the batch + status door, the formats and safe zones, and a real render
 * (ffmpeg + headless Chromium for the cards) with a fake recorder and a silent voice.
 */
final class AdRenderTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dir = sys_get_temp_dir() . '/tv-ad-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/book-a-viewing.php', '<?php return ' . var_export(['key' => 'book-a-viewing', 'title' => 'Book', 'route' => null, 'url' => '/corex/x', 'approved' => true, 'related' => [],
            'steps' => [['selector' => '[data-x="a"]', 'action' => 'highlight', 'narration' => 'x', 'why' => 'y'], ['selector' => '[data-x="b"]', 'action' => 'click', 'narration' => 'x', 'why' => 'y']]], true) . ';');
        config(['training-videos.auto.scripts_dir' => $this->dir, 'training-videos.auto.tts.driver' => 'fake', 'training-videos.auto.work_dir' => $this->dir . '/work']);
        $this->app->forgetInstance(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function request(array $over = []): array
    {
        return array_replace_recursive([
            'ad_ref' => 7, 'length' => 30, 'formats' => ['9:16', '1:1'], 'voice' => null, 'captions' => true, 'music' => null,
            'script' => [
                'hook' => ['text' => 'Still guessing which owners to phone?', 'accent' => 'guessing', 'seconds' => 3],
                'beats' => [
                    ['benefit' => 'See who to phone first', 'screen' => ['script_key' => 'book-a-viewing', 'step' => 0], 'zoom' => ['scale' => 1.4], 'seconds' => 4],
                    ['benefit' => 'One click opens everything we know', 'screen' => ['script_key' => 'book-a-viewing', 'step' => 1], 'zoom' => ['scale' => 1.8], 'seconds' => 4],
                ],
                'proof' => ['text' => 'HFC runs on CoreX, and we keep expanding it', 'seconds' => 2.5],
                'cta' => ['line' => 'Join CoreX', 'follow' => 'Follow us', 'sub' => 'Run your agency on CoreX'],
            ],
            'brand' => ['name' => 'CoreX OS', 'logo' => 'images/corex-os-logo.svg', 'colours' => ['primary' => '#0b2a4a', 'secondary' => '#0e5a7a', 'accent' => '#33c4e0', 'text' => '#ffffff'],
                'website' => 'https://corexos.co.za', 'contact_email' => 'johan@corexos.co.za', 'phone' => '+27 76 618 5578', 'join_line' => 'Join CoreX', 'cta_line' => 'Run your agency on CoreX',
                'tagline' => 'HFC runs on CoreX, and we keep expanding it.', 'socials' => ['facebook' => 'https://facebook.com/corexos']],
        ], $over);
    }

    public function test_a_good_request_passes_and_the_renderers_own_rules_catch_the_rest(): void
    {
        $v = app(AdRequestValidator::class);
        $this->assertSame([], $v->problems($this->request()));

        $bad = $this->request(['script' => ['hook' => ['text' => 'We are building the best tile panel for you today in the market right now okay']]]);
        $text = implode("\n", $v->problems($bad));
        $this->assertStringContainsString('over 12 words', $text);
        $this->assertStringContainsString('never say CoreX is being built', $text);
        $this->assertStringContainsString('"tile" is a software word', $text);

        $none = $this->request();
        $none['script']['beats'] = [];
        $this->assertStringContainsString('1 to 8 shots', implode(' ', $v->problems($none)));
        $r = $this->request();
        $r['script']['beats'][0]['screen'] = ['script_key' => 'nope', 'step' => 0];
        $r['script']['beats'][1]['screen']['step'] = 9;
        $r['script']['beats'][1]['seconds'] = 9;
        $r['length'] = 20;
        $r['formats'] = ['4:3'];
        $t = implode("\n", $v->problems($r));
        foreach (['not a known training script', 'has no step number 10', '2 to 6 seconds', 'length must be 15, 30 or 60', 'Choose one or more formats'] as $needle) {
            $this->assertStringContainsString($needle, $t);
        }
        $long = $this->request(['length' => 15]);
        $this->assertStringContainsString('over the 15 seconds chosen', implode(' ', $v->problems($long)));
    }

    public function test_queue_makes_one_row_per_format_dispatches_once_and_returns_the_same_ad_when_it_is_already_ready(): void
    {
        Bus::fake();
        $r = app(AdRenderer::class);
        $batch = $r->queue($this->request());
        $this->assertSame(['1:1', '9:16'], AdRender::where('batch_id', $batch)->orderBy('format')->pluck('format')->all());
        $this->assertSame(7, app(AdRenderer::class)->status($batch)['ad_ref']);
        $this->assertSame('queued', $r->status($batch)['status']);
        Bus::assertDispatchedTimes(RenderAdJob::class, 1);

        AdRender::where('batch_id', $batch)->update(['status' => 'ready', 'file_name' => 'x.mp4', 'poster_name' => 'x.jpg', 'duration_seconds' => 20, 'width' => 1080, 'height' => 1920, 'size_bytes' => 9]);
        $again = $r->queue($this->request(['ad_ref' => 99]));   // same ad under another id: nothing is rendered twice
        $this->assertSame($batch, $again);
        Bus::assertDispatchedTimes(RenderAdJob::class, 1);
        $s = $r->status($batch);
        $this->assertSame('ready', $s['status']);
        $this->assertEqualsCanonicalizing(['9:16', '1:1'], array_keys($s['files']));
        $this->assertSame('x.jpg', $s['files']['9:16']['poster']);

        $changed = $r->queue($this->request(['script' => ['hook' => ['text' => 'Still guessing which owners to call?']]]));
        $this->assertNotSame($batch, $changed, 'a changed script is a new render');
    }

    public function test_an_invalid_request_is_refused_with_plain_sentences_and_nothing_is_queued(): void
    {
        Bus::fake();
        try {
            app(AdRenderer::class)->queue($this->request(['length' => 20]));
            $this->fail('expected AdRequestInvalid');
        } catch (AdRequestInvalid $e) {
            $this->assertStringContainsString('length must be', $e->problems[0]);
        }
        $this->assertSame(0, AdRender::count());
        Bus::assertNothingDispatched();
    }

    public function test_nine_by_sixteen_captions_sit_inside_the_safe_zone(): void
    {
        $s = AdFormat::get('9:16');
        $ass = (new AdCaptions())->ass([['start' => 1.0, 'end' => 3.5, 'text' => 'One click opens everything we know about the house today']], $s);
        $this->assertStringContainsString('PlayResX: 1080', $ass);
        $this->assertStringContainsString('PlayResY: 1920', $ass);
        // bottom of the captions is at 78% of the height (22% margin), with 6% side margins; wrapped to a readable width
        $this->assertMatchesRegularExpression('/,2,65,65,422,1/', $ass);
        $this->assertStringContainsString('Dialogue: 0,0:00:01.00,0:00:03.50,Cap', $ass);
        $this->assertStringContainsString('\\N', $ass);
        $w = AdFormat::window('9:16', 1.0);
        $this->assertSame(1080, $w['h']);
        $this->assertLessThanOrEqual(608, $w['w']);
        $this->assertSame([1080, 1080], array_values(AdFormat::window('1:1', 1.0)) === [1080, 1080] ? [1080, 1080] : [0, 0]);
        $zoomed = AdFormat::window('16:9', 2.0);
        $this->assertSame(960, $zoomed['w']);
    }

    public function test_a_real_render_makes_an_mp4_and_a_poster_per_format_at_the_right_size(): void
    {
        if (! is_file((string) config('training-videos.auto.recorder.chromium')) || trim((string) shell_exec('which ffmpeg')) === '' || ! is_dir(base_path('node_modules/puppeteer-core'))) {
            $this->markTestSkipped('ffmpeg, Chromium or puppeteer-core is not here (a worktree has no node_modules; the real render is proven on QA1).');
        }
        app(TrainingAgencySeeder::class)->seed();
        $recorder = function (array $job): array {
            // a synthetic 14 s "screen" at 1920x1080 instead of the real recorder
            exec('ffmpeg -y -v error -f lavfi -i testsrc2=size=1920x1080:rate=25:duration=14 -pix_fmt yuv420p ' . escapeshellarg($job['out']) . ' 2>&1');
            $this->assertTrue($job['ad']);
            $this->assertSame(1920, $job['viewport']['width']);
            $this->assertSame('/corex/x', $job['steps'][0]['url']);
            $steps = [];
            foreach ($job['steps'] as $i => $s) {
                $steps[] = ['i' => $i, 'start' => 1 + $i * 6, 'end' => 7 + $i * 6, 'found' => true, 'settled' => 0.8, 'rect' => ['x' => 1200, 'y' => 300, 'w' => 300, 'h' => 120]];
            }

            return ['viewport' => $job['viewport'], 'steps' => $steps, 'total' => 14.0];
        };
        $work = $this->dir . '/work/ad-test';
        $stages = [];
        $out = app(AdComposer::class)->render($this->request(), 'bf_emma', $work, function ($stage, $pct) use (&$stages) {
            $stages[] = $stage;
        }, $recorder);

        $this->assertEqualsCanonicalizing(['9:16', '1:1'], array_keys($out));
        foreach (['9:16' => [1080, 1920], '1:1' => [1080, 1080]] as $f => [$w, $h]) {
            $this->assertFileExists($out[$f]['mp4']);
            $this->assertFileExists($out[$f]['poster']);
            $info = json_decode((string) shell_exec('ffprobe -v error -show_entries stream=codec_type,width,height -show_entries format=duration -of json ' . escapeshellarg($out[$f]['mp4'])), true);
            $v = collect($info['streams'])->firstWhere('codec_type', 'video');
            $this->assertSame([$w, $h], [$v['width'], $v['height']], $f);
            $this->assertNotNull(collect($info['streams'])->firstWhere('codec_type', 'audio'), 'the ad has a voice track');
            $this->assertEqualsWithDelta($out[$f]['seconds'], (float) $info['format']['duration'], 0.6);
            $this->assertLessThanOrEqual(30 * 1.15, $out[$f]['seconds']);
        }
        $this->assertContains('Filming the screen', $stages);
    }

    public function test_the_job_stores_the_files_marks_the_rows_ready_and_fires_the_event_and_a_failure_is_reported_in_plain_words(): void
    {
        Event::fake([AdRenderFinished::class]);
        $r = $this->request();
        AdRender::create(['batch_id' => 'b1', 'ad_ref' => 7, 'format' => '1:1', 'status' => 'queued', 'source_hash' => 'h']);

        $ok = new class extends AdComposer {
            public function __construct() {}

            public function render(array $r, string $voiceKey, string $work, callable $progress, ?callable $recorder = null): array
            {
                @mkdir($work, 0777, true);
                file_put_contents($work . '/a.mp4', 'MP4');
                file_put_contents($work . '/a.jpg', 'JPG');
                $progress('Making the 1:1 video', 70);

                return ['1:1' => ['mp4' => $work . '/a.mp4', 'poster' => $work . '/a.jpg', 'seconds' => 21.5, 'width' => 1080, 'height' => 1080, 'size' => 3]];
            }
        };
        (new RenderAdJob('b1', $r, 'bf_emma'))->handle($ok);
        $row = AdRender::where('batch_id', 'b1')->first();
        $this->assertSame('ready', $row->status);
        $this->assertStringStartsWith('training-videos-auto/ads/b1/', $row->file_name, 'ads live in their own private folder on the data volume');
        Storage::disk('local')->assertExists($row->file_name);
        Storage::disk('local')->assertExists($row->poster_name);
        $this->assertSame(1080, $row->width);
        Event::assertDispatched(AdRenderFinished::class, fn ($e) => $e->batchId === 'b1' && $e->adRef === 7 && $e->status === 'ready');

        AdRender::create(['batch_id' => 'b2', 'ad_ref' => 8, 'format' => '9:16', 'status' => 'queued', 'source_hash' => 'h2']);
        $bad = new class extends AdComposer {
            public function __construct() {}

            public function render(array $r, string $voiceKey, string $work, callable $progress, ?callable $recorder = null): array
            {
                throw new \RuntimeException('The screen no longer matches: shot(s) 2 could not find their element on the screen. Pick another screen step.');
            }
        };
        (new RenderAdJob('b2', $r, 'bf_emma'))->handle($bad);
        $s = app(AdRenderer::class)->status('b2');
        $this->assertSame('failed', $s['status']);
        $this->assertStringContainsString('Pick another screen step', $s['failure_reason']);
        Event::assertDispatched(AdRenderFinished::class, fn ($e) => $e->batchId === 'b2' && $e->status === 'failed');
    }
}
