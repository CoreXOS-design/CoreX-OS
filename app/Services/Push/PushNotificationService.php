<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\Push\Contracts\PushTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The single funnel every device push in CoreX flows through.
 *
 * Before this existed, two call sites (NotificationDispatcher::sendPush and the
 * PushNewPortalLeadToMobile listener) called the FCM transport directly with
 * zero guards. A single logical event — most dangerously an agency-wide portal
 * lead, re-fired by the 5-minute P24 poller or amplified by stale duplicate
 * device-token rows — fanned out 1:1 into device pushes. Repeated firing meant
 * a push storm: the same buzz delivered again and again until the handset
 * locked up. See .ai/specs/push-notifications.md and the incident write-up.
 *
 * Every push now passes through dispatch(), which enforces, in order:
 *   1. Token de-duplication        — one physical token is hit at most once per call.
 *   2. Idempotency (key, token)     — the same logical push is never delivered twice
 *                                     to the same device within the idempotency TTL.
 *   3. Per-device rate cap          — hard backstop: at most N pushes per device per
 *                                     minute regardless of key, so even a distinct-key
 *                                     flood cannot storm a handset.
 *   4. Bounded retry + backoff      — transient transport failures retry up to a cap
 *                                     with exponential backoff, then give up.
 *   5. Stale-token pruning          — tokens the provider reports permanently dead
 *                                     (NotRegistered/Invalid) are deleted, never retried.
 *   6. Per-user-per-minute metrics  — counted + logged so a future regression is
 *                                     observable and alertable.
 */
class PushNotificationService
{
    public function __construct(private PushTransport $transport) {}

    /**
     * Push to every active device of a single user.
     *
     * @param  string  $idempotencyKey  Stable key for the LOGICAL notification
     *                                   (e.g. "user:7|deal.stalled_offer|Deal:42|2026061013").
     *                                   Re-firing the same event with the same key is a no-op.
     */
    public function sendToUser(User $user, string $idempotencyKey, array $payload): PushDispatchSummary
    {
        return $this->sendToUserIds([$user->id], $idempotencyKey, $payload);
    }

    /**
     * Push to every active device of every given user id.
     *
     * @param  array<int|string>  $userIds
     */
    public function sendToUserIds(array $userIds, string $idempotencyKey, array $payload): PushDispatchSummary
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (empty($userIds)) {
            return PushDispatchSummary::empty();
        }

        $devices = DeviceToken::query()
            ->whereIn('user_id', $userIds)
            ->whereNotNull('token')
            ->get(['id', 'user_id', 'token']);

