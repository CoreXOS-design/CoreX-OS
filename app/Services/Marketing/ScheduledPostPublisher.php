<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Events\Marketing\MarketingPostScheduleFailed;
use App\Models\AgentSocialAccount;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Services\Compliance\MarketingReadinessService;
use App\Services\MetaOAuthService;
use App\Services\MetaPublishingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AT-456 Part D — publishes scheduled posts that have come due (spec §D.3).
 *
 *  1. CLAIM  — `UPDATE ... SET status='publishing' WHERE id=? AND status='scheduled' AND
 *     scheduled_for <= now`. Only the run whose UPDATE touches the row owns it, so two overlapping
 *     runs (or a retry) can never publish the same post twice. Cancel / edit use the same
 *     compare-and-set, so they can never lose to — or race with — a publish.
 *  2. CHECK  — the connection (token refreshed through MetaOAuthService, expiry / missing account),
 *     the listing (still on the market and marketing-ready), the pictures (still on disk).
 *  3. PUBLISH — through the existing MetaPublishingService, inline (no queue worker needed).
 *  4. RECORD — success: status 'published' + platform id (the observer then fires
 *     MarketingPostPublished). Failure: status 'failed' + a plain failure_reason, MarketingPostScheduleFailed
 *     (-> bell notification to the agent). A failed post is NEVER re-published automatically: Meta may have
 *     accepted it before the error came back, and a retry would post twice.
 *
 * Runs with no signed-in user (scheduler / command), so it reads across agencies with the global
 * scopes lifted and checks the agency of each related record itself.
 */
class ScheduledPostPublisher
{
    /** A post that has been 'publishing' this long was interrupted (process died); never retried. */
    public const STUCK_AFTER_MINUTES = 30;

    public function __construct(
        private readonly MetaPublishingService $publisher,
        private readonly MetaOAuthService $oauth,
        private readonly ScheduledPostImages $images,
    ) {}

    /**
     * Publish everything that is due. Returns counts for the command's output.
     *
     * @return array{published:int, failed:int, skipped:int, interrupted:int}
     */
    public function runDue(int $limit = 50): array
    {
        $out = ['published' => 0, 'failed' => 0, 'skipped' => 0, 'interrupted' => 0];

        $out['interrupted'] = $this->failInterrupted();

        $ids = DB::table('property_marketing_posts')
            ->where('status', PropertyMarketingPost::STATUS_SCHEDULED)
            ->where('scheduled_for', '<=', now()->utc()->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at')
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            if (! $this->claim((int) $id)) {
                $out['skipped']++;   // another run, a cancel or an edit got there first

                continue;
            }

            $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
            if (! $post) {
                continue;
            }

            $this->publishClaimed($post) ? $out['published']++ : $out['failed']++;
        }

        return $out;
    }

