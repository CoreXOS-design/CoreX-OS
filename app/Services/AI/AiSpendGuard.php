<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\AiPausedException;
use App\Mail\AiSpendAlertMail;
use App\Models\AI\AiSpendGuardEvent;
use App\Models\AI\AINarrativeCache;
use App\Models\Agency;
use App\Models\DevSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * AI Spend Safety — the one choke point between CoreX and an AI bill nobody
 * noticed. Replaces the per-agency monthly rand budget (AT-456 §A).
 *
 *   assertAllowed()  — called by EVERY Anthropic caller before it spends.
 *                      Throws AiPausedException while the bucket is paused.
 *   evaluate()       — called by AiUsageRecorder after every costed ledger
 *                      write. Sends the alert email at the alert level and
 *                      pauses the bucket at the cut-out level.
 *   resume()         — the owner's "Turn AI back on".
 *
 * A "bucket" is one agency, or the platform-wide system bucket (agency NULL —
 * calls no agency owns, e.g. the Article Library). Agency resolution matches
 * AiUsageRecorder exactly (explicit id → auth user's agency → system), so the
 * guard always checks the same bucket the recorder charges.
 *
 * Window: spend is summed since max(now − window, last resume). Without the
 * resume floor a resumed agency would re-trip instantly — the last 24h still
 * holds the spend that paused it.
 *
 * Known limit (spec §A.2): spend is recorded after a call, so one in-flight
 * call can overshoot the cut-out by its own cost. A BATCH of concurrent calls
 * (AnthropicGateway::generateMany) is held to the cut-out up front instead,
 * via reserveBatch() (AT-456 audit D-M3).
 *
 * Failure posture: a broken guard must not take AI down with it, so lookup
 * errors log and allow. The pause itself, once written, always holds.
 *
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §A.
 */
final class AiSpendGuard
{
    // ── Platform settings (Dev Settings → AI Spend Safety, spec §A.4) ──────
    public const KEY_ALERT_ZAR    = 'ai_alert_threshold_zar';
    public const KEY_CUTOUT_ZAR   = 'ai_cutout_threshold_zar';
    public const KEY_WINDOW_HOURS = 'ai_guard_window_hours';
    public const KEY_RECIPIENTS   = 'ai_alert_recipients';
    /** AT-456 audit D-M2: failed-but-paid AI template / company-ad generations an agency may have per day. */
    public const KEY_MAX_FAILED_PER_DAY = 'ai_template_max_failed_per_day';

    public const DEFAULT_ALERT_ZAR    = 30.0;
    public const DEFAULT_CUTOUT_ZAR   = 50.0;
    public const DEFAULT_WINDOW_HOURS = 24;
    public const DEFAULT_RECIPIENTS   = ['andre@corexos.co.za'];
    public const DEFAULT_MAX_FAILED_PER_DAY = 5;

    // ── System-bucket state (no agency row to hold it) ─────────────────────
    public const KEY_SYSTEM_PAUSED_AT       = 'ai_system_paused_at';
    public const KEY_SYSTEM_PAUSED_SPEND    = 'ai_system_paused_spend_zar';
    public const KEY_SYSTEM_RESUMED_AT      = 'ai_system_resumed_at';
    public const KEY_SYSTEM_ALERT_SENT_AT   = 'ai_system_alert_last_sent_at';

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_ALERTED = 'alerted';
    public const STATUS_PAUSED  = 'paused';

    /** After a failed alert email, don't try again for this long (a dead SMTP must not slow every AI call). */
    private const ALERT_MAIL_RETRY_MINUTES = 15;

    private const LOCK_SECONDS      = 10;
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * @return array{alert_zar: float, cutout_zar: float, window_hours: int, recipients: array<int, string>, max_failed_per_day: int}
     */
    public function settings(): array
    {
        $alert  = (float) DevSetting::get(self::KEY_ALERT_ZAR, (string) self::DEFAULT_ALERT_ZAR);
        $cutout = (float) DevSetting::get(self::KEY_CUTOUT_ZAR, (string) self::DEFAULT_CUTOUT_ZAR);
        $window = (int) DevSetting::get(self::KEY_WINDOW_HOURS, (string) self::DEFAULT_WINDOW_HOURS);
        $failed = (int) DevSetting::get(self::KEY_MAX_FAILED_PER_DAY, (string) self::DEFAULT_MAX_FAILED_PER_DAY);

        $raw        = DevSetting::get(self::KEY_RECIPIENTS);
        $decoded    = $raw === null ? self::DEFAULT_RECIPIENTS : json_decode((string) $raw, true);
        $recipients = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : self::DEFAULT_RECIPIENTS;

        return [
            'alert_zar'    => $alert > 0 ? $alert : self::DEFAULT_ALERT_ZAR,
            'cutout_zar'   => $cutout > 0 ? $cutout : self::DEFAULT_CUTOUT_ZAR,
            'window_hours' => $window > 0 ? $window : self::DEFAULT_WINDOW_HOURS,
            'recipients'   => $recipients,
            'max_failed_per_day' => $failed > 0 ? $failed : self::DEFAULT_MAX_FAILED_PER_DAY,
        ];
    }

    /**
     * Refuse the call when the bucket is paused. Every Anthropic caller calls
     * this before spending (enforced by AiSpendGuardCoverageTest).
     *
     * @param int|null $agencyId Explicit agency; null → auth user's agency → system bucket.
     * @param bool     $system   AT-456 Part C — check the platform/system bucket (agency NULL) no
     *                           matter who is signed in. Pass it for work no agency owns, so an
     *                           owner pressing a button is never checked against their own agency.
     *
     * @throws AiPausedException
     */
    public function assertAllowed(?int $agencyId = null, bool $system = false): void
    {
        $bucket = $this->resolveAgencyId($agencyId, $system);

        if ($this->isPaused($bucket)) {
            throw new AiPausedException($bucket);
        }
    }

    /**
     * Whether the bucket is paused right now. $agencyId is already resolved:
     * null means the system bucket here, NOT "resolve from auth".
     */
    public function isPaused(?int $agencyId): bool
    {
        try {
            if ($agencyId === null) {
                return $this->toCarbon(DevSetting::get(self::KEY_SYSTEM_PAUSED_AT)) !== null;
            }

            return DB::table('agencies')
                ->where('id', $agencyId)
                ->whereNotNull('ai_paused_at')
                ->exists();
        } catch (Throwable $e) {
            Log::warning('AiSpendGuard: pause lookup failed — allowing the call', [
                'agency_id' => $agencyId,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Trip the alert / cut-out if the bucket's windowed spend has reached them.
     * Called by AiUsageRecorder after each costed write. Never throws.
     *
     * @param int|null $agencyId Already resolved (null = system bucket).
     */
    public function evaluate(?int $agencyId): void
    {
        $afterLock = null;

        try {
            $afterLock = Cache::lock('ai-spend-guard:' . ($agencyId ?? 'system'), self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->evaluateLocked($agencyId));
        } catch (LockTimeoutException) {
            // Another request is evaluating this bucket right now on near-identical
            // data — it will trip whatever needs tripping.
        } catch (Throwable $e) {
            Log::warning('AiSpendGuard: evaluate failed', [
                'agency_id' => $agencyId,
                'error'     => $e->getMessage(),
            ]);
        }

        // The alert / cut-out EMAIL goes out only now, after the lock is released (AT-456 audit D-L3):
        // a slow SMTP server must never hold the lock past its 10 s expiry, which would make other
        // evaluations give up silently and let a second one in before the alert was recorded.
        if ($afterLock instanceof \Closure) {
            try {
                $afterLock();
            } catch (Throwable $e) {
                Log::warning('AiSpendGuard: sending the spend email failed', [
                    'agency_id' => $agencyId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Reserve room for a batch of calls that will run at the same time (AT-456 audit D-M3).
     *
     * Spend is only recorded after a call comes back, so a batch of N concurrent calls (the AI Template
     * Builder asks one call per ticked property type) would all pass the pause check together and
     * overshoot the cut-out by far more than "one in-flight call". Before such a batch starts, this
     * compares the window spend + the maximum cost of batches already reserved but not yet recorded + this
     * batch's own conservative maximum cost against the cut-out, inside the guard's lock. If it would
     * cross, nothing is reserved and AiPausedException is thrown (the existing "AI paused" path).
     *
     * A single call is unaffected - it keeps going through assertAllowed() / the pause check only.
     *
     * @param  int|null $agencyId           Already resolved (null = system bucket).
     * @param  float    $estimatedMaxZar    The batch's worst-case cost (all tokens used, every call).
     * @param  int      $holdSeconds        Safety expiry for the reservation if release is never reached.
     * @return string   Token for releaseBatch(); '' when the guard itself is unavailable (fail open).
     * @throws AiPausedException
     */
    public function reserveBatch(?int $agencyId, float $estimatedMaxZar, int $holdSeconds = 300): string
    {
        $token = '';

        try {
            Cache::lock('ai-spend-guard:' . ($agencyId ?? 'system'), self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($agencyId, $estimatedMaxZar, $holdSeconds, &$token) {
                    if ($this->isPausedFresh($agencyId)) {
                        throw new AiPausedException($agencyId);
                    }

                    $cutout   = $this->settings()['cutout_zar'];
                    $reserved = array_sum(array_column($this->liveReservations($agencyId), 0));

                    if ($this->windowSpend($agencyId) + $reserved + $estimatedMaxZar >= $cutout) {
                        Log::warning('AiSpendGuard: batch refused - it could cross the cut-out', [
                            'agency_id'  => $agencyId,
                            'estimate'   => round($estimatedMaxZar, 2),
                            'reserved'   => round($reserved, 2),
                            'cutout_zar' => $cutout,
                        ]);

                        throw new AiPausedException($agencyId);
                    }

                    $token = bin2hex(random_bytes(8));
                    $all   = $this->liveReservations($agencyId);
                    $all[$token] = [$estimatedMaxZar, time() + max(30, $holdSeconds)];
                    Cache::put($this->reservationKey($agencyId), $all, now()->addSeconds(max(30, $holdSeconds) + 60));
                });
        } catch (AiPausedException $e) {
            throw $e;
        } catch (LockTimeoutException) {
            Log::warning('AiSpendGuard: could not lock the bucket to reserve a batch - allowing it', ['agency_id' => $agencyId]);
        } catch (Throwable $e) {
            Log::warning('AiSpendGuard: batch reservation failed - allowing the batch', ['agency_id' => $agencyId, 'error' => $e->getMessage()]);
        }

        return $token;
    }

    /**
     * Release a batch reservation once its calls have been recorded in the ledger (or have failed).
     */
    public function releaseBatch(?int $agencyId, string $token): void
    {
        if ($token === '') {
            return;
        }

        try {
            Cache::lock('ai-spend-guard:' . ($agencyId ?? 'system'), self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($agencyId, $token) {
                    $all = $this->liveReservations($agencyId);
                    unset($all[$token]);
                    if ($all === []) {
                        Cache::forget($this->reservationKey($agencyId));
                    } else {
                        Cache::put($this->reservationKey($agencyId), $all, now()->addMinutes(10));
                    }
                });
        } catch (Throwable $e) {
            // Best effort: the reservation expires on its own.
            Log::warning('AiSpendGuard: could not release a batch reservation', ['agency_id' => $agencyId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, array{0: float, 1: int}> token => [max cost, expires at (unix)]
     */
    private function liveReservations(?int $agencyId): array
    {
        $all = Cache::get($this->reservationKey($agencyId), []);
        if (! is_array($all)) {
            return [];
        }

        $now = time();

        return array_filter($all, fn ($r) => is_array($r) && isset($r[0], $r[1]) && (int) $r[1] > $now);
    }

    private function reservationKey(?int $agencyId): string
    {
        return 'ai-spend-guard:reserved:' . ($agencyId ?? 'system');
    }

    /**
     * The owner's "Turn AI back on". Clears the pause, starts a fresh counting
     * window from now, re-arms the alert, and audits who did it.
     *
     * @param int|null $agencyId null = the system bucket.
     */
    public function resume(?int $agencyId, User $by): void
    {
        $spendAtResume = $this->windowSpend($agencyId);
        $now           = Carbon::now();

        if ($agencyId === null) {
            DevSetting::set(self::KEY_SYSTEM_PAUSED_AT, null);
            DevSetting::set(self::KEY_SYSTEM_PAUSED_SPEND, null);
            DevSetting::set(self::KEY_SYSTEM_ALERT_SENT_AT, null);
            DevSetting::set(self::KEY_SYSTEM_RESUMED_AT, $now->toDateTimeString());
        } else {
            DB::table('agencies')->where('id', $agencyId)->update([
                'ai_paused_at'          => null,
                'ai_paused_reason'      => null,
                'ai_paused_spend_zar'   => null,
                'ai_alert_last_sent_at' => null,
                'ai_resumed_at'         => $now,
            ]);
        }

        $this->clearFallbackNarratives($agencyId);
        Cache::forget($this->alertRetryKey($agencyId)); // re-armed: the next alert must not wait out a back-off

        AiSpendGuardEvent::create([
            'agency_id'   => $agencyId,
            'type'        => AiSpendGuardEvent::TYPE_RESUME,
            'spend_zar'   => $spendAtResume,
            'user_id'     => $by->id,
            'occurred_at' => $now,
        ]);
    }

    /**
     * After a resume, drop the bucket's cached "(fallback)" narratives (soft delete) so the
     * next request calls the AI instead of re-serving the templated text the pause produced.
     * Good cached narratives are untouched. Best-effort: the resume itself must never fail on it.
     */
    private function clearFallbackNarratives(?int $agencyId): void
    {
        try {
            AINarrativeCache::query()
                ->when(
                    $agencyId === null,
                    fn ($q) => $q->whereNull('agency_id'),
                    fn ($q) => $q->where('agency_id', $agencyId),
                )
                ->where('model', 'like', '%(fallback)')
                ->delete();
        } catch (Throwable $e) {
            Log::warning('AiSpendGuard: could not clear fallback narratives after resume', [
                'agency_id' => $agencyId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start of the counting window: max(now − window, last resume).
     */
    public function windowStart(?int $agencyId): Carbon
    {
        $start     = Carbon::now()->subHours($this->settings()['window_hours']);
        $resumedAt = $this->resumedAt($agencyId);

        return $resumedAt !== null && $resumedAt->greaterThan($start) ? $resumedAt : $start;
    }

    /**
     * Spend the guard is counting for this bucket right now.
     */
    public function windowSpend(?int $agencyId): float
    {
        return (float) DB::table('ai_usage_events')
            ->when(
                $agencyId === null,
                fn ($q) => $q->whereNull('agency_id'),
                fn ($q) => $q->where('agency_id', $agencyId),
            )
            ->where('occurred_at', '>=', $this->windowStart($agencyId))
            ->sum('cost_zar');
    }

    /**
     * Active · Alerted · Paused, for the AI usage page.
     */
    public function status(?int $agencyId): string
    {
        if ($this->isPaused($agencyId)) {
            return self::STATUS_PAUSED;
        }

        $sentAt = $this->alertSentAt($agencyId);
        $cutoff = Carbon::now()->subHours($this->settings()['window_hours']);

        return $sentAt !== null && $sentAt->greaterThanOrEqualTo($cutoff)
            ? self::STATUS_ALERTED
            : self::STATUS_ACTIVE;
    }

    /**
     * Spend + status for one agency from its already-loaded row — the AI usage
     * list renders one per agency, so this avoids re-reading the guard columns.
     *
     * @return array{spend_zar: float, status: string, window_start: Carbon, since_resume: bool}
     */
    public function snapshot(Agency $agency): array
    {
        $settings    = $this->settings();
        $windowFloor = Carbon::now()->subHours($settings['window_hours']);
        $resumedAt   = $agency->ai_resumed_at;
        $sinceResume = $resumedAt !== null && $resumedAt->greaterThan($windowFloor);
        $start       = $sinceResume ? $resumedAt : $windowFloor;

        $spend = (float) DB::table('ai_usage_events')
            ->where('agency_id', $agency->id)
            ->where('occurred_at', '>=', $start)
            ->sum('cost_zar');

        $status = match (true) {
            $agency->ai_paused_at !== null => self::STATUS_PAUSED,
            $agency->ai_alert_last_sent_at !== null
                && $agency->ai_alert_last_sent_at->greaterThanOrEqualTo($windowFloor) => self::STATUS_ALERTED,
            default => self::STATUS_ACTIVE,
        };

        return [
            'spend_zar'    => $spend,
            'status'       => $status,
            'window_start' => $start,
            'since_resume' => $sinceResume,
        ];
    }

    /**
     * Same resolution as AiUsageRecorder::record(): explicit → auth → null (system).
     * $system = true short-circuits to the system bucket (null) — same as the recorder's flag.
     */
    public function resolveAgencyId(?int $agencyId, bool $system = false): ?int
    {
        if ($system) {
            return null;
        }

        if ($agencyId !== null) {
            return $agencyId;
        }

        $user = Auth::user();
        if ($user === null) {
            return null;
        }
        if (method_exists($user, 'effectiveAgencyId')) {
            $resolved = $user->effectiveAgencyId();

            return $resolved !== null ? (int) $resolved : null;
        }

        return isset($user->agency_id) ? (int) $user->agency_id : null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Decide and WRITE the state (alert-sent marker, pause) under the lock; return the email to send once the
     * lock is released, if any.
     */
    private function evaluateLocked(?int $agencyId): ?\Closure
    {
        if ($this->isPausedFresh($agencyId)) {
            return null; // already cut out — calls are refused; nothing more to trip
        }

        $settings = $this->settings();
        $spend    = $this->windowSpend($agencyId);
        $now      = Carbon::now();

        if ($spend >= $settings['cutout_zar']) {
            return $this->pause($agencyId, $spend, $settings, $now);
        }

        if ($spend < $settings['alert_zar']) {
            return null;
        }

        $sentAt = $this->alertSentAtFresh($agencyId);
        if ($sentAt !== null && $sentAt->greaterThan($now->copy()->subHours($settings['window_hours']))) {
            return null; // at most one alert per bucket per window
        }

        // A failed send must not use up the once-per-window alert: after a failure, back off
        // (cache, not DB, so a dead mail server costs one attempt per interval rather than one
        // per AI call) and try again once the interval has passed.
        $retryKey = $this->alertRetryKey($agencyId);
        if (Cache::has($retryKey)) {
            return null;
        }

        Log::warning('AI SPEND ALERT', [
            'agency_id' => $agencyId,
            'spend_zar' => round($spend, 2),
            'alert_zar' => $settings['alert_zar'],
        ]);

        // Mark the alert sent BEFORE mailing, inside the lock (AT-456 audit D-L3): any evaluation that gets the
        // lock after this sees it and stays quiet, even if the mail itself is slow. The mail is sent after the
        // lock is released; if that send fails the marker is taken back so the alert is retried after the back-off.
        $this->writeAlertSentAt($agencyId, $now);

        return function () use ($agencyId, $spend, $settings, $now, $retryKey): void {
            if (! $this->sendMail(AiSpendAlertMail::KIND_ALERT, $agencyId, $spend, $settings['alert_zar'], $settings)) {
                Cache::put($retryKey, 1, now()->addMinutes(self::ALERT_MAIL_RETRY_MINUTES));
                $this->clearAlertSentAt($agencyId, $now); // not sent after all - the next evaluation after the back-off tries again

                return;
            }

            AiSpendGuardEvent::create([
                'agency_id'     => $agencyId,
                'type'          => AiSpendGuardEvent::TYPE_ALERT,
                'spend_zar'     => $spend,
                'threshold_zar' => $settings['alert_zar'],
                'occurred_at'   => $now,
            ]);
        };
    }

    private function pause(?int $agencyId, float $spend, array $settings, Carbon $now): \Closure
    {
        $reason = sprintf(
            'Spent R %s in the last %d hours (cut-out R %s).',
            number_format($spend, 2),
            $settings['window_hours'],
            number_format($settings['cutout_zar'], 2),
        );

        if ($agencyId === null) {
            DevSetting::set(self::KEY_SYSTEM_PAUSED_AT, $now->toDateTimeString());
            DevSetting::set(self::KEY_SYSTEM_PAUSED_SPEND, (string) round($spend, 4));
        } else {
            DB::table('agencies')->where('id', $agencyId)->update([
                'ai_paused_at'        => $now,
                'ai_paused_reason'    => $reason,
                'ai_paused_spend_zar' => round($spend, 4),
            ]);
        }

        // A cut-out supersedes the alert: mark it sent so no "you reached R30"
        // email follows the "AI is now off" one inside the same window.
        $this->writeAlertSentAt($agencyId, $now);

        AiSpendGuardEvent::create([
            'agency_id'     => $agencyId,
            'type'          => AiSpendGuardEvent::TYPE_PAUSE,
            'spend_zar'     => $spend,
            'threshold_zar' => $settings['cutout_zar'],
            'details'       => ['reason' => $reason],
            'occurred_at'   => $now,
        ]);

        Log::critical('AI SPEND CUT-OUT — AI paused', [
            'agency_id'  => $agencyId,
            'spend_zar'  => round($spend, 2),
            'cutout_zar' => $settings['cutout_zar'],
        ]);

        // The pause is already written; the email goes out after the lock is released (see evaluate()).
        return function () use ($agencyId, $spend, $settings): void {
            $this->sendMail(AiSpendAlertMail::KIND_CUTOUT, $agencyId, $spend, $settings['cutout_zar'], $settings);
        };
    }

    /**
     * Best-effort email. The log line is the guarantee; a mail failure must never
     * undo or mask the pause. Returns whether the mail went out (no recipients
     * configured counts as done — there is nothing a retry could achieve).
     */
    private function sendMail(string $kind, ?int $agencyId, float $spend, float $threshold, array $settings): bool
    {
        if ($settings['recipients'] === []) {
            return true;
        }

        try {
            $agencyName = $agencyId === null
                ? 'Platform (no agency)'
                : (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?? "Agency #{$agencyId}");

            $topSources = DB::table('ai_usage_events')
                ->when(
                    $agencyId === null,
                    fn ($q) => $q->whereNull('agency_id'),
                    fn ($q) => $q->where('agency_id', $agencyId),
                )
                ->where('occurred_at', '>=', $this->windowStart($agencyId))
                ->selectRaw('source, SUM(cost_zar) AS cost')
                ->groupBy('source')
                ->orderByDesc('cost')
                ->limit(3)
                ->pluck('cost', 'source')
                ->map(fn ($c) => (float) $c)
                ->all();

            $usageUrl = $agencyId === null
                ? route('admin.ai-usage.index')
                : route('admin.ai-usage.agency', ['agency' => $agencyId]);

            Mail::to($settings['recipients'])->send(new AiSpendAlertMail(
                kind:        $kind,
                agencyName:  $agencyName,
                spendZar:    $spend,
                thresholdZar: $threshold,
                windowHours: $settings['window_hours'],
                topSources:  $topSources,
                usageUrl:    $usageUrl,
                isSystem:    $agencyId === null,
            ));

            return true;
        } catch (Throwable $e) {
            Log::error('AiSpendGuard: failed to send ' . $kind . ' email: ' . $e->getMessage(), [
                'agency_id' => $agencyId,
            ]);

            return false;
        }
    }

    private function alertRetryKey(?int $agencyId): string
    {
        return 'ai-spend-guard:alert-mail-retry:' . ($agencyId ?? 'system');
    }

    private function resumedAt(?int $agencyId): ?Carbon
    {
        $value = $agencyId === null
            ? DevSetting::get(self::KEY_SYSTEM_RESUMED_AT)
            : DB::table('agencies')->where('id', $agencyId)->value('ai_resumed_at');

        return $this->toCarbon($value);
    }

    private function alertSentAt(?int $agencyId): ?Carbon
    {
        $value = $agencyId === null
            ? DevSetting::get(self::KEY_SYSTEM_ALERT_SENT_AT)
            : DB::table('agencies')->where('id', $agencyId)->value('ai_alert_last_sent_at');

        return $this->toCarbon($value);
    }

    /** Uncached read for use inside the lock — DevSetting::get() caches for an hour. */
    private function isPausedFresh(?int $agencyId): bool
    {
        if ($agencyId === null) {
            return $this->toCarbon(DevSetting::query()->where('key', self::KEY_SYSTEM_PAUSED_AT)->value('value')) !== null;
        }

        return $this->isPaused($agencyId);
    }

    private function alertSentAtFresh(?int $agencyId): ?Carbon
    {
        if ($agencyId === null) {
            return $this->toCarbon(DevSetting::query()->where('key', self::KEY_SYSTEM_ALERT_SENT_AT)->value('value'));
        }

        return $this->alertSentAt($agencyId);
    }

    private function writeAlertSentAt(?int $agencyId, Carbon $at): void
    {
        if ($agencyId === null) {
            DevSetting::set(self::KEY_SYSTEM_ALERT_SENT_AT, $at->toDateTimeString());

            return;
        }

        DB::table('agencies')->where('id', $agencyId)->update(['ai_alert_last_sent_at' => $at]);
    }

    /**
     * Take back the alert-sent marker written by writeAlertSentAt($at) - but only if it is still that value,
     * so a cut-out that superseded the alert in the meantime keeps its own marker.
     */
    private function clearAlertSentAt(?int $agencyId, Carbon $at): void
    {
        $current = $this->alertSentAtFresh($agencyId);
        if ($current === null || $current->toDateTimeString() !== $at->toDateTimeString()) {
            return;
        }

        if ($agencyId === null) {
            DevSetting::set(self::KEY_SYSTEM_ALERT_SENT_AT, null);

            return;
        }

        DB::table('agencies')->where('id', $agencyId)->update(['ai_alert_last_sent_at' => null]);
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse((string) $value);
    }
}
