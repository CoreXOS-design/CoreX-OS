<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\RentalInspectionDueNotice;
use App\Models\RentalInspectionPlannedDate;
use App\Models\RentalInspectionPlannedDateNotice;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use App\Notifications\RentalInspectionDueReminder;
use App\Services\CommandCenter\NotificationDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-inspections.md §45.7 items 3-4 (Build I-5) — the two daily reminder runs, as one service the two
 * commands (rentals:send-planned-inspection-reminders, rentals:send-due-inspection-reminders) call.
 *
 * Rules, shared by both:
 *  - The responsible agent only: the property's agent, else the lease creator. NEVER a tenant or landlord.
 *  - One append-only notice row per milestone (lead | due | overdue); the unique key makes a re-run, or a catch-up after a
 *    missed cron tick, harmless — unlike the exact-day match SendRentalInspectionReminders uses.
 *  - Catch-up sends ONLY the latest milestone reached; earlier unsent ones are recorded as 'skipped' (superseded), so a
 *    missed week is one email, not three.
 *  - GO-LIVE SAFETY, so loading or deploying a backlog never floods an agent:
 *      planned dates — a milestone only fires if its day is AFTER the day the date was loaded (a date loaded for the past, or
 *      for today, never mails in bulk; it still shows as overdue on the list);
 *      In/Out due items — a milestone older than CATCH_UP_DAYS is recorded as skipped, not sent (a lease that was already
 *      overdue when this ran first stays on the Due tab and the Command Centre without 50 emails).
 *  - A date on a lease that is no longer ACTIVE is never mailed.
 */
class RentalInspectionDueReminderService
{
    /** Operational go-live guard for the computed In/Out items (not an agency preference). */
    public const CATCH_UP_DAYS = 3;

    /** A dry run counts what WOULD be sent and writes/sends nothing (set per run by runPlanned()/runDue()). */
    private bool $dryRun = false;

    /** The day being run (set by runPlanned()/runDue()); the gateway's threshold_hit_at for every reminder sent in this run. */
    private ?CarbonInterface $runDay = null;

    public function __construct(private RentalInspectionDueService $due) {}

    /**
     * @param  int|null  $onlyAgencyId  run for this agency only (a hand run on a shared box must not walk every agency)
     * @param  bool  $dryRun  count only — no notice rows, no notifications
     * @return array{sent:int, skipped:int, failed:int}
     */
    public function runPlanned(?CarbonInterface $today = null, ?int $onlyAgencyId = null, bool $dryRun = false): array
    {
        $this->dryRun = $dryRun;
        $today = ($today ?? now())->copy()->startOfDay();
        $this->runDay = $today;
        $tally = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $agencyIds = RentalInspectionPlannedDate::withoutGlobalScopes()
            ->whereNull('deleted_at')->whereIn('status', RentalInspectionPlannedDate::OPEN_STATUSES)
            ->when($onlyAgencyId, fn ($q) => $q->where('agency_id', $onlyAgencyId))
            ->distinct()->pluck('agency_id');

        foreach ($agencyIds as $agencyId) {
            $lead = RentalInspectionSetting::plannedDateLeadDaysFor((int) $agencyId);

            RentalInspectionPlannedDate::withoutGlobalScopes()
                ->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->whereIn('status', RentalInspectionPlannedDate::OPEN_STATUSES)
                ->with(['lease' => fn ($q) => $q->withoutGlobalScopes()->with(['property' => fn ($p) => $p->withoutGlobalScopes()])])
                ->chunkById(200, function ($dates) use ($lead, $today, &$tally) {
                    foreach ($dates as $date) {
                        $this->handlePlanned($date, $lead, $today, $tally);
                    }
                });
        }

        return $tally;
    }

    /**
     * @param  int|null  $onlyAgencyId  run for this agency only
     * @param  bool  $dryRun  count only — no notice rows, no notifications
     * @return array{sent:int, skipped:int, failed:int}
     */
    public function runDue(?CarbonInterface $today = null, ?int $onlyAgencyId = null, bool $dryRun = false): array
    {
        $this->dryRun = $dryRun;
        $today = ($today ?? now())->copy()->startOfDay();
        $this->runDay = $today;
        $tally = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $agencyIds = Lease::withoutGlobalScopes()->whereNull('deleted_at')->where('status', Lease::STATUS_ACTIVE)
            ->when($onlyAgencyId, fn ($q) => $q->where('agency_id', $onlyAgencyId))
            ->distinct()->pluck('agency_id');

        foreach ($agencyIds as $agencyId) {
            if (! RentalInspectionSetting::raiseDueInspectionsEnabledFor((int) $agencyId)) {
                continue;
            }
            $outLead = RentalInspectionSetting::outDueLeadDaysFor((int) $agencyId);

            foreach ($this->due->inOutItemsFor((int) $agencyId, null, $today) as $item) {
                $lead = $item['type'] === RentalInspectionDueService::TYPE_OUT ? $outLead : 0;
                $reached = $this->due->milestonesReached($item['due_on'], $lead, $today);
                if ($reached === []) {
                    continue;
                }
                $done = RentalInspectionDueNotice::withoutGlobalScopes()
                    ->where('lease_id', $item['lease_id'])->where('type', $item['type'])
                    ->whereDate('due_on', $item['due_on']->toDateString())
                    ->pluck('milestone')->all();
                $pending = array_diff_key($reached, array_flip($done));
                if ($pending === []) {
                    continue;
                }
                $latest = array_key_last($pending);

                foreach ($pending as $milestone => $day) {
                    $skipReason = null;
                    if ($milestone !== $latest) {
                        $skipReason = 'superseded by a later reminder';
                    } elseif ($day->diffInDays($today) > self::CATCH_UP_DAYS) {
                        $skipReason = 'already past before reminders began — see the Due list';
                    }
                    $this->record(
                        fn (array $attrs) => RentalInspectionDueNotice::withoutGlobalScopes()->create($attrs + [
                            'lease_id' => $item['lease_id'], 'type' => $item['type'], 'due_on' => $item['due_on']->toDateString(),
                        ]),
                        (int) $agencyId, (string) $milestone, $item['lease'], $skipReason, $tally,
                        ['type' => $item['type'], 'due_on' => $item['due_on']->toDateString(), 'source' => 'due_list', 'lease_id' => $item['lease_id']],
                    );
                }
            }
        }

        return $tally;
    }

