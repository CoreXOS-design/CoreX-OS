<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\AiPausedException;
use App\Mail\AiSpendAlertMail;
use App\Models\AI\AiSpendGuardEvent;
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
 * call can overshoot the cut-out by its own cost.
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

    public const DEFAULT_ALERT_ZAR    = 30.0;
    public const DEFAULT_CUTOUT_ZAR   = 50.0;
    public const DEFAULT_WINDOW_HOURS = 24;
    public const DEFAULT_RECIPIENTS   = ['andre@corexos.co.za'];

    // ── System-bucket state (no agency row to hold it) ─────────────────────
    public const KEY_SYSTEM_PAUSED_AT       = 'ai_system_paused_at';
    public const KEY_SYSTEM_PAUSED_SPEND    = 'ai_system_paused_spend_zar';
    public const KEY_SYSTEM_RESUMED_AT      = 'ai_system_resumed_at';
    public const KEY_SYSTEM_ALERT_SENT_AT   = 'ai_system_alert_last_sent_at';

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_ALERTED = 'alerted';
    public const STATUS_PAUSED  = 'paused';

    private const LOCK_SECONDS      = 10;
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * @return array{alert_zar: float, cutout_zar: float, window_hours: int, recipients: array<int, string>}
     */
    public function settings(): array
    {
        $alert  = (float) DevSetting::get(self::KEY_ALERT_ZAR, (string) self::DEFAULT_ALERT_ZAR);
        $cutout = (float) DevSetting::get(self::KEY_CUTOUT_ZAR, (string) self::DEFAULT_CUTOUT_ZAR);
        $window = (int) DevSetting::get(self::KEY_WINDOW_HOURS, (string) self::DEFAULT_WINDOW_HOURS);

        $raw        = DevSetting::get(self::KEY_RECIPIENTS);
        $decoded    = $raw === null ? self::DEFAULT_RECIPIENTS : json_decode((string) $raw, true);
        $recipients = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : self::DEFAULT_RECIPIENTS;

        return [
            'alert_zar'    => $alert > 0 ? $alert : self::DEFAULT_ALERT_ZAR,
            'cutout_zar'   => $cutout > 0 ? $cutout : self::DEFAULT_CUTOUT_ZAR,
            'window_hours' => $window > 0 ? $window : self::DEFAULT_WINDOW_HOURS,
            'recipients'   => $recipients,
        ];
    }

    /**
     * Refuse the call when the bucket is paused. Every Anthropic caller calls
     * this before spending (enforced by AiSpendGuardCoverageTest).
     *
     * @param int|null $agencyId Explicit agency; null → auth user's agency → system bucket.
     *
     * @throws AiPausedException
     */
    public function assertAllowed(?int $agencyId = null): void
    {
        $bucket = $this->resolveAgencyId($agencyId);

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
        try {
            Cache::lock('ai-spend-guard:' . ($agencyId ?? 'system'), self::LOCK_SECONDS)
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

        AiSpendGuardEvent::create([
            'agency_id'   => $agencyId,
            'type'        => AiSpendGuardEvent::TYPE_RESUME,
            'spend_zar'   => $spendAtResume,
            'user_id'     => $by->id,
            'occurred_at' => $now,
        ]);
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
     */
    public function resolveAgencyId(?int $agencyId): ?int
    {
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

    private function evaluateLocked(?int $agencyId): void
    {
        if ($this->isPausedFresh($agencyId)) {
            return; // already cut out — calls are refused; nothing more to trip
        }

        $settings = $this->settings();
        $spend    = $this->windowSpend($agencyId);
        $now      = Carbon::now();

        if ($spend >= $settings['cutout_zar']) {
            $this->pause($agencyId, $spend, $settings, $now);

            return;
        }

        if ($spend < $settings['alert_zar']) {
            return;
        }

        $sentAt = $this->alertSentAtFresh($agencyId);
        if ($sentAt !== null && $sentAt->greaterThan($now->copy()->subHours($settings['window_hours']))) {
            return; // at most one alert per bucket per window
        }

        $this->writeAlertSentAt($agencyId, $now);

        AiSpendGuardEvent::create([
            'agency_id'     => $agencyId,
            'type'          => AiSpendGuardEvent::TYPE_ALERT,
            'spend_zar'     => $spend,
            'threshold_zar' => $settings['alert_zar'],
            'occurred_at'   => $now,
        ]);

        Log::warning('AI SPEND ALERT', [
            'agency_id' => $agencyId,
            'spend_zar' => round($spend, 2),
            'alert_zar' => $settings['alert_zar'],
        ]);

        $this->sendMail(AiSpendAlertMail::KIND_ALERT, $agencyId, $spend, $settings['alert_zar'], $settings);
    }

    private function pause(?int $agencyId, float $spend, array $settings, Carbon $now): void
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

        $this->sendMail(AiSpendAlertMail::KIND_CUTOUT, $agencyId, $spend, $settings['cutout_zar'], $settings);
    }

    /**
     * Best-effort email. The log line above is the guarantee; a mail failure
     * must never undo or mask the pause.
     */
    private function sendMail(string $kind, ?int $agencyId, float $spend, float $threshold, array $settings): void
    {
        if ($settings['recipients'] === []) {
            return;
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
            ));
        } catch (Throwable $e) {
            Log::error('AiSpendGuard: failed to send ' . $kind . ' email: ' . $e->getMessage(), [
                'agency_id' => $agencyId,
            ]);
        }
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

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse((string) $value);
    }
}
