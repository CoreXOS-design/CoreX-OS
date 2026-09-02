<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Compliance\EmployeeScreening;
use App\Models\Compliance\EmployeeScreeningCheck;
use App\Models\User;
use Carbon\Carbon;

/**
 * Seeds a realistic SPREAD of staff screening states for the demo webinar
 * (Johan, 2026-09-03) — the Compliance → Staff Screening dashboard currently
 * shows every active user as "not screened" because employee_screenings has
 * zero rows and every user.screening_status sits at its migration default.
 *
 * Deliberately NOT a uniform "everyone cleared" set — a realistic compliance
 * program has a mix: clear, overdue, expired, one flagged for review, two
 * pending pre-employment checks, and a few genuinely never screened.
 *
 * Drives the SAME denormalised fields (users.screening_status/risk_tier/
 * screening_due_on) the real EmployeeScreening workflow maintains, but with
 * historical (backdated) timestamps — EmployeeScreening::complete() always
 * stamps "now", so historical rows are constructed directly rather than via
 * that action method.
 *
 * IDEMPOTENT: keyed on a per-user check — skips any user who already has an
 * employee_screenings row OR a non-default screening_status. Re-running never
 * duplicates or overwrites a real screening entered through the UI.
 */
final class DemoStaffScreeningSeeder
{
    /**
     * user_id => target state. Left out entirely = stays 'never_screened'
     * (genuinely unseeded, matching the real default).
     */
    private function plan(): array
    {
        return [
            1  => 'clear',                   // Demo Administrator
            2  => 'clear',                   // Lerato Ndlovu — BM Margate
            3  => 'clear',                   // Pieter van der Merwe
            4  => 'overdue',                  // Anele Dlamini
            6  => 'clear',                   // Sipho Mkhize — BM Shelly Beach
            7  => 'concerns_flagged',         // Karen Joubert
            8  => 'pre_employment_pending',   // Bongani Khumalo
            10 => 'clear',                   // Mandla Nkosi — BM Port Shepstone
            11 => 'expired',                  // Nomsa Steyn
            12 => 'overdue',                  // Grant du Plessis
            13 => 'pre_employment_pending',   // Ayanda Pillay
            // 5, 9, 14 deliberately absent — stay never_screened.
        ];
    }

    private function riskTierFor(User $user): string
    {
        if (in_array($user->role, ['admin', 'branch_manager'], true)) {
            return 'high';
        }
        if ($user->role === 'viewer') {
            return 'low';
        }
        return 'medium';
    }

    public function run(int $agencyId): array
    {
        $created = 0;
        $skipped = 0;
        $notes = [];

        $users = User::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $admin = $users->firstWhere('role', 'admin') ?? $users->first();

        foreach ($users as $user) {
            $riskTier = $this->riskTierFor($user);
            $target = $this->plan()[$user->id] ?? 'never_screened';

            $alreadySeeded = ($user->screening_status && $user->screening_status !== 'never_screened')
                || EmployeeScreening::withoutGlobalScopes()->where('user_id', $user->id)->exists();

            if ($alreadySeeded) {
                $skipped++;
                $notes[] = "SKIPPED (already has screening data): {$user->name} (id={$user->id})";
                continue;
            }

            // Risk tier is set for everyone (including the never_screened
            // group) — a real compliance program classifies risk BEFORE the
            // first screening is ever run.
            $user->risk_tier = $riskTier;

            if ($target === 'never_screened') {
                $user->screening_status = 'never_screened';
                $user->screening_due_on = null;
                $user->save();
                $notes[] = "SET: {$user->name} (id={$user->id}) → never_screened, risk_tier={$riskTier}";
                continue;
            }

            $this->seedFor($user, $riskTier, $target, $admin);
            $created++;
            $notes[] = "CREATED: {$user->name} (id={$user->id}) → {$target}, risk_tier={$riskTier}";
        }

        return ['created' => $created, 'skipped' => $skipped, 'notes' => $notes];
    }

