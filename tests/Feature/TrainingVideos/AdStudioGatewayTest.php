<?php

namespace Tests\Feature\TrainingVideos;

use App\Jobs\TrainingVideos\RenderAdJob;
use App\Models\AdRender;
use App\Services\AdStudio\AdRenderGateway;
use App\Services\TrainingVideos\Ads\AdStudioRenderGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-525 / AT-527 (spec 9.16.2) - Ad Studio's AdRenderGateway is bound to the renderer: queue() returns a batch id and queues the render, a refused request is ONE plain sentence, and
 * status() speaks the gateway's shape (state / percent / stage / failure / absolute file paths).
 */
final class AdStudioGatewayTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dir = sys_get_temp_dir() . '/tv-gw-' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/mic-work.php', '<?php return ' . var_export(['key' => 'mic-work', 'title' => 'MIC', 'route' => null, 'url' => '/corex/m', 'approved' => true, 'related' => [],
            'steps' => [['selector' => '[data-x="a"]', 'action' => 'highlight', 'narration' => 'x', 'why' => 'y']]], true) . ';');
        config(['training-videos.auto.scripts_dir' => $this->dir]);
        $this->app->forgetInstance(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** the request exactly as AdSnapshotBuilder builds it (hook.accent, cta.sub, CoreX's own brand, a step-less shot given by route + selector) */
    private function request(): array
    {
        return [
            'ad_ref' => null, 'length' => 30, 'formats' => ['9:16', '1:1'], 'voice' => null, 'captions' => true, 'music' => null,
            'script' => [
                'hook' => ['text' => 'Still guessing which owners to phone?', 'accent' => 'guessing', 'seconds' => 3.0],
                'beats' => [
                    ['benefit' => 'See who to phone first', 'screen' => ['script_key' => 'mic-work', 'step' => 0], 'zoom' => ['selector' => null, 'scale' => 1.4], 'seconds' => 4.0],
                    ['benefit' => 'One click shows everything', 'screen' => ['route' => 'login', 'selector' => '[data-x="b"]', 'action' => 'highlight'], 'zoom' => ['selector' => '[data-x="b"]', 'scale' => 1.2], 'seconds' => 4.0],
                ],
                'proof' => ['text' => 'HFC runs on CoreX, and we keep expanding it.', 'seconds' => 2.5],
                'cta' => ['line' => 'Join CoreX', 'follow' => 'Follow us', 'sub' => 'Run your agency on CoreX'],
            ],
            'brand' => ['name' => 'CoreX', 'logo' => '/x/logo.svg', 'colours' => ['primary' => '#0b2a4a', 'accent' => '#33c4e0', 'text' => '#ffffff', 'background' => '#0e5a7a'], 'cta_url' => 'https://corexos.co.za',
                'website' => 'https://corexos.co.za', 'contact_email' => 'johan@corexos.co.za', 'phone' => '+27 76 618 5578', 'socials' => [], 'tagline' => 'HFC runs on CoreX, and we keep expanding it.',
                'join_line' => 'Join CoreX', 'cta_line' => 'Run your agency on CoreX'],
        ];
    }

    public function test_the_gateway_interface_is_bound_to_the_renderer(): void
    {
        $this->assertInstanceOf(AdStudioRenderGateway::class, app(AdRenderGateway::class));
    }

    public function test_queue_returns_a_batch_and_status_speaks_the_gateways_shape(): void
    {
        Bus::fake();
        $gw = app(AdRenderGateway::class);
        $batch = $gw->queue($this->request());
        Bus::assertDispatched(RenderAdJob::class);

        $s = $gw->status($batch);
        $this->assertSame(['state', 'percent', 'stage', 'failure', 'files'], array_keys($s));
        $this->assertSame('queued', $s['state']);

        AdRender::where('batch_id', $batch)->where('format', '9:16')->update(['status' => 'ready', 'progress_percent' => 100, 'file_name' => 'training-videos-auto/ads/x/9x16.mp4', 'poster_name' => 'training-videos-auto/ads/x/9x16.jpg',
            'duration_seconds' => 26.4, 'width' => 1080, 'height' => 1920, 'size_bytes' => 2000]);
        AdRender::where('batch_id', $batch)->where('format', '1:1')->update(['status' => 'ready', 'progress_percent' => 100, 'file_name' => 'training-videos-auto/ads/x/1x1.mp4', 'poster_name' => 'training-videos-auto/ads/x/1x1.jpg',
            'duration_seconds' => 26.4, 'width' => 1080, 'height' => 1080, 'size_bytes' => 1000]);
        $s = $gw->status($batch);
        $this->assertSame('ready', $s['state']);
        $f = $s['files']['9:16'];
        $this->assertSame(Storage::disk('local')->path('training-videos-auto/ads/x/9x16.mp4'), $f['mp4'], 'an ABSOLUTE path');
        $this->assertSame([1080, 1920, 26.4], [$f['width'], $f['height'], $f['seconds']]);
    }

    public function test_a_refused_request_is_one_plain_sentence_for_the_editor(): void
    {
        Bus::fake();
        $r = $this->request();
        $r['script']['hook']['text'] = 'We are building the best tile panel for you today in the market right now';
        try {
            app(AdRenderGateway::class)->queue($r);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertStringContainsString('more thing', $e->getMessage());
        }
        $this->assertSame(0, AdRender::count());
        Bus::assertNothingDispatched();
    }
}
