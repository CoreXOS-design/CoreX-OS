<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Leave\LeaveApplication;
use App\Models\Leave\LeaveTransaction;
use App\Models\Leave\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveCalendarService;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\CommandCenter\NotificationPreferenceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveApplicationController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'all');
        $q = $request->query('q');
        $typeFilter = $request->query('type');

        $query = LeaveApplication::with('user', 'leaveType', 'decidedBy')
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 WHEN 'cancelled' THEN 3 ELSE 4 END")
            ->orderByDesc('submitted_at');

        if ($status === 'pending') {
            $query->where('status', 'submitted');
        } elseif ($status === 'approved') {
            $query->where('status', 'approved');
        } elseif ($status === 'rejected') {
            $query->where('status', 'rejected');
        } elseif ($status === 'cancelled') {
            $query->where('status', 'cancelled');
        } elseif ($status === 'this_month') {
            $query->where('submitted_at', '>=', now()->startOfMonth());
        }

        if ($q) {
            $query->where(function ($qb) use ($q) {
                $qb->where('application_number', 'like', "%{$q}%")
                   ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', "%{$q}%"));
            });
        }

        if ($typeFilter) {
            $query->where('leave_type_id', $typeFilter);
        }

        $applications = $query->paginate(25)->withQueryString();

        $counts = [
            'all'        => LeaveApplication::count(),
            'pending'    => LeaveApplication::where('status', 'submitted')->count(),
            'approved'   => LeaveApplication::where('status', 'approved')->count(),
            'rejected'   => LeaveApplication::where('status', 'rejected')->count(),
            'cancelled'  => LeaveApplication::where('status', 'cancelled')->count(),
            'this_month' => LeaveApplication::where('submitted_at', '>=', now()->startOfMonth())->count(),
        ];

        $leaveTypes = LeaveType::active()->orderBy('sort_order')->get();

        return view('payroll.leave.applications.index', compact('applications', 'status', 'q', 'typeFilter', 'counts', 'leaveTypes'));
    }

    public function show($id)
    {
        $application = LeaveApplication::with([
            'user', 'leaveType', 'payrollEmployee', 'decidedBy',
            'cancelledBy', 'documents', 'transactions',
        ])->findOrFail($id);

        // Balance impact
        $balanceService = new LeaveBalanceService();
        $balanceBefore = null;
        if ($application->payrollEmployee && $application->leaveType) {
            $balanceBefore = $balanceService->getBalance(
                $application->payrollEmployee,
                $application->leaveType
            );
        }

        // Conflict check — other approved leave in same branch during same period
        $conflicts = LeaveApplication::where('branch_id', $application->branch_id)
            ->where('id', '!=', $application->id)
            ->whereIn('status', ['approved', 'taken'])
            ->where('start_date', '<=', $application->end_date)
            ->where('end_date', '>=', $application->start_date)
            ->with('user', 'leaveType')
            ->get();

        // Transactions for this application
        $appTransactions = LeaveTransaction::withoutGlobalScopes()
            ->where('source_type', 'leave_application')
            ->where('source_id', $application->id)
            ->with('createdBy')
            ->orderByDesc('created_at')
            ->get();

        return view('payroll.leave.applications.show', compact(
            'application', 'balanceBefore', 'conflicts', 'appTransactions'
        ));
    }

    public function approve($id)
    {
        // Atomic update — first to act wins
        $updated = LeaveApplication::where('id', $id)
            ->where('status', 'submitted')
            ->whereNull('decided_at')
            ->update([
                'status'             => 'approved',
                'decided_at'         => now(),
                'decided_by_user_id' => auth()->id(),
                'decided_by_role'    => $this->resolveDeciderRole(),
            ]);

        if ($updated === 0) {
            return redirect()->route('payroll.leave.applications.show', $id)
                ->with('error', 'This application has already been decided by someone else.');
        }

        $application = LeaveApplication::findOrFail($id);

        DB::transaction(function () use ($application) {
            $cycleStart = (new LeaveBalanceService())->getCurrentCycleStart(
                $application->payrollEmployee, $application->leaveType
            );

            // Create approved transaction (negative — deducts from balance)
            LeaveTransaction::create([
                'agency_id'            => $application->agency_id,
                'payroll_employee_id'  => $application->payroll_employee_id,
                'user_id'              => $application->user_id,
                'leave_type_id'        => $application->leave_type_id,
                'cycle_start_date'     => $cycleStart->toDateString(),
                'transaction_type'     => 'application_approved',
                'days_delta'           => bcmul((string) $application->working_days_requested, '-1', 3),
                'effective_date'       => $application->start_date->toDateString(),
                'description'          => "Leave approved: {$application->application_number} ({$application->working_days_requested} days)",
                'source_type'          => 'leave_application',
                'source_id'            => $application->id,
                'created_by_user_id'   => auth()->id(),
            ]);

            // Refresh entitlement
            (new LeaveBalanceService())->refreshEntitlement(
                $application->payrollEmployee, $application->leaveType
            );
        });

        // Create calendar event
        try {
            (new LeaveCalendarService())->createEventForApplication($application);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Leave calendar event creation failed', ['error' => $e->getMessage()]);
        }

        // Notify applicant
        $this->dispatchNotification('leave.approved', $application, $application->user);

        return redirect()->route('payroll.leave.applications.show', $application)
            ->with('success', "Application {$application->application_number} approved.");
    }

    public function reject(Request $request, $id)
    {
        $validated = $request->validate([
            'decision_reason' => 'required|string|min:10|max:500',
        ]);

        // Atomic update
        $updated = LeaveApplication::where('id', $id)
            ->where('status', 'submitted')
            ->whereNull('decided_at')
            ->update([
                'status'             => 'rejected',
                'decided_at'         => now(),
                'decided_by_user_id' => auth()->id(),
                'decided_by_role'    => $this->resolveDeciderRole(),
                'decision_reason'    => $validated['decision_reason'],
            ]);

        if ($updated === 0) {
            return redirect()->route('payroll.leave.applications.show', $id)
                ->with('error', 'This application has already been decided by someone else.');
        }

        $application = LeaveApplication::findOrFail($id);

        // Option C: no reservation transaction exists at submit time,
        // so no reversal needed on rejection. The pending query in
        // getBalance() auto-adjusts when status changes from 'submitted'.

        // Notify applicant
        $this->dispatchNotification('leave.rejected', $application, $application->user);

        return redirect()->route('payroll.leave.applications.show', $application)
            ->with('success', "Application {$application->application_number} rejected.");
    }

    private function dispatchNotification(string $eventKey, LeaveApplication $application, \App\Models\User $recipient): void
    {
        try {
            $dispatcher = app(NotificationDispatcher::class);
            $dispatcher->fire($recipient, $eventKey, $application, [
                'title'         => ucfirst(str_replace('.', ' ', $eventKey)) . ': ' . $application->application_number,
                'body'          => "{$application->user->name} — {$application->leaveType->label} ({$application->working_days_requested} days, {$application->start_date->format('d M')} — {$application->end_date->format('d M Y')})" . ($application->decision_reason ? "\nReason: {$application->decision_reason}" : ''),
                'subject_label' => $application->application_number,
                'action_url'    => route('payroll.leave.applications.show', $application),
                'severity'      => $eventKey === 'leave.rejected' ? 'warning' : 'info',
                'threshold_hit_at' => now()->startOfHour(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Leave notification dispatch failed: {$eventKey}", ['error' => $e->getMessage()]);
        }
    }

    private function resolveDeciderRole(): string
    {
        $user = auth()->user();
        if ($user->hasPermission('manage_leave') && $user->role === 'branch_manager') {
            return 'branch_manager';
        }
        if ($user->role === 'admin' || $user->hasPermission('adjust_leave_balances')) {
            return 'admin';
        }
        return 'admin';
    }
}
