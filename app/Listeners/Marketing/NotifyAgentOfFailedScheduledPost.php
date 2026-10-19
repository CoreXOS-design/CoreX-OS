<?php

declare(strict_types=1);

namespace App\Listeners\Marketing;

use App\Events\Marketing\MarketingPostScheduleFailed;
use App\Models\User;
use App\Notifications\ScheduledPostFailedNotification;
use Illuminate\Support\Facades\Log;

/**
 * AT-456 Part D — MarketingPostScheduleFailed -> a bell notification to the agent whose post it is.
 * Sync. Idempotent: one notification per post (a repeat of the same event for the same post is
 * skipped). Failure-isolated: a notification problem must never break the publish run.
 */
final class NotifyAgentOfFailedScheduledPost
{
    public function handle(MarketingPostScheduleFailed $event): void
    {
        try {
            $post = $event->post;

            $user = $post->user_id ? User::withoutGlobalScopes()->find($post->user_id) : null;
            // Event-layer tenancy check (domain-events spec E7): never notify across agencies.
            if (! $user || ($user->agency_id !== null && (int) $user->agency_id !== (int) $post->agency_id)) {
                return;
            }

            $already = $user->notifications()
                ->where('type', ScheduledPostFailedNotification::class)
                ->where(fn ($w) => $w
                    ->where('data', 'like', '%"post_id":' . (int) $post->id . ',%')
                    ->orWhere('data', 'like', '%"post_id":' . (int) $post->id . '}%'))
                ->exists();
            if ($already) {
                return;
            }

            $user->notify(new ScheduledPostFailedNotification($post, $event->reason));
        } catch (\Throwable $e) {
            Log::warning('NotifyAgentOfFailedScheduledPost failed (swallowed)', [
                'post_id' => $event->post->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
