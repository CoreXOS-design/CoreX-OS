<?php

namespace App\Console\Commands\TitleDeeds;

use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Services\TitleDeeds\TitleDeedNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/title-deed-register.md §8.5 - re-notify the owners of deeds that are still at the office.
 *
 * A deed is due when it is "Owner notified" (not collected, not archived), fewer than the agency's maximum reminders have
 * gone out, and the last notice (or last reminder) is at least the agency's reminder-days old. Idempotent: the counters move
 * under the deed's row lock, so running it twice in a day (or two overlapping runs) sends one reminder, not two. Email only -
 * CoreX cannot send WhatsApp itself. Scheduled daily; QA1 has no scheduler cron, so run it by hand there.
 */
class SendTitleDeedReminders extends Command
{
    protected $signature = 'titledeeds:send-reminders {--dry-run : List what would be sent without sending} {--agency= : Only this agency id}';

    protected $description = 'Remind the owners of title deeds that have not been collected (email, capped, idempotent).';

    public function handle(TitleDeedNotifier $notifier): int
    {
        $dry = (bool) $this->option('dry-run');
        $sent = $skipped = 0;

        $query = TitleDeed::withoutGlobalScopes()
            ->where('status', TitleDeed::STATUS_NOTIFIED)
            ->whereNull('deleted_at')
            ->when($this->option('agency'), fn ($q, $id) => $q->where('agency_id', (int) $id))
            ->orderBy('id');

        $query->chunkById(100, function ($chunk) use ($notifier, $dry, &$sent, &$skipped) {
            foreach ($chunk as $candidate) {
                if (! $this->isDue($candidate)) {
                    $skipped++;
                    continue;
                }

                $this->line(($dry ? '[dry-run] ' : '') . 'deed #' . $candidate->id . ' (' . $candidate->property_display . ') - reminder ' . ($candidate->reminder_count + 1));
                if ($dry) {
                    $sent++;
                    continue;
                }

                // Claim it under the row lock FIRST: a second run (or an overlapping one) finds it no longer due.
                $claimed = DB::transaction(function () use ($candidate) {
                    $locked = TitleDeed::withoutGlobalScopes()->lockForUpdate()->find($candidate->id);
                    if (! $locked || $locked->status !== TitleDeed::STATUS_NOTIFIED || $locked->trashed() || ! $this->isDue($locked)) {
                        return null;
                    }
                    $locked->forceFill(['last_reminder_at' => now(), 'reminder_count' => $locked->reminder_count + 1])->save();

                    return $locked;
                });
                if (! $claimed) {
                    $skipped++;
                    continue;
                }

                try {
                    $summary = $notifier->sendReminder($claimed);
                    TitleDeedAuditLog::record($claimed, TitleDeedAuditLog::EVENT_REMINDER_SENT,
                        'Reminder ' . $claimed->reminder_count . ' sent: ' . $summary['sent'] . ' emailed, ' . $summary['failed'] . ' failed, ' . $summary['skipped'] . ' skipped.');
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                    $this->error('deed #' . $claimed->id . ': ' . $e->getMessage());
                }
            }
        });

        $this->info(($dry ? 'Dry run: ' : '') . $sent . ' reminder' . ($sent === 1 ? '' : 's') . ($dry ? ' due' : ' sent') . ', ' . $skipped . ' deed' . ($skipped === 1 ? '' : 's') . ' not due.');

        return self::SUCCESS;
    }

    private function isDue(TitleDeed $deed): bool
    {
        $max = TitleDeedSetting::maxRemindersFor($deed->agency_id);
        if ($max <= 0 || $deed->reminder_count >= $max) {
            return false;
        }
        $since = $deed->last_reminder_at ?? $deed->notified_at;
        if (! $since) {
            return false;
        }

        return $since->copy()->addDays(TitleDeedSetting::reminderDaysFor($deed->agency_id))->lte(now());
    }
}
