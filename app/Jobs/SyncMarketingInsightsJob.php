<?php

namespace App\Jobs;

use App\Models\PropertyMarketingPost;
use App\Services\MetaPublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMarketingInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 1;

    public function handle(MetaPublishingService $publishingService): void
    {
        $cutoff = now()->subHours(23);

        $posts = PropertyMarketingPost::where('status', 'published')
            ->whereNotNull('platform_post_id')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_synced_at')
                  ->orWhere('last_synced_at', '<', $cutoff);
            })
            ->limit(50)
            ->get();

        Log::info('SyncMarketingInsightsJob: syncing ' . $posts->count() . ' posts.');

        foreach ($posts as $post) {
            try {
                $metrics = $publishingService->fetchPostInsights($post);
                $post->update(array_merge($metrics, ['last_synced_at' => now()]));
            } catch (\Throwable $e) {
                Log::error('SyncMarketingInsightsJob: failed for post ' . $post->id . ': ' . $e->getMessage());
            }
        }

        Log::info('SyncMarketingInsightsJob: done.');
    }
}