    private function handlePlanned(RentalInspectionPlannedDate $date, int $lead, CarbonInterface $today, array &$tally): void
    {
        $lease = $date->lease;
        if (! $lease || $lease->status !== Lease::STATUS_ACTIVE || $lease->deleted_at !== null) {
            return; // "lease ended" — shown on the list, never mailed
        }

        $reached = $this->due->milestonesReached($date->planned_on, $lead, $today);
        if ($reached === []) {
            return;
        }
        $done = RentalInspectionPlannedDateNotice::withoutGlobalScopes()->where('planned_date_id', $date->id)->pluck('milestone')->all();
        $pending = array_diff_key($reached, array_flip($done));
        if ($pending === []) {
            return;
        }
        $latest = array_key_last($pending);
        $loadedOn = $date->created_at ? $date->created_at->copy()->startOfDay() : null;

        foreach ($pending as $milestone => $day) {
            $skipReason = null;
            if ($milestone !== $latest) {
                $skipReason = 'superseded by a later reminder';
            } elseif ($loadedOn && ! $day->gt($loadedOn)) {
                $skipReason = 'date was loaded on or after this reminder day';
            }
            $this->record(
                fn (array $attrs) => RentalInspectionPlannedDateNotice::withoutGlobalScopes()->create($attrs + ['planned_date_id' => $date->id]),
                (int) $date->agency_id, (string) $milestone, $lease, $skipReason, $tally,
                ['type' => $date->type, 'due_on' => $date->planned_on->toDateString(), 'source' => 'planned_date', 'lease_id' => $lease->id],
            );
        }
    }

    /**
     * Write the notice row and, unless it is being skipped, send. The row is written for every handled milestone — that is
     * what makes the run idempotent. A send that throws is recorded as 'failed' (visible, never retried in a loop) and
     * logged; it never aborts the run for the other items.
     *
     * @param  callable(array): mixed  $create  creates the notice row from the common attributes
     */
    private function record(callable $create, int $agencyId, string $milestone, Lease $lease, ?string $skipReason, array &$tally, array $payload): void
    {
        $common = ['agency_id' => $agencyId, 'milestone' => $milestone, 'created_at' => now()];

        if ($this->dryRun) {
            // Same decision the real run takes, minus every side effect.
            $agentId = $skipReason === null ? $this->due->responsibleAgentId($lease) : null;
            $agent = $agentId ? User::withoutGlobalScopes()->find($agentId) : null;
            $tally[$skipReason !== null || ! $agent || ! $agent->is_active ? 'skipped' : 'sent']++;

            return;
        }

        if ($skipReason !== null) {
            $create($common + ['recipient_user_id' => null, 'channel' => null, 'status' => 'skipped', 'detail' => $skipReason]);
            $tally['skipped']++;

            return;
        }

        $agentId = $this->due->responsibleAgentId($lease);
        $agent = $agentId ? User::withoutGlobalScopes()->find($agentId) : null;
        if (! $agent || ! $agent->is_active) {
            $create($common + ['recipient_user_id' => $agent?->id, 'channel' => null, 'status' => 'skipped', 'detail' => 'no active agent to remind']);
            $tally['skipped']++;

            return;
        }

        try {
            $delivered = app(NotificationDispatcher::class)->send(
                $agent, 'rental_inspection.due_reminder', $lease,
                new RentalInspectionDueReminder(
                    inspectionType: $payload['type'],
                    milestone: $milestone,
                    dueOn: $payload['due_on'],
                    propertyAddress: $lease->property?->buildDisplayAddress() ?? 'Unknown property',
                    leaseId: $lease->id,
                    source: $payload['source'],
                    url: route('corex.rental-inspections.due'),
                ),
                // threshold_hit_at is the day this milestone was handled - a stable fact, not now(): the gateway's
                // once-only check compares it, and the reminder's own notice row stays the real idempotency.
                ['threshold_hit_at' => $this->runDay ?? now()->startOfDay()],
            );
            if (! $delivered) {
                // The agent switched this reminder off (or it is outside their open hours): not an error, and the
                // milestone is still handled once - the row below is what stops it being retried.
                $create($common + ['recipient_user_id' => $agent->id, 'channel' => null, 'status' => 'skipped', 'detail' => "switched off in the agent's notification settings, or outside their open hours"]);
                $tally['skipped']++;

                return;
            }
            $create($common + [
                'recipient_user_id' => $agent->id,
                'channel' => $agent->email ? 'in_app,mail' : 'in_app',
                'status' => 'sent',
                'detail' => $agent->email ? null : 'no email address on file — in-app only',
            ]);
            $tally['sent']++;
        } catch (\Throwable $e) {
            Log::warning('Rental inspection due reminder failed', ['lease_id' => $lease->id, 'milestone' => $milestone, 'error' => $e->getMessage()]);
            $create($common + ['recipient_user_id' => $agent->id, 'channel' => null, 'status' => 'failed', 'detail' => mb_substr($e->getMessage(), 0, 250)]);
            $tally['failed']++;
        }
    }
}
