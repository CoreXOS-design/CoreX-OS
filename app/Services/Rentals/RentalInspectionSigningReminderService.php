<?php

namespace App\Services\Rentals;

use App\Models\RentalInspection;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSigningNotice;
use App\Models\User;
use App\Notifications\RentalInspectionSigningReminder;
use App\Services\CommandCenter\NotificationDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-inspections.md §52 — the daily signing-window reminder (command `rentals:send-signing-window-reminders`).
 *
 * - Only reports waiting for signatures (`awaiting_signature`, not archived) whose window has a closing day and who still have
 *   someone to sign (a tenant or the landlord with no outcome). The agent's own signature never triggers it.
 * - Two milestones per inspection: `lead` (from N days before the closing day, N = the agency's setting; 0 = no lead reminder)
 *   and `passed` (the day after). One append-only notice row each (UNIQUE inspection+milestone) — a re-run, or a catch-up after
 *   a missed tick, is harmless. A catch-up sends only the latest milestone reached; earlier ones are recorded as skipped.
 * - GO-LIVE SAFETY: a milestone older than CATCH_UP_DAYS is recorded as skipped, never mailed, so switching this on cannot flood
 *   an agent with reminders for reports that closed weeks ago.
 * - The agent only: the inspector, else whoever created the inspection. Never a tenant or landlord. Per agency, switchable
 *   (`signing_window_reminders_enabled`, default on). Nothing is done to the inspection.
 */
class RentalInspectionSigningReminderService
{
    /** Operational go-live guard (not an agency preference). */
    public const CATCH_UP_DAYS = 3;

    /** @return array{sent:int, skipped:int, failed:int} */
    public function run(?CarbonInterface $today = null, ?int $onlyAgencyId = null, bool $dryRun = false): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $tally = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $agencyIds = RentalInspection::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('status', RentalInspection::STATUS_AWAITING_SIGNATURE)->whereNotNull('signing_deadline_at')
            ->when($onlyAgencyId, fn ($q) => $q->where('agency_id', $onlyAgencyId))
            ->distinct()->pluck('agency_id');

        foreach ($agencyIds as $agencyId) {
            if (! RentalInspectionSetting::ruleFor((int) $agencyId, 'signing_window_reminders_enabled')) {
                continue;
            }
            $lead = RentalInspectionSetting::signingReminderLeadDaysFor((int) $agencyId);

            RentalInspection::withoutGlobalScopes()->whereNull('deleted_at')
                ->where('agency_id', $agencyId)->where('status', RentalInspection::STATUS_AWAITING_SIGNATURE)->whereNotNull('signing_deadline_at')
                ->with(['property' => fn ($p) => $p->withoutGlobalScopes(), 'inspector' => fn ($u) => $u->withoutGlobalScopes(), 'createdBy' => fn ($u) => $u->withoutGlobalScopes()])
                ->chunkById(200, function ($inspections) use ($lead, $today, &$tally, $dryRun) {
                    foreach ($inspections as $inspection) {
                        $this->handle($inspection, $lead, $today, $tally, $dryRun);
                    }
                });
        }

        return $tally;
    }

    private function handle(RentalInspection $inspection, int $lead, CarbonInterface $today, array &$tally, bool $dryRun): void
    {
        $closingDay = $inspection->signing_deadline_at->copy()->startOfDay();
        $outstanding = $inspection->outstandingSignatories()->count();
        if ($outstanding === 0) {
            return; // everyone has an outcome — nothing to chase
        }

        $days = [];
        if ($lead > 0) {
            $days[RentalInspectionSigningNotice::MILESTONE_LEAD] = $closingDay->copy()->subDays($lead);
        }
        $days[RentalInspectionSigningNotice::MILESTONE_PASSED] = $closingDay->copy()->addDay();
        $reached = array_filter($days, fn (CarbonInterface $d) => $d->lte($today));
        if ($reached === []) {
            return;
        }

        $done = RentalInspectionSigningNotice::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)->pluck('milestone')->all();
        $pending = array_diff_key($reached, array_flip($done));
        if ($pending === []) {
            return;
        }
        $latest = array_key_last($pending);

        foreach ($pending as $milestone => $day) {
            $skip = null;
            if ($milestone !== $latest) {
                $skip = 'superseded by a later reminder';
            } elseif ($day->diffInDays($today) > self::CATCH_UP_DAYS) {
                $skip = 'already past before reminders began';
            }
            $this->record($inspection, (string) $milestone, $closingDay, $outstanding, $skip, $tally, $dryRun, $today);
        }
    }

    private function record(RentalInspection $inspection, string $milestone, CarbonInterface $closingDay, int $outstanding, ?string $skip, array &$tally, bool $dryRun, CarbonInterface $today): void
    {
        $agent = $inspection->inspector ?? $inspection->createdBy;
        if ($skip === null && (! $agent || ! $agent->is_active)) {
            $skip = 'no active agent to remind';
        }

        if ($dryRun) {
            $tally[$skip === null ? 'sent' : 'skipped']++;

            return;
        }

        $common = ['agency_id' => $inspection->agency_id, 'rental_inspection_id' => $inspection->id, 'milestone' => $milestone, 'created_at' => now()];
        if ($skip !== null) {
            RentalInspectionSigningNotice::withoutGlobalScopes()->create($common + ['recipient_user_id' => $agent?->id, 'channel' => null, 'status' => 'skipped', 'detail' => $skip]);
            $tally['skipped']++;

            return;
        }

        try {
            $delivered = app(NotificationDispatcher::class)->send(
                $agent, 'rental_inspection.signing_reminder', $inspection,
                new RentalInspectionSigningReminder(
                    inspectionTypeName: RentalInspection::typeName($inspection->type),
                    milestone: $milestone,
                    closesOn: $closingDay->toDateString(),
                    outstanding: $outstanding,
                    propertyAddress: $inspection->property?->buildDisplayAddress() ?? 'Unknown property',
                    inspectionId: $inspection->id,
                    url: route('corex.rental-inspections.show', $inspection->id),
                ),
                // threshold_hit_at is the day this milestone was handled - a stable fact, not now(): the gateway's
                // once-only check compares it, and the reminder's own notice row stays the real idempotency.
                ['threshold_hit_at' => $today],
            );
            if (! $delivered) {
                RentalInspectionSigningNotice::withoutGlobalScopes()->create($common + ['recipient_user_id' => $agent->id, 'channel' => null, 'status' => 'skipped', 'detail' => "switched off in the agent's notification settings, or outside their open hours"]);
                $tally['skipped']++;

                return;
            }
            RentalInspectionSigningNotice::withoutGlobalScopes()->create($common + [
                'recipient_user_id' => $agent->id,
                'channel' => $agent->email ? 'in_app,mail' : 'in_app',
                'status' => 'sent',
                'detail' => $agent->email ? null : 'no email address on file — in-app only',
            ]);
            RentalInspectionAuditLog::record($inspection, RentalInspectionAuditLog::EVENT_SIGNING_REMINDER,
                ($milestone === 'passed' ? 'Signing window closed' : 'Signing window about to close') . ' with ' . $outstanding . ' still to sign — ' . $agent->name . ' reminded.',
                null, ['milestone' => $milestone, 'recipient_user_id' => $agent->id]);
            $tally['sent']++;
        } catch (\Throwable $e) {
            Log::warning('Rental inspection signing reminder failed', ['inspection_id' => $inspection->id, 'error' => $e->getMessage()]);
            RentalInspectionSigningNotice::withoutGlobalScopes()->create($common + ['recipient_user_id' => $agent->id, 'channel' => null, 'status' => 'failed', 'detail' => mb_substr($e->getMessage(), 0, 250)]);
            $tally['failed']++;
        }
    }
}
