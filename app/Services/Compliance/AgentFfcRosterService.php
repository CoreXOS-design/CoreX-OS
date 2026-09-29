<?php

namespace App\Services\Compliance;

use App\Models\AgentApplication;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The per-agent FFC status roster — extracted 2026-09-28 (PPRA Inspection
 * Pack Phase B) from AgentComplianceController::calculateFfcStatus() so the
 * exact same status computation is shared by the /compliance/agents
 * dashboard, its PDF/CSV exports, and PpraInspectionPackChecklistService's
 * items c/f, instead of drifting across three independent copies.
 * Behaviour is unchanged from the original private method — same 60-day
 * amber window, same AgentApplication.ffc_expiry fallback.
 */
class AgentFfcRosterService
{
    /**
     * Every active, non-assistant agent in the agency with their FFC status.
     *
     * @return Collection<int, array{id:int,name:string,designation:?string,ffc:array}>
     */
    public function rosterFor(int $agencyId): Collection
    {
        $agents = User::agencyMembers()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->where('is_assistant', false) // AT-267: assistants are not agents in the compliance roster
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        return $agents->map(fn (User $agent) => [
            'id'          => $agent->id,
            'name'        => $agent->name,
            'designation' => $agent->designation,
            'ffc'         => $this->ffcStatusFor($agent),
        ])->values();
    }

    public function ffcStatusFor(User $agent): array
    {
        $hasFile = ! empty($agent->ffc_certificate_path);
        $hasNumber = ! empty($agent->ffc_number);

        // Users table doesn't have ffc_expiry column wired here yet —
        // check agent_applications if the user was onboarded (unchanged
        // from the original AgentComplianceController logic).
        $expiryDate = null;
        if ($agent->id) {
            $application = AgentApplication::where('user_id', $agent->id)->first();
            if ($application && $application->ffc_expiry) {
                $expiryDate = $application->ffc_expiry;
            }
        }

        if ($expiryDate) {
            $daysRemaining = (int) now()->diffInDays(Carbon::parse($expiryDate), false);
            if ($daysRemaining < 0) {
                return ['status' => 'red', 'label' => 'Expired', 'expiry_date' => $expiryDate, 'days' => $daysRemaining, 'has_file' => $hasFile];
            }
            if ($daysRemaining <= 60) {
                return ['status' => 'amber', 'label' => 'Expiring ' . Carbon::parse($expiryDate)->format('d M'), 'expiry_date' => $expiryDate, 'days' => $daysRemaining, 'has_file' => $hasFile];
            }

            return ['status' => 'green', 'label' => 'Valid until ' . Carbon::parse($expiryDate)->format('d M Y'), 'expiry_date' => $expiryDate, 'days' => $daysRemaining, 'has_file' => $hasFile];
        }

        if ($hasFile && $hasNumber) {
            return ['status' => 'green', 'label' => $agent->ffc_number, 'expiry_date' => null, 'days' => null, 'has_file' => $hasFile];
        }
        if ($hasNumber) {
            return ['status' => 'amber', 'label' => $agent->ffc_number . ' (no cert)', 'expiry_date' => null, 'days' => null, 'has_file' => $hasFile];
        }

        return ['status' => 'red', 'label' => 'Not set', 'expiry_date' => null, 'days' => null, 'has_file' => $hasFile];
    }
}
