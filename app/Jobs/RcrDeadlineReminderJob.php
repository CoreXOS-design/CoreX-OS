<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Compliance\Rcr\RcrSubmission;
use App\Models\User;
use App\Notifications\Compliance\RcrDeadlineApproachingNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 9d H1 — RCR deadline cadence reminders.
 *
 * Cadence (days remaining):
 *   > 30 days  → silent
 *   8-30 days  → weekly (on day-of-week dispatch)
 *   4-7 days   → every 3 days
 *   1-3 days   → daily
 *   ≤ 0 days   → daily until submitted or admin acknowledged
 *
 * Recipients per submission:
 *   - assigned_co_user_id
 *   - users with role principal in same agency
 *   - users with role super_admin (system-wide oversight)
 *
 * De-dup via the `rcr_reminder_sent_at` cache key — one notification per
 * submission per day max.
 */
final class RcrDeadlineReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $submissions = RcrSubmission::whereIn('status', [
            RcrSubmission::STATUS_DRAFT,
            RcrSubmission::STATUS_IN_REVIEW,
            RcrSubmission::STATUS_APPROVED_FOR_SUBMISSION,
        ])->get();

        foreach ($submissions as $submission) {
            $days = $submission->daysToDeadline();
            if (!$this->shouldSendToday($submission, $days)) {
                continue;
            }
            $this->dispatchToRecipients($submission, $days);
        }
    }

    private function shouldSendToday(RcrSubmission $submission, int $days): bool
    {
        // Past deadline: every day.
        if ($days <= 0) return true;
        // Critical (≤3): every day.
        if ($days <= 3) return true;
        // 4-7: every 3 days (today % 3 == 0 against submission id for spacing).
        if ($days <= 7) return (now()->dayOfYear + $submission->id) % 3 === 0;
        // 8-30: weekly — only Mondays.
        if ($days <= 30) return now()->isMonday();
        // > 30 — silent.
        return false;
    }

    private function dispatchToRecipients(RcrSubmission $submission, int $days): void
    {
        $recipients = User::query()
            ->where('agency_id', $submission->agency_id)
            ->where(function ($q) use ($submission) {
                $q->where('id', $submission->assigned_co_user_id)
                  ->orWhereIn('role', ['principal', 'branch_manager', 'admin']);
            })
            ->where('is_active', true)
            ->distinct('id')
            ->get();

        // System owners — get the notification regardless of agency.
        $owners = User::where('role', 'super_admin')->where('is_active', true)->get();
        $all = $recipients->merge($owners)->unique('id');

        foreach ($all as $user) {
            // De-dup per recipient per submission per day via cache.
            $cacheKey = sprintf('rcr_reminder:%d:%d:%s', $submission->id, $user->id, now()->toDateString());
            if (cache()->has($cacheKey)) continue;
            cache()->put($cacheKey, true, now()->addDay());

            try {
                $user->notify(new RcrDeadlineApproachingNotification($submission->id, $days));
            } catch (\Throwable $e) {
                Log::warning('rcr.deadline.notify_failed', [
                    'submission_id' => $submission->id,
                    'user_id'       => $user->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }
}
