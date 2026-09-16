<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\Compliance\OfficerAppointment;
use App\Models\Compliance\WhistleblowComplaint;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Services\Docuperfect\EsignApprovalService;

/**
 * The three "waiting on an officer" counts, computed once per page for the sidebar badges, the
 * Approvals hub and the toast feed. Spec §8.2.
 *
 * FICA uses exactly the two queries FicaController::index() uses (visibleTo-scoped), so the badge
 * and the FICA screen always agree. FicaController itself is not touched.
 */
class ApprovalQueueCounts
{
    public function __construct(
        private OfficerRegistry $registry,
        private EsignApprovalService $esign,
    ) {}

    /**
     * @return array{fica: array{ro:int, co:int}, esign:int, esign_officer:bool, whistleblow:int,
     *               whistleblow_visible:int, total:int}
     *
     * `esign_officer` and `whistleblow_visible` exist so the sidebar can draw the Documents › Approvals
     * link and the Compliance Reporting badge from THIS array instead of re-running the same queries.
     */
    public function forUser(User $user): array
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);

        $fica = ['ro' => 0, 'co' => 0];
        if ($agencyId > 0) {
            if ($user->isComplianceOfficer($agencyId)) {
                $fica['ro'] = FicaSubmission::where('status', 'agent_approved')->visibleTo($user)->count();
            }
            if ($user->isPrimaryComplianceOfficer($agencyId)) {
                $fica['co'] = FicaSubmission::where('status', 'referred_to_co')->visibleTo($user)->count();
            }
        }

        // One officer lookup, one count — pendingCountFor() would do the lookup again.
        $esignOfficer = $agencyId > 0 && $this->registry->isOfficer($user, OfficerAppointment::MODULE_ESIGN, $agencyId);
        $esign        = $esignOfficer ? $this->esign->queueQuery($user)->count() : 0;

        // The Compliance Reporting badge shows everyone who may VIEW what is waiting; the decider
        // count is the same query narrowed by who may decide — so count once, use twice.
        $whistleblowVisible = 0;
        if ($agencyId > 0 && $user->hasPermission('compliance.whistleblow.view')) {
            $whistleblowVisible = WhistleblowComplaint::query()
                ->where('status', 'pending_approval')
                ->visibleTo($user)
                ->count();
        }
        $whistleblow = $whistleblowVisible > 0 && $this->whistleblowMayDecide($user, $agencyId) ? $whistleblowVisible : 0;

        return [
            'fica'                => $fica,
            'esign'               => $esign,
            'esign_officer'       => $esignOfficer,
            'whistleblow'         => $whistleblow,
            'whistleblow_visible' => $whistleblowVisible,
            'total'               => $fica['ro'] + $fica['co'] + $esign + $whistleblow,
        ];
    }

    /**
     * Cheap pre-check for the polling toast: can this user ever have something waiting? One indexed
     * existence query on officer_appointments, the FICA officer flag, and the legacy
     * compliance-reporting fallback roles. Ordinary agents never poll.
     */
    public function mayHaveWork(User $user): bool
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return false;
        }

        if (OfficerAppointment::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('user_id', $user->id)
            ->whereNull('ended_on')
            ->exists()) {
            return true;
        }

        if ($user->isComplianceOfficer($agencyId)) {
            return true;
        }

        return in_array($user->role ?? 'agent', ['admin', 'branch_manager', 'super_admin'], true);
    }

    /**
     * The ONE rule for "who may decide a compliance report" (spec §9.1), shared by the controller, the
     * service, the listener, the badge and the hub: the appointed CO always; appointed ROs when the
     * agency lets them send onward; the legacy admin / BM / super_admin roles (holding the approve
     * permission) only while no CO is appointed. The appointment is the authority — an appointed
     * officer decides whatever their role's permissions say (Andre, 2026-09-16).
     */
    public function whistleblowMayDecide(User $user, int $agencyId): bool
    {
        if ($this->registry->isCo($user, OfficerAppointment::MODULE_WHISTLEBLOW, $agencyId)) {
            return true;
        }
        if ($this->registry->isRo($user, OfficerAppointment::MODULE_WHISTLEBLOW, $agencyId)) {
            return $this->registry->whistleblowRosMaySubmit($agencyId);
        }
        if ($this->registry->currentCo($agencyId, OfficerAppointment::MODULE_WHISTLEBLOW) === null) {
            return $user->hasPermission('compliance.whistleblow.approve')
                && in_array($user->role ?? 'agent', ['admin', 'branch_manager', 'super_admin'], true);
        }

        return false;
    }
}