    private function seedFor(User $user, string $riskTier, string $target, ?User $admin): void
    {
        $agencyId = $user->agency_id;
        $adminId = $admin?->id;

        switch ($target) {
            case 'clear':
                $months = match ($riskTier) {
                    'high' => 8, 'low' => 40, default => 20,
                };
                $initiated = now()->subMonths($months)->subDays(3);
                $completed = now()->subMonths($months);
                $nextDue = match ($riskTier) {
                    'high' => $completed->copy()->addYear(),
                    'low' => $completed->copy()->addYears(5),
                    default => $completed->copy()->addYears(3),
                };
                $screening = $this->makeScreening($user, 'periodic', $riskTier, 'completed', $initiated, $completed, $nextDue, $adminId, $adminId, 'pass', 'Periodic review completed — no adverse findings.');
                $this->makeChecks($screening, 'periodic', 'clear', $completed, $adminId);
                $user->screening_status = 'clear';
                $user->screening_due_on = $nextDue->toDateString();
                break;

            case 'overdue':
                $cycleYears = $riskTier === 'high' ? 1 : ($riskTier === 'low' ? 5 : 3);
                $lapsedMonths = 2; // just past due
                $completed = now()->subYears($cycleYears)->subMonths($lapsedMonths);
                $initiated = $completed->copy()->subDays(3);
                $nextDue = $completed->copy()->addYears($cycleYears);
                $screening = $this->makeScreening($user, 'periodic', $riskTier, 'completed', $initiated, $completed, $nextDue, $adminId, $adminId, 'pass', 'Periodic review completed — no adverse findings at the time. Re-screening now overdue.');
                $this->makeChecks($screening, 'periodic', 'clear', $completed, $adminId);
                $user->screening_status = 'overdue';
                $user->screening_due_on = $nextDue->toDateString();
                break;

            case 'expired':
                $cycleYears = $riskTier === 'high' ? 1 : ($riskTier === 'low' ? 5 : 3);
                $lapsedMonths = 13; // well past due
                $completed = now()->subYears($cycleYears)->subMonths($lapsedMonths);
                $initiated = $completed->copy()->subDays(3);
                $nextDue = $completed->copy()->addYears($cycleYears);
                $screening = $this->makeScreening($user, 'periodic', $riskTier, 'completed', $initiated, $completed, $nextDue, $adminId, $adminId, 'pass', 'Periodic review completed — no adverse findings at the time. Re-screening window has lapsed; certification treated as expired pending re-screen.');
                $this->makeChecks($screening, 'periodic', 'clear', $completed, $adminId);
                $user->screening_status = 'expired';
                $user->screening_due_on = $nextDue->toDateString();
                break;

            case 'concerns_flagged':
                $initiated = now()->subWeeks(3);
                $screening = $this->makeScreening($user, 'periodic', $riskTier, 'flagged', $initiated, null, null, $adminId, null, 'concerns_flagged', 'Credit bureau check returned an adverse judgment on file. Practitioner has been asked to provide an explanation before sign-off.');
                $checkedOn = $initiated->copy()->addDays(4);
                foreach (['id_verification', 'address_verification', 'tfs_screening', 'high_risk_association_check'] as $type) {
                    $this->makeCheck($screening, $type, 'clear', $checkedOn, $adminId);
                }
                $this->makeCheck($screening, 'credit_check', 'concerns', $checkedOn, $adminId, 'Adverse judgment listed — awaiting practitioner response.');
                $this->makeCheck($screening, 'criminal_record_check', 'clear', $checkedOn, $adminId);
                $user->screening_status = 'concerns_flagged';
                $user->screening_due_on = null;
                break;

            case 'pre_employment_pending':
                $initiated = now()->subDays($riskTier === 'high' ? 12 : 8);
                $screening = $this->makeScreening($user, 'pre_employment', $riskTier, 'in_progress', $initiated, null, null, $adminId, null, null, null);
                $checkedOn = $initiated->copy()->addDays(2);
                foreach (['id_verification', 'employment_history_verified', 'references_checked', 'address_verification'] as $type) {
                    $this->makeCheck($screening, $type, 'clear', $checkedOn, $adminId);
                }
                foreach (['qualification_verified', 'ppra_ffc_verified', 'criminal_record_check', 'credit_check', 'tfs_screening', 'previous_aml_role_review', 'high_risk_association_check'] as $type) {
                    $this->makeCheck($screening, $type, 'pending', null, null);
                }
                $user->screening_status = 'pre_employment_pending';
                $user->screening_due_on = null;
                break;
        }

        $user->save();
    }

    private function makeScreening(
        User $user,
        string $type,
        string $riskTier,
        string $status,
        Carbon $initiatedOn,
        ?Carbon $completedOn,
        ?Carbon $nextDueOn,
        ?int $initiatedBy,
        ?int $completedBy,
        ?string $overallResult,
        ?string $notes = null,
    ): EmployeeScreening {
        $screening = EmployeeScreening::withoutGlobalScopes()->create([
            'agency_id'      => $user->agency_id,
            'user_id'        => $user->id,
            'screening_type' => $type,
            'risk_tier'      => $riskTier,
            'status'         => $status,
            'initiated_on'   => $initiatedOn->toDateString(),
            'completed_on'   => $completedOn?->toDateString(),
            'next_due_on'    => $nextDueOn?->toDateString(),
            'initiated_by'   => $initiatedBy,
            'completed_by'   => $completedBy,
            'overall_result' => $overallResult,
            'summary_notes'  => $notes,
        ]);
        // branch_id is not mass-assignable on this model — set explicitly.
        $screening->branch_id = $user->branch_id;
        $screening->created_at = $initiatedOn;
        $screening->updated_at = $completedOn ?? $initiatedOn;
        $screening->save();

        return $screening;
    }

    private function makeChecks(EmployeeScreening $screening, string $screeningType, string $result, Carbon $checkedOn, ?int $checkedBy): void
    {
        foreach (EmployeeScreeningCheck::typesForScreening($screeningType) as $type) {
            $this->makeCheck($screening, $type, $result, $checkedOn, $checkedBy);
        }
    }

    private function makeCheck(EmployeeScreening $screening, string $type, string $result, ?Carbon $checkedOn, ?int $checkedBy, ?string $notes = null): void
    {
        EmployeeScreeningCheck::withoutGlobalScopes()->create([
            'agency_id'              => $screening->agency_id,
            'employee_screening_id'  => $screening->id,
            'check_type'             => $type,
            'result'                 => $result,
            'checked_on'             => $checkedOn?->toDateString(),
            'checked_by'             => $checkedBy,
            'notes'                  => $notes,
            'reference_number'       => $result === 'pending' ? null : ('DEMO-SCR-' . $screening->user_id . '-' . strtoupper(substr($type, 0, 3))),
        ]);
    }
}
