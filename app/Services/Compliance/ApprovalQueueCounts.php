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

    /** @return array{fica: array{ro:int, co:int}, esign:int, whistleblow:int, total:int} */
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

        $esign = $agencyId > 0 ? $this->esign->pendingCountFor($user) : 0;

        $whistleblow = 0;
        if ($agencyId > 0 && $this->whistleblowMayDecide($user, $agencyId)) {
            $whistleblow = WhistleblowComplaint::query()
                ->where('status', 'pending_approval')
                ->visibleTo($user)
                ->count();
        }

        return [
            'fica'        => $fica,
            'esign'       => $esign,
            'whistleblow' => $whistleblow,
            'total'       => $fica['ro'] + $fica['co'] + $esign + $whistleblow,
        ];
    }

    /** Mirrors WhistleblowComplaintService's officer rule (CO always; ROs when allowed; legacy roles when no CO). */
    public function whistleblowMayDecide(User $user, int $agencyId): bool
    {
        if (! $user->hasPermission('compliance.whistleblow.approve')) {
            return false;
        }
        if ($this->registry->isCo($user, OfficerAppointment::MODULE_WHISTLEBLOW, $agencyId)) {
            return true;
        }
        if ($this->registry->isRo($user, OfficerAppointment::MODULE_WHISTLEBLOW, $agencyId)) {
            return $this->registry->whistleblowRosMaySubmit($agencyId);
        }
        if ($this->registry->currentCo($agencyId, OfficerAppointment::MODULE_WHISTLEBLOW) === null) {
            return in_array($user->role ?? 'agent', ['admin', 'branch_manager', 'super_admin'], true);
        }

        return false;
    }
}
