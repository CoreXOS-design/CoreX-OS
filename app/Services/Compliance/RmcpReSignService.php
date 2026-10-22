<?php

namespace App\Services\Compliance;

use App\Models\Compliance\RmcpAcknowledgement;
use App\Models\Compliance\RmcpVersion;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AT-506 — what happens to staff when an agency approves a NEW RMCP version.
 *
 * The acknowledgement flow already asks everyone to sign the ACTIVE version (User::rmcpAcknowledgementStatus reads the
 * active version, so approving v2 makes every staff member "not started" on it). This service adds the two things that
 * flow did not do:
 *   1. the acknowledgements staff signed on the earlier versions are marked `superseded` (kept, never deleted — they are
 *      the signed record of what each person agreed to), so they no longer read as a current, valid acknowledgement;
 *   2. every active staff member of the agency gets an in-app notification asking them to read and sign the new version.
 *
 * Called by RmcpController::approve after the approval itself has committed. A failure here never undoes an approval.
 */
class RmcpReSignService
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * @return array{superseded:int, notified:int}
     */
    public function afterApproval(RmcpVersion $version, ?User $approver = null): array
    {
        $superseded = 0;
        $notified   = 0;

        try {
            $superseded = DB::table('rmcp_acknowledgements')
                ->where('agency_id', $version->agency_id)
                ->where('rmcp_version_id', '<>', $version->id)
                ->whereIn('status', [RmcpAcknowledgement::STATUS_IN_PROGRESS, RmcpAcknowledgement::STATUS_COMPLETED])
                ->whereNull('deleted_at')
                ->update(['status' => RmcpAcknowledgement::STATUS_SUPERSEDED, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('RMCP re-sign: could not mark earlier acknowledgements superseded', [
                'version_id' => $version->id, 'error' => $e->getMessage(),
            ]);
        }

        // The same people the RMCP staff register (RmcpDashboardController) expects to sign: the agency's active users.
        $staff = User::withoutGlobalScopes()
            ->where('agency_id', $version->agency_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get();

        foreach ($staff as $user) {
            try {
                $sent = $this->dispatcher->fire($user, 'rmcp.version_approved', $version, [
                    'title'            => 'New RMCP version — please acknowledge',
                    'body'             => 'Version ' . $version->version_number . ' of your agency\'s Risk Management and Compliance Programme is now in force. Please read it and sign the acknowledgement.',
                    'subject_label'    => 'RMCP v' . $version->version_number,
                    'action_url'       => '/my-portal',
                    'severity'         => 'warning',
                    // One notification per person per version: the approval moment is a stable fact for this version.
                    'threshold_hit_at' => $version->approved_at ?? now(),
                ]);
                if ($sent) {
                    $notified++;
                }
            } catch (\Throwable $e) {
                Log::warning('RMCP re-sign: notification failed for one staff member', [
                    'version_id' => $version->id, 'user_id' => $user->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('RMCP re-sign started', [
            'version_id' => $version->id, 'agency_id' => $version->agency_id,
            'superseded' => $superseded, 'notified' => $notified, 'approved_by' => $approver?->id,
        ]);

        return ['superseded' => $superseded, 'notified' => $notified];
    }
}
