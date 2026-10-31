<?php

namespace App\Jobs\AdStudio;

use App\Models\VideoAd;
use App\Services\AdStudio\VideoAdService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * AT-527 - hands one saved "Ad N" (its frozen request) to the render pipeline on the `training-videos` queue. The rendering itself is the pipeline's job
 * (spec .ai/specs/training-videos.md 9.16.1); this only queues it and records where it went or why it could not.
 */
class GenerateVideoAdJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public readonly int $adId)
    {
        $this->onQueue((string) config('training-videos.queue', 'training-videos'));
    }

    public function handle(VideoAdService $service): void
    {
        $ad = VideoAd::withTrashed()->find($this->adId);
        if ($ad && $ad->status === VideoAd::STATUS_QUEUED) {
            $service->queueRender($ad);
        }
    }
}
