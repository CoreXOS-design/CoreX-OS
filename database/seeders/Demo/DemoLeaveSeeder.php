<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Http\Controllers\Leave\LeaveApplicationController;
use App\Http\Controllers\MyPortal\MyPortalLeaveController;
use App\Models\Leave\LeaveApplication;
use App\Models\Leave\LeaveType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\User;
use App\Services\Leave\LeaveAccrualService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

/**
 * Makes Payroll -> Leave a LIVING function, not an empty module. Leave types
 * were already configured (7 BCEA-compliant types) but zero entitlements,
 * applications, or ledger transactions existed for any of the 12 payroll
 * employees seeded earlier tonight — the leave dashboard/balances screens
 * were completely empty despite the underlying employees being real.
 *
 * Step 1 — drives the REAL App\Services\Leave\LeaveAccrualService for every
 * payroll employee, so entitlements/accrual transactions are computed by the
 * actual BCEA accrual math against each employee's real employment_date —
 * not hand-typed balances that could drift from what the engine would
 * produce.
 *
 * Step 2 — drives a handful of REAL leave applications through
 * MyPortalLeaveController::store() (as the employee) and
 * LeaveApplicationController::approve()/reject() (as the admin) — a
 * deliberate mix of approved/pending/rejected, one WITH a real attached
 * document (medical certificate), so the documentation-requirement feature
 * is demonstrated actually working, not just configured.
 *
 * IDEMPOTENT: accrual is naturally idempotent (targets a computed balance,
 * doesn't double-post — see LeaveAccrualService). Applications are skipped
 * per-employee if that employee already has any leave_applications row.
 */
final class DemoLeaveSeeder
{
    public function run(int $agencyId): array
    {
        $notes = [];
        $admin = User::withoutGlobalScopes()->where('agency_id', $agencyId)->where('role', 'admin')->first();
        if (!$admin) {
            return ['created' => 0, 'skipped' => 0, 'notes' => ['FAILED: no admin user found for agency ' . $agencyId]];
        }

        $accrual = new LeaveAccrualService();
        $employees = PayrollEmployee::withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)->get();

        $accrued = 0;
        foreach ($employees as $employee) {
            $result = $accrual->accrueForEmployee($employee);
            $accrued += (int) ($result['transactions_created'] ?? 0);
        }
        $notes[] = "Accrual run: {$accrued} ledger transactions posted across {$employees->count()} employees";

        $applicationsCreated = $this->seedApplications($agencyId, $admin, $notes);

        return ['created' => $applicationsCreated, 'skipped' => 0, 'notes' => $notes];
    }

    private function seedApplications(int $agencyId, User $admin, array &$notes): int
    {
        $leaveCtrl = app(MyPortalLeaveController::class);
        $approveCtrl = app(LeaveApplicationController::class);

        $types = LeaveType::withoutGlobalScopes()->where('agency_id', $agencyId)->get()->keyBy('code');

        $plan = [
            // user_id, leave_type code, start, end, action (approve|reject|leave_pending), reason, with_doc
            [3, 'annual_leave', now()->subDays(20), now()->subDays(18), 'approve', 'Family trip to Durban.', false],
            [4, 'sick_leave', now()->subDays(15), now()->subDays(12), 'approve', 'Flu, doctor advised rest.', true],
            [7, 'annual_leave', now()->addDays(12), now()->addDays(12), 'leave_pending', 'Day off for a family event.', false],
            [8, 'family_responsibility_leave', now()->subDays(9), now()->subDays(9), 'approve', "Child's school event requiring attendance.", false],
            [11, 'unpaid_leave', now()->addDays(5), now()->addDays(5), 'reject', 'Requested during peak listing season.', false],
            [12, 'unpaid_leave', now()->addDays(20), now()->addDays(21), 'leave_pending', 'Personal matter.', false],
        ];

        $created = 0;

        foreach ($plan as [$userId, $typeCode, $start, $end, $action, $reason, $withDoc]) {
            $user = User::withoutGlobalScopes()->find($userId);
            $employee = PayrollEmployee::withoutGlobalScopes()->where('user_id', $userId)->first();
            $type = $types->get($typeCode);
            if (!$user || !$employee || !$type) {
                $notes[] = "SKIPPED (missing user/employee/type): user_id={$userId} type={$typeCode}";
                continue;
            }

            $already = LeaveApplication::withoutGlobalScopes()->where('payroll_employee_id', $employee->id)->exists();
            if ($already) {
                $notes[] = "SKIPPED (already has leave applications): {$user->name}";
                continue;
            }

            Auth::login($user);
            $payload = [
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'is_half_day' => false,
                'reason' => $reason,
            ];
            $req = Request::create('/my-portal/leave', 'POST', $payload);
            $req->setUserResolver(fn () => $user);
            if ($withDoc) {
                $req->files->set('documents', [UploadedFile::fake()->create('medical-certificate.pdf', 40, 'application/pdf')]);
            }

            try {
                $leaveCtrl->store($req);
            } catch (\Throwable $e) {
                $notes[] = "FAILED to submit for {$user->name}: " . $e->getMessage();
                continue;
            }

            $application = LeaveApplication::withoutGlobalScopes()->where('payroll_employee_id', $employee->id)->latest('id')->first();
            if (!$application) {
                $notes[] = "FAILED: no application row created for {$user->name}";
                continue;
            }

            if ($action === 'leave_pending') {
                $created++;
                $notes[] = "CREATED (pending): {$user->name} — {$type->label}, {$start->format('d M')}–{$end->format('d M')}";
                continue;
            }

            Auth::login($admin);
            try {
                if ($action === 'approve') {
                    $approveCtrl->approve($application->id);
                    $notes[] = "CREATED + APPROVED: {$user->name} — {$type->label}, {$start->format('d M')}–{$end->format('d M')}" . ($withDoc ? ' (with medical certificate)' : '');
                } elseif ($action === 'reject') {
                    $rejectReq = Request::create("/payroll/leave/applications/{$application->id}/reject", 'POST', ['decision_reason' => 'Insufficient cover during peak season — please resubmit for a quieter period.']);
                    $rejectReq->setUserResolver(fn () => $admin);
                    $approveCtrl->reject($rejectReq, $application->id);
                    $notes[] = "CREATED + REJECTED: {$user->name} — {$type->label}, {$start->format('d M')}–{$end->format('d M')}";
                }
                $created++;
            } catch (\Throwable $e) {
                $notes[] = "FAILED to decide application for {$user->name}: " . $e->getMessage();
            }
        }

        return $created;
    }
}
