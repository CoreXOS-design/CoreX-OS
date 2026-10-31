<?php

namespace App\Services\AdStudio;

use App\Jobs\AdStudio\PublishVideoAdPostJob;
use App\Models\AgentSocialAccount;
use App\Models\User;
use App\Models\VideoAd;
use App\Models\VideoAdPost;
use App\Services\MetaOAuthService;
use App\Services\MetaPublishingService;
use App\Support\ExternalPublishingGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * AT-527 (spec .ai/specs/training-videos.md 9.22) - Post now / Schedule a video ad to the CoreX Facebook page, and the due-posts pass that
 * `marketing:publish-due` runs. Reuses the Ad Manager's pieces (MetaOAuthService token refresh, MetaPublishingService, the atomic claim pattern of
 * ScheduledPostPublisher); only the table and the preflight are new, because the agency preflight is agency / listing bound.
 *
 * Johan connects the page himself through Facebook's own login; this never sees a password. When external publishing is off (QA1) every publish is a dry run.
 */
class VideoAdPostService
{
    public function __construct(
        private readonly MetaPublishingService $publisher,
        private readonly MetaOAuthService $oauth,
    ) {
    }

    /** True while nothing is really sent (external publishing off on this server): the pages show the "Dry run (QA)" badge. */
    public function isDryRun(): bool
    {
        return ! ExternalPublishingGuard::isAllowed();
    }

    /** The CoreX Facebook page, when one is connected and active. */
    public function account(): ?AgentSocialAccount
    {
        return AgentSocialAccount::withoutGlobalScopes()->where('is_platform', true)->where('platform', 'facebook')->where('is_active', true)->whereNull('deleted_at')->first();
    }

    /** The format a one-press "Post now" uses: the square feed video if the ad has one, else the first ready format. */
    public function defaultFormat(VideoAd $ad): ?string
    {
        $ready = array_keys($ad->readyFormats());
        foreach (['1:1', '16:9', '9:16'] as $f) {
            if (in_array($f, $ready, true)) {
                return $f;
            }
        }

        return $ready[0] ?? null;
    }

    /** @throws \RuntimeException with a plain sentence when this ad cannot be posted */
    private function assertPostable(VideoAd $ad, string $format): void
    {
        if ($ad->trashed() || ! $ad->isReady()) {
            throw new \RuntimeException('Only a finished ad can be posted.');
        }
        if (! isset($ad->readyFormats()[$format])) {
            throw new \RuntimeException("This ad has no {$format} video.");
        }
        if (! $this->account()) {
            throw new \RuntimeException('Connect the CoreX Facebook page first (Marketing, Facebook page).');
        }
    }

    public function postNow(VideoAd $ad, string $format, User $by): VideoAdPost
    {
        $this->assertPostable($ad, $format);
        $post = $this->make($ad, $format, now()->utc(), $by);
        PublishVideoAdPostJob::dispatch($post->id);

        return $post;
    }

    public function schedule(VideoAd $ad, string $format, CarbonInterface $when, User $by): VideoAdPost
    {
        $this->assertPostable($ad, $format);
        if ($when->lessThanOrEqualTo(now()->addMinute())) {
            throw new \RuntimeException('Pick a time at least a few minutes from now.');
        }

        return $this->make($ad, $format, $when, $by);
    }

    private function make(VideoAd $ad, string $format, CarbonInterface $when, User $by): VideoAdPost
    {
        return VideoAdPost::create([
            'video_ad_id' => $ad->id, 'social_account_id' => $this->account()?->id, 'format' => $format, 'message' => $ad->postText(), 'status' => VideoAdPost::STATUS_SCHEDULED,
            'scheduled_for' => $when, 'created_by' => $by->id,
        ]);
    }

    public function cancel(VideoAdPost $post): bool
    {
        return VideoAdPost::query()->whereKey($post->id)->where('status', VideoAdPost::STATUS_SCHEDULED)
            ->update(['status' => VideoAdPost::STATUS_CANCELLED, 'updated_at' => now()]) === 1;
    }

    public function reschedule(VideoAdPost $post, CarbonInterface $when): void
    {
        if (! $post->isOpen()) {
            throw new \RuntimeException('Only a post that is still waiting can be moved.');
        }
        if ($when->lessThanOrEqualTo(now()->addMinute())) {
            throw new \RuntimeException('Pick a time at least a few minutes from now.');
        }
        $post->scheduled_for = $when;
        $post->save();
    }

    /** The cron pass: publish every scheduled post whose time has come. @return array{published:int, failed:int, skipped:int} */
    public function runDue(int $limit = 20): array
    {
        $r = ['published' => 0, 'failed' => 0, 'skipped' => 0];
        $ids = VideoAdPost::query()->where('status', VideoAdPost::STATUS_SCHEDULED)->where('scheduled_for', '<=', now()->utc()->format('Y-m-d H:i:s'))
            ->orderBy('scheduled_for')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $post = VideoAdPost::find($id);
            if (! $post) {
                continue;
            }
            $r[$this->publish($post)]++;
        }

        return $r;
    }

    /** Claim (atomic, so overlapping runs publish once) and publish one post. @return 'published'|'failed'|'skipped' */
    public function publish(VideoAdPost $post): string
    {
        $claimed = VideoAdPost::query()->whereKey($post->id)->where('status', VideoAdPost::STATUS_SCHEDULED)
            ->update(['status' => VideoAdPost::STATUS_PUBLISHING, 'updated_at' => now()]) === 1;
        if (! $claimed) {
            return 'skipped';
        }
        $post->refresh();

        try {
            $ad = VideoAd::withTrashed()->find($post->video_ad_id);
            if (! $ad || $ad->trashed() || ! $ad->isReady()) {
                return $this->fail($post, 'The video ad was archived or is no longer available, so it was not posted.');
            }
            $entry = $ad->readyFormats()[$post->format] ?? null;
            $real = $entry && ! empty($entry['mp4']) ? realpath((string) $entry['mp4']) : false;
            $root = realpath(storage_path('app'));
            if (! $real || ! $root || ! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return $this->fail($post, 'The video file is no longer available. Make the ad again.');
            }
            $account = $this->account();
            if (! $account) {
                return $this->fail($post, 'The CoreX Facebook page is not connected. Connect it under Marketing, Facebook page, then schedule the post again.');
            }
            $this->oauth->refreshTokenIfNeeded($account);
            $account->refresh();
            if ($account->token_expires_at !== null && $account->token_expires_at->lte(now())) {
                return $this->fail($post, 'The Facebook connection has expired. Reconnect it under Marketing, Facebook page.');
            }

            $out = $this->publisher->publishVideoToFacebook($account, $real, $post->message, $ad->name . ' - ' . data_get($ad->snapshot, 'feature_title'));
            $post->forceFill(['status' => VideoAdPost::STATUS_PUBLISHED, 'published_at' => now(), 'platform_post_id' => $out['id'], 'dry_run' => $out['dry_run'], 'failure_reason' => null])->save();

            return 'published';
        } catch (\Throwable $e) {
            Log::warning('Video ad post ' . $post->id . ' failed: ' . $e->getMessage());

            return $this->fail($post, mb_substr($e->getMessage(), 0, 240));
        }
    }

    private function fail(VideoAdPost $post, string $reason): string
    {
        $post->forceFill(['status' => VideoAdPost::STATUS_FAILED, 'failure_reason' => mb_substr($reason, 0, 240)])->save();

        return 'failed';
    }
}
