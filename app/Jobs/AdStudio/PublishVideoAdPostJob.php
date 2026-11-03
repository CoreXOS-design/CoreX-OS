<?php

namespace App\Jobs\AdStudio;

use App\Models\VideoAdPost;
use App\Services\AdStudio\VideoAdPostService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * AT-527 - "Post now": publishes one video-ad post in the background (a video upload can take a minute, so it never runs inside the web request).
 * Spec .ai/specs/training-videos.md 9.22. Dry run when external publishing is off.
 */
class PublishVideoAdPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1200;
    public int $tries = 1;

    public function __construct(public readonly int $postId)
    {
    }

    public function handle(VideoAdPostService $service): void
    {
        $post = VideoAdPost::find($this->postId);
        if ($post) {
            $service->publish($post);
        }
    }
}
