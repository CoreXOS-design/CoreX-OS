<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\Marketing\MarketingPostPublished;
use App\Models\PropertyMarketingPost;
use Illuminate\Support\Facades\Log;

/**
 * SPINE-3 — fires MarketingPostPublished on the FIRST transition into
 * status='published'. The canonical "ad is live" moment per the audit:
 *
 *   PropertyMarketingController:108  → INSERT with status='draft'
 *   PropertyMarketingController:124  → on successful platform publish,
 *                                       UPDATE status='published' +
 *                                       published_at=now()
 *
 * The audit was explicit that the publish UPDATE — not the draft INSERT
 * — is the scoreable moment (the ad actually went live). We hook
 * `updated` and gate on the status flip; a failed publish that leaves
 * the row at 'draft' or 'failed' never credits.
 *
 * Failure-isolated: dispatch wrapped in try/catch so a points-side blip
 * never breaks the underlying post update.
 */
final class PropertyMarketingPostObserver
{
    public function updated(PropertyMarketingPost $post): void
    {
        try {
            if (! $post->wasChanged('status')) {
                return;
            }
            if ($post->status !== 'published') {
                return;
            }
            event(new MarketingPostPublished($post));
        } catch (\Throwable $e) {
            Log::warning('SPINE-3 MarketingPostPublished dispatch failed (swallowed)', [
                'post_id' => $post->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
