<?php

declare(strict_types=1);

namespace App\Listeners\Compliance;

use App\Events\Compliance\WhistleblowReportSubmitted;
use App\Models\Compliance\OfficerAppointment;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\User;
use App\Notifications\Compliance\WhistleblowReportSubmittedNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Compliance\ApprovalQueueCounts;
use App\Services\Compliance\OfficerRegistry;
use Illuminate\Support\Facades\Log;

/**
 * A compliance report was submitted — tell whoever may decide it (the CO; ROs when the agency lets
 * them send onward; the legacy admin / BM roles when no CO is appointed yet), scoped by the
 * standing own / branch / all rule. Spec §9.2. Explicitly registered (discovery OFF).
 */
class NotifyOfficersOfWhistleblowSubmission
{
    public function __construct(
        private OfficerRegistry $registry,
        private ApprovalQueueCounts $counts,
    ) {}

    public function handle(WhistleblowReportSubmitted $event): void
    {
        $complaint = $event->complaint;
        $agencyId  = (int) $complaint->agency_id;
        if ($agencyId <= 0) {
            return;
        }

        $recipients = $this->registry->officers($agencyId, OfficerAppointment::MODULE_WHISTLEBLOW);
        if ($this->registry->currentCo($agencyId, OfficerAppointment::MODULE_WHISTLEBLOW) === null) {
            // Legacy fallback — nobody appointed yet, so the role-based approvers carry it.
            $recipients = User::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereIn('role', ['admin', 'branch_manager', 'super_admin'])
                ->get();
        }

        foreach ($recipients as $officer) {
            if ((int) $officer->id === (int) $complaint->reported_by_user_id) {
                continue;
            }
            if (! $this->counts->whistleblowMayDecide($officer, $agencyId)) {
                continue;
            }
            $inScope = WhistleblowComplaint::query()->withoutGlobalScopes()
                ->whereKey($complaint->id)
                ->visibleTo($officer)
                ->exists();
            if (! $inScope) {
                continue;
            }

            try {
                app(NotificationDispatcher::class)->send(
                    $officer,
                    'whistleblow.report_submitted',
                    $complaint,
                    new WhistleblowReportSubmittedNotification($complaint),
                    [
                        'threshold_hit_at' => now()->toIso8601String(),
                        'complaint_id'     => $complaint->id,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('Whistleblow submission notification failed (non-fatal)', [
                    'complaint_id' => $complaint->id,
                    'officer_id'   => $officer->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }
    }
}
