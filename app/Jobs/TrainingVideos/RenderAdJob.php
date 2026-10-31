<?php

namespace App\Jobs\TrainingVideos;

use App\Events\TrainingVideos\AdRenderFinished;
use App\Models\AdRender;
use App\Services\TrainingVideos\Ads\AdComposer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * AT-525 / AT-527 - render one batch of an ad (every requested format) on the `training-videos` lane. A failure writes its plain-sentence reason on the rows and fires
 * AdRenderFinished with status `failed`; nothing half-made is kept as `ready`.
 */
class RenderAdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3000;
    public int $tries = 1;

    /** @param array<string,mixed> $request */
    public function __construct(public readonly string $batchId, public readonly array $request, public readonly string $voiceKey)
    {
        $this->onQueue((string) config('training-videos.queue', 'training-videos'));
    }

    public function handle(AdComposer $composer): void
    {
        $rows = AdRender::query()->where('batch_id', $this->batchId)->get();
        if ($rows->isEmpty()) {
            return;
        }
        AdRender::where('batch_id', $this->batchId)->update(['status' => 'rendering', 'progress_percent' => 2, 'stage' => 'Starting']);
        $work = rtrim((string) config('training-videos.auto.work_dir'), '/') . '/ad-' . $this->batchId;
        $disk = Storage::disk((string) config('training-videos.auto.disk', 'local'));
        $folder = rtrim((string) config('training-videos.ads.folder'), '/') . '/' . $this->batchId;
        try {
            $made = $composer->render($this->request, $this->voiceKey, $work, function (string $stage, int $pct) {
                AdRender::where('batch_id', $this->batchId)->update(['stage' => $stage, 'progress_percent' => $pct]);
            });
            foreach ($made as $format => $f) {
                $key = str_replace(':', 'x', $format);
                $disk->put("{$folder}/{$key}.mp4", (string) file_get_contents($f['mp4']));
                $disk->put("{$folder}/{$key}.jpg", (string) file_get_contents($f['poster']));
                AdRender::where('batch_id', $this->batchId)->where('format', $format)->update(['status' => 'ready', 'stage' => 'Ready', 'progress_percent' => 100, 'file_name' => "{$folder}/{$key}.mp4",
                    'poster_name' => "{$folder}/{$key}.jpg", 'duration_seconds' => $f['seconds'], 'size_bytes' => $f['size'], 'width' => $f['width'], 'height' => $f['height'], 'rendered_at' => now(), 'failure_reason' => null]);
            }
            AdRenderFinished::dispatch($this->batchId, $rows->first()->ad_ref ? (int) $rows->first()->ad_ref : null, 'ready');
        } catch (\Throwable $e) {
            \Log::warning('Ad render failed', ['batch' => $this->batchId, 'error' => $e->getMessage()]);
            AdRender::where('batch_id', $this->batchId)->where('status', '!=', 'ready')->update(['status' => 'failed', 'stage' => 'Failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 500)]);
            AdRenderFinished::dispatch($this->batchId, $rows->first()->ad_ref ? (int) $rows->first()->ad_ref : null, 'failed');
        } finally {
            File::deleteDirectory($work);
        }
    }
}