    /** Atomic claim. True only for the single caller whose UPDATE changed the row. */
    public function claim(int $postId): bool
    {
        return DB::table('property_marketing_posts')
            ->where('id', $postId)
            ->where('status', PropertyMarketingPost::STATUS_SCHEDULED)
            ->where('scheduled_for', '<=', now()->utc()->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at')
            ->update(['status' => PropertyMarketingPost::STATUS_PUBLISHING, 'updated_at' => now()]) === 1;
    }

    /** Publish a post this caller has already claimed. True = published, false = failed (and recorded). */
    public function publishClaimed(PropertyMarketingPost $post): bool
    {
        try {
            $reason = $this->preflight($post, $account);
            if ($reason !== null) {
                $this->fail($post, $reason);

                return false;
            }

            $copy   = (string) $post->ad_copy;
            $images = array_values((array) $post->image_urls);

            $platformPostId = $post->platform === 'instagram'
                ? $this->publisher->publishToInstagram($account, $copy, $images)
                : $this->publisher->publishToFacebook($account, $copy, $images);
        } catch (\Throwable $e) {
            Log::error('ScheduledPostPublisher: publish failed for post ' . $post->id . ': ' . $this->scrub($e->getMessage()));
            $this->fail($post, $this->explain($post->platform, $e->getMessage()));

            return false;
        }

        // Meta has the post. Record it; if THIS write failed the post would be left 'publishing' and
        // later marked interrupted — never re-published.
        $post->forceFill([
            'platform_post_id' => $platformPostId,
            'status'           => PropertyMarketingPost::STATUS_PUBLISHED,
            'published_at'     => now(),
            'failure_reason'   => null,
        ])->save();

        return true;
    }

    /**
     * Everything that must still be true at publish time. Returns a plain-language reason, or null when
     * clear to publish (and the account to use in $account).
     */
    private function preflight(PropertyMarketingPost $post, ?AgentSocialAccount &$account = null): ?string
    {
        $label = ucfirst((string) $post->platform);

        $property = Property::withoutGlobalScopes()->find($post->property_id);
        if (! $property || $property->deleted_at !== null || (int) $property->agency_id !== (int) $post->agency_id) {
            return 'The listing for this post is no longer available.';
        }
        if ($property->isOtherAgencyStock() || ! $property->isOnMarket()) {
            return 'The listing is no longer on the market, so the post was not published.';
        }
        if (! app(MarketingReadinessService::class)->isMarketable($property)) {
            return 'The listing is no longer marketing-ready, so the post was not published.';
        }

        $account = AgentSocialAccount::withoutGlobalScopes()
            ->where('user_id', $post->user_id)
            ->where('platform', $post->platform)
            ->where('is_active', true)
            ->first();

        if (! $account || ($account->agency_id !== null && (int) $account->agency_id !== (int) $post->agency_id)) {
            return "No connected $label account was found. Connect it under My Portal, Tools, Social Media, then schedule the post again.";
        }

        // Existing token refresh (renews a token that is inside its last 7 days).
        $this->oauth->refreshTokenIfNeeded($account);
        $account->refresh();

        if ($account->token_expires_at !== null && $account->token_expires_at->lte(now())) {
            return "Your $label connection has expired. Reconnect it under My Portal, Tools, Social Media, then schedule the post again.";
        }

        if (! $this->images->allPresent($post)) {
            return 'The picture for this post is no longer available. Make the ad again and schedule it fresh.';
        }

        return null;
    }

    /** Record the failure, tell the rest of CoreX. Never throws. */
    public function fail(PropertyMarketingPost $post, string $reason): void
    {
        try {
            DB::table('property_marketing_posts')->where('id', $post->id)->update([
                'status'         => PropertyMarketingPost::STATUS_FAILED,
                'failure_reason' => mb_substr($reason, 0, 1000),
                'updated_at'     => now(),
            ]);
            $post->refresh();
        } catch (\Throwable $e) {
            Log::error('ScheduledPostPublisher: could not record failure for post ' . $post->id . ': ' . $e->getMessage());

            return;
        }

        try {
            event(new MarketingPostScheduleFailed($post, $reason));
        } catch (\Throwable $e) {
            Log::warning('MarketingPostScheduleFailed dispatch failed (swallowed)', ['post_id' => $post->id, 'message' => $e->getMessage()]);
        }
    }

    /** Posts stuck in 'publishing' (the process died mid-publish): fail them, do NOT retry. */
    private function failInterrupted(): int
    {
        $n = 0;
        $stuck = PropertyMarketingPost::withoutGlobalScopes()
            ->where('status', PropertyMarketingPost::STATUS_PUBLISHING)
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
            ->get();

        foreach ($stuck as $post) {
            $this->fail($post, 'CoreX was interrupted while publishing this post, so it may or may not have gone out. '
                . 'Check your ' . ucfirst((string) $post->platform) . ' page before scheduling it again.');
            $n++;
        }

        return $n;
    }

    /**
     * Meta's / Guzzle's wording to something an agent can act on. Never contains a token.
     * Public: the direct (non-scheduled) publish in PropertyMarketingController stores the
     * same plain, scrubbed reason on a failed post, so there is one scrubbing path.
     */
    public function explain(string $platform, string $raw): string
    {
        $label = ucfirst($platform);
        $clean = $this->scrub($raw);

        if (preg_match('/OAuthException|"code"\s*:\s*190|access token|session (has )?(expired|been invalidated)|Error validating|permission|\(#200\)|\(#10\)/i', $clean)) {
            return "$label no longer accepts your connection (it was disconnected, expired or lost a permission). "
                . 'Reconnect it under My Portal, Tools, Social Media, then schedule the post again.';
        }

        return "$label did not accept the post: " . mb_substr(preg_replace('/^Failed to publish to \w+:\s*/', '', $clean) ?? $clean, 0, 300);
    }

    private function scrub(string $text): string
    {
        $text = preg_replace('/access_token=[^&\s"\']+/i', 'access_token=[hidden]', $text) ?? $text;

        return preg_replace('/EAA[A-Za-z0-9]{20,}/', '[hidden]', $text) ?? $text;
    }
}