        return $this->dispatch($devices, $idempotencyKey, $payload);
    }

    /**
     * Core dispatch. Operates on resolved DeviceToken rows so it can de-dupe by
     * token string, attribute metrics per user, and prune dead rows.
     *
     * @param  Collection<int, DeviceToken>  $devices
     */
    public function dispatch(Collection $devices, string $idempotencyKey, array $payload): PushDispatchSummary
    {
        $requested = $devices->count();
        if ($requested === 0) {
            return PushDispatchSummary::empty();
        }

        if (! $this->transport->isOperational()) {
            // No real transport wired (e.g. local/CI). Nothing to send, nothing to
            // storm. Still record the intent so dashboards don't read as "broken".
            return new PushDispatchSummary($requested, 0, 0, 0, 0, 0);
        }

        // 1) One row per distinct token string. A single physical handset can own
        //    multiple DeviceToken rows (token rotation, or the same FCM token under
        //    two user accounts after a re-login) — collapse them so it buzzes once.
        $byToken = [];
        foreach ($devices as $device) {
            $token = (string) $device->token;
            if ($token === '') continue;
            $byToken[$token] ??= $device;
        }

        $deduped = count($byToken);

        $deliverable    = [];      // token => DeviceToken
        $idempotentSkip = 0;
        $rateLimited    = 0;

        foreach ($byToken as $token => $device) {
            // 2) Idempotency per (key, token): atomic Cache::add returns false when
            //    this logical push already went to this device inside the TTL.
            if (! $this->claimIdempotency($idempotencyKey, $token)) {
                $idempotentSkip++;
                continue;
            }

            // 3) Hard per-device rate cap — the backstop that holds even if the
            //    idempotency key is wrong/missing or a genuine burst arrives.
            if (! $this->withinRateCap($token)) {
                $rateLimited++;
                continue;
            }

            $deliverable[$token] = $device;
        }

        if ($rateLimited > 0) {
            Log::warning('Push rate cap hit — devices dropped to prevent a notification storm', [
                'idempotency_key' => $idempotencyKey,
                'rate_limited'    => $rateLimited,
                'cap_per_minute'  => $this->ratePerMinute(),
            ]);
        }

        $sent = 0;
        if (! empty($deliverable)) {
            $sent = $this->deliverWithRetry(array_keys($deliverable), $payload);
        }

        $this->recordMetrics($byToken, $deliverable, $idempotencyKey, $sent);

        return new PushDispatchSummary(
            requested:        $requested,
            deduped:          $deduped,
            idempotencySkipped: $idempotentSkip,
            rateLimited:      $rateLimited,
            sent:             $sent,
            attempted:        count($deliverable),
        );
    }

    /**
     * Bounded retry with exponential backoff. Transport throws on transport-level
     * failure → retry up to maxAttempts; permanent per-token rejections come back
     * as deadTokens (no throw) → prune, never retry.
     */
    private function deliverWithRetry(array $tokens, array $payload): int
    {
        $maxAttempts = $this->maxAttempts();
        $attempt     = 0;
        $lastError   = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $result = $this->transport->send($tokens, $payload);
                $this->pruneDeadTokens($result->deadTokens);
                return $result->sent;
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < $maxAttempts) {
                    $this->backoff($attempt);
                }
            }
        }

        Log::warning('Push delivery failed after bounded retries', [
            'attempts' => $maxAttempts,
            'tokens'   => count($tokens),
            'error'    => $lastError?->getMessage(),
        ]);

        return 0;
    }

    private function pruneDeadTokens(array $deadTokens): void
    {
        $deadTokens = array_values(array_filter(array_unique($deadTokens)));
        if (empty($deadTokens)) return;

        DeviceToken::whereIn('token', $deadTokens)->delete();
        Log::info('Pruned stale device tokens reported dead by the push provider', [
            'count' => count($deadTokens),
        ]);
    }

    /**
     * Returns true the first time this (key, token) pair is claimed within the
     * idempotency window, false on every repeat. Cache::add is atomic, so two
     * concurrent dispatches of the same event race safely — exactly one wins.
     */
    private function claimIdempotency(string $key, string $token): bool
    {
        $cacheKey = 'push:idem:' . sha1($key . '|' . $token);
        return Cache::add($cacheKey, 1, $this->idempotencyTtlSeconds());
    }

    private function withinRateCap(string $token): bool
    {
        $cap = $this->ratePerMinute();
        if ($cap <= 0) {
            return true; // cap disabled
        }

        $bucket   = now()->format('YmdHi'); // per-minute bucket
        $cacheKey = 'push:rate:' . sha1($token) . ':' . $bucket;

        // Initialise the counter (with a TTL) before incrementing so the key
        // always expires; increment is atomic on the redis/array stores.
        Cache::add($cacheKey, 0, 120);
        $count = (int) Cache::increment($cacheKey);

        return $count <= $cap;
    }

    private function backoff(int $attempt): void
    {
        $baseMs = $this->retryBaseMs();
        if ($baseMs <= 0) return; // disabled in tests

        // Exponential: base * 2^(attempt-1), capped to keep a queued worker honest.
        $delayMs = min($baseMs * (2 ** ($attempt - 1)), 5_000);
        usleep($delayMs * 1000);
    }

    /**
     * @param  array<string, DeviceToken>  $byToken      every distinct token considered
     * @param  array<string, DeviceToken>  $deliverable  tokens that passed the guards
     */
    private function recordMetrics(array $byToken, array $deliverable, string $key, int $sent): void
    {
        $bucket = now()->format('YmdHi');

        // Per-user-per-minute push counter — the regression tripwire.
        $perUser = [];
        foreach ($deliverable as $device) {
            $perUser[$device->user_id] = ($perUser[$device->user_id] ?? 0) + 1;
        }

        $cap = $this->ratePerMinute();
        foreach ($perUser as $userId => $n) {
            $metricKey = 'push:metric:user:' . $userId . ':' . $bucket;
            Cache::add($metricKey, 0, 120);
            $total = (int) Cache::increment($metricKey, $n);

            if ($cap > 0 && $total > $cap) {
                Log::warning('User is receiving pushes above the per-minute cap — possible storm', [
                    'user_id'        => $userId,
                    'pushes_this_min'=> $total,
                    'cap_per_minute' => $cap,
                ]);
            }
        }

        Log::info('Push dispatched', [
            'idempotency_key' => $key,
            'distinct_tokens' => count($byToken),
            'delivered'       => count($deliverable),
            'sent'            => $sent,
        ]);
    }

    private function idempotencyTtlSeconds(): int
    {
        return (int) config('push.idempotency_ttl', 300);
    }

    private function ratePerMinute(): int
    {
        return (int) config('push.rate_per_minute', 5);
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('push.max_attempts', 3));
    }

    private function retryBaseMs(): int
    {
        return (int) config('push.retry_base_ms', 200);
    }
}
