<?php

namespace App\Services\Rentals;

use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalPortalSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Audit fix C-A1 (19 Oct 2026) - an owner who never answers a fault sent to them is chased, in three steps, each ONCE per fault, every timing an agency setting:
 *   1. after `owner_reminder_hours` (default 24) with no answer: the owner is emailed a reminder;
 *   2. `owner_second_reminder_hours` (24) after that: a second reminder;
 *   3. `owner_queue_after_hours` (24) after that: the fault goes on the agent's Command Centre queue ("Owner has not answered - phone them").
 * Only faults still awaiting the owner's answer and NOT paused by the owner. Idempotent: the ledger (`owner_reminder_1_at`, `owner_reminder_2_at`,
 * `owner_queue_flagged_at`) is claimed with one conditional UPDATE before anything is sent, so two scheduler runs cannot both act; one step per fault per run, so
 * a catch-up after downtime never sends both reminders at once.
 *
 * Audit 2026-10-20 (M2): a step is only left recorded as DONE when it really went out. The send is inside a try/catch per fault: a mail transport error undoes the claim
 * (the step is retried on the next run), writes ONE history line saying so, and never stops the other faults or agencies. A fault that was already waiting for the owner
 * when this feature shipped is not chased at once - its clock starts at `owner_chase_from` (set by the migration, and by reopening a decision), see dueStep(). Every step writes a history line the agent can see (also when the owner could not be emailed:
 * RentalPortalNotificationService says why). Scheduled every 30 minutes (routes/console.php).
 */
class RentalOwnerChaseService
{
    /** @return array{reminded_1: int, reminded_2: int, queued: int, skipped_hold: int, failed: int} */
    public function run(?Carbon $now = null, bool $dryRun = false): array
    {
        $now ??= now();
        $out = ['reminded_1' => 0, 'reminded_2' => 0, 'queued' => 0, 'skipped_hold' => 0, 'failed' => 0];
        $holds = app(RentalOwnerHoldService::class);

        RentalFaultReport::withoutGlobalScopes()
            ->where('status', RentalFaultReport::STATUS_AWAITING_APPROVAL)
            ->where('owner_approval_status', RentalFaultReport::APPROVAL_PENDING)
            ->whereNotNull('sent_to_owner_at')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(100, function ($faults) use ($now, $dryRun, $holds, &$out) {
                foreach ($faults as $fault) {
                    $step = $this->dueStep($fault, $now);
                    if ($step === null) {
                        continue;
                    }
                    if ($holds->activeFor($fault)) {
                        $out['skipped_hold']++;   // the owner paused it themselves: nothing is chased, and the clock is not advanced
                        continue;
                    }
                    if ($dryRun) {
                        $out[$step === 3 ? 'queued' : ($step === 2 ? 'reminded_2' : 'reminded_1')]++;
                        continue;
                    }
                    try {
                        if ($this->act($fault, $step, $now)) {
                            $out[$step === 3 ? 'queued' : ($step === 2 ? 'reminded_2' : 'reminded_1')]++;
                        } elseif ($fault->fresh()?->owner_chase_failed_at !== null) {
                            $out['failed']++;
                        }
                    } catch (\Throwable $e) {
                        // one fault's trouble (a broken row, a database error) must never stop the others
                        $out['failed']++;
                        Log::error('Owner chase: a fault could not be processed', ['fault_id' => $fault->id, 'step' => $step, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $out;
    }

    /** The next step that is due for this fault (1, 2 or 3), or null. One step at a time. */
    public function dueStep(RentalFaultReport $fault, Carbon $now): ?int
    {
        $agency = (int) $fault->agency_id;
        // the clock of the current wait: when it was sent - or later, when it was already waiting as this feature shipped / its decision was reopened
        $waitStart = $fault->owner_chase_from !== null && $fault->owner_chase_from->gt($fault->sent_to_owner_at) ? $fault->owner_chase_from : $fault->sent_to_owner_at;
        if ($fault->owner_reminder_1_at === null) {
            return $now->gte($waitStart->copy()->addHours(RentalPortalSetting::ownerReminderHoursFor($agency))) ? 1 : null;
        }
        if ($fault->owner_reminder_2_at === null) {
            return $now->gte($fault->owner_reminder_1_at->copy()->addHours(RentalPortalSetting::ownerSecondReminderHoursFor($agency))) ? 2 : null;
        }
        if ($fault->owner_queue_flagged_at === null) {
            return $now->gte($fault->owner_reminder_2_at->copy()->addHours(RentalPortalSetting::ownerQueueAfterHoursFor($agency))) ? 3 : null;
        }

        return null;
    }

    private function act(RentalFaultReport $fault, int $step, Carbon $now): bool
    {
        $column = [1 => 'owner_reminder_1_at', 2 => 'owner_reminder_2_at', 3 => 'owner_queue_flagged_at'][$step];
        // claim the step first: only one run can win it
        $claimed = RentalFaultReport::withoutGlobalScopes()->whereKey($fault->id)->whereNull($column)->update([$column => $now]);
        if ($claimed === 0) {
            return false;
        }
        $fault = RentalFaultReport::withoutGlobalScopes()->find($fault->id);
        $alreadyFailed = $fault->owner_chase_failed_at !== null;

        try {
            if ($step === 3) {
                $fault->recordHistory(RentalFaultReportUpdate::TYPE_OWNER_SILENT_FLAGGED, null, 'The owner has not answered after two reminders - it is on your Command Centre queue: phone them, or record what they say.');
            } else {
                $result = app(RentalPortalNotificationService::class)->notifyLandlordDecisionNeeded($fault, $step);
                if ($result['sent'] > 0) {
                    $fault->recordHistory(RentalFaultReportUpdate::TYPE_OWNER_REMINDED, null, ($step === 1 ? 'First' : 'Second') . ' reminder emailed to the owner (' . $result['sent'] . ($result['sent'] === 1 ? ' address' : ' addresses') . ') - no answer yet.'
                        . (($result['failed'] ?? 0) > 0 ? ' ' . $result['failed'] . ' address' . ($result['failed'] === 1 ? '' : 'es') . ' could not be reached - phone that owner.' : ''));
                }
                // (a reminder that could not go out because the agency switched the email off, or no owner has an address, was already written to the history by
                //  notifyLandlordDecisionNeeded with the reason - that is a decision the agent can see, not a failure, so the step stays done)
            }
        } catch (\Throwable $e) {
            // NOT sent: take the claim back so the next run tries again - a reminder is never recorded as done when it never went out
            RentalFaultReport::withoutGlobalScopes()->whereKey($fault->id)->where($column, $now)->update([$column => null, 'owner_chase_failed_at' => $now]);
            Log::warning('Owner chase: the reminder could not be sent, it will be tried again', ['fault_id' => $fault->id, 'step' => $step, 'error' => $e->getMessage()]);
            if (! $alreadyFailed) {   // one line per outage, not one every 30 minutes
                try {
                    $fault->recordHistory(RentalFaultReportUpdate::TYPE_OWNER_NOT_TOLD, null, ($step === 3 ? 'The queue flag' : ($step === 1 ? 'The first owner reminder' : 'The second owner reminder')) . ' could not be sent because of a technical problem - it will be tried again at the next check. If it keeps failing, phone the owner.');
                } catch (\Throwable) {
                    // the history line is a courtesy; the retry above is what matters
                }
            }

            return false;
        }

        if ($alreadyFailed) {
            RentalFaultReport::withoutGlobalScopes()->whereKey($fault->id)->update(['owner_chase_failed_at' => null]);
        }

        return true;
    }
}
