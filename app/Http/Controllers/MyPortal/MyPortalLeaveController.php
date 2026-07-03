<?php

namespace App\Http\Controllers\MyPortal;

use App\Http\Controllers\Controller;
use App\Models\Leave\LeaveApplication;
use App\Models\Leave\LeaveTransaction;
use App\Models\Leave\LeaveType;
use App\Models\Payroll\PayrollEmployee;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\PublicHolidayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MyPortalLeaveController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $employee = PayrollEmployee::where('user_id', $user->id)->where('is_active', true)->first();

        if (!$employee) {
            return view('my-portal.leave.index', ['balances' => collect(), 'applications' => new LengthAwarePaginator([], 0, 10, 1, ['path' => $request->url()]), 'employee' => null]);
        }

        $balanceService = new LeaveBalanceService();
        $balances = $balanceService->getAllBalancesForEmployee($employee);

        $status = $request->query('status', 'all');
        $appQuery = LeaveApplication::where('user_id', $user->id)
            ->with('leaveType')
            ->orderByDesc('submitted_at');

        if ($status === 'pending') {
            $appQuery->where('status', 'submitted');
        } elseif ($status === 'approved') {
            $appQuery->where('status', 'approved');
        } elseif ($status === 'rejected') {
            $appQuery->where('status', 'rejected');
        } elseif ($status === 'cancelled') {
            $appQuery->where('status', 'cancelled');
        }

        $applications = $appQuery->paginate(10)->withQueryString();

        $counts = [
            'all'       => LeaveApplication::where('user_id', $user->id)->count(),
            'pending'   => LeaveApplication::where('user_id', $user->id)->where('status', 'submitted')->count(),
            'approved'  => LeaveApplication::where('user_id', $user->id)->where('status', 'approved')->count(),
            'rejected'  => LeaveApplication::where('user_id', $user->id)->where('status', 'rejected')->count(),
            'cancelled' => LeaveApplication::where('user_id', $user->id)->where('status', 'cancelled')->count(),
        ];

        return view('my-portal.leave.index', compact('balances', 'applications', 'employee', 'status', 'counts'));
    }

    public function create()
    {
        $user = auth()->user();
        $employee = PayrollEmployee::where('user_id', $user->id)->where('is_active', true)->firstOrFail();

        $balanceService = new LeaveBalanceService();
        $leaveTypes = LeaveType::withoutGlobalScopes()
            ->where('agency_id', $employee->agency_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $balances = [];
        foreach ($leaveTypes as $type) {
            $balances[$type->id] = $balanceService->getBalance($employee, $type);
        }

        return view('my-portal.leave.apply', compact('employee', 'leaveTypes', 'balances'));
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $employee = PayrollEmployee::where('user_id', $user->id)->where('is_active', true)->firstOrFail();

        $validated = $request->validate([
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'is_half_day'   => 'boolean',
            'half_day_period' => 'nullable|in:morning,afternoon',
            'reason'        => 'nullable|string|max:2000',
            'notes'         => 'nullable|string|max:2000',
            'documents'     => 'nullable|array',
            'documents.*'   => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $type = LeaveType::findOrFail($validated['leave_type_id']);
        $start = Carbon::parse($validated['start_date']);
        $end = Carbon::parse($validated['end_date']);
        $isHalfDay = $validated['is_half_day'] ?? false;

        // Calculate working days
        $holidayService = new PublicHolidayService();
        $workingDays = $holidayService->countWorkingDays($start, $end, $employee->workingDaysMaskArray());
        if ($isHalfDay && $workingDays >= 1) {
            $workingDays = 0.5;
        }

        // Documentation requirement check
        if ($type->requires_documentation) {
            $threshold = $type->documentation_threshold_days ?? 0;
            if ($workingDays > $threshold && !$request->hasFile('documents')) {
                $docLabel = $type->documentation_label ?: 'Supporting document';
                return back()->withInput()->with('error', "{$docLabel} is required for {$type->label} longer than {$threshold} day(s).");
            }
        }

        // Balance check
        $balanceService = new LeaveBalanceService();
        $balance = $balanceService->getBalance($employee, $type);
        $available = (float) $balance['available_days'];

        if ($workingDays > $available && !$type->allows_negative_balance) {
            return back()->withInput()->with('error', "Insufficient {$type->label} balance. Available: " . number_format($available, 2) . " days, requested: {$workingDays} days.");
        }

        // Overlap check
        $overlap = LeaveApplication::where('user_id', $user->id)
            ->whereIn('status', ['submitted', 'approved'])
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->exists();

        if ($overlap) {
            return back()->withInput()->with('error', 'You already have a pending or approved application overlapping these dates.');
        }

        // Reason required for certain types
        if (in_array($type->category, ['family_responsibility', 'special', 'unpaid']) && empty($validated['reason'])) {
            return back()->withInput()->with('error', "Reason is required for {$type->label} applications.");
        }

        $application = DB::transaction(function () use ($employee, $user, $type, $validated, $start, $end, $workingDays, $isHalfDay, $balanceService) {
            $app = LeaveApplication::create([
                'agency_id'               => $employee->agency_id,
                'branch_id'               => $employee->branch_id,
                'payroll_employee_id'     => $employee->id,
                'user_id'                 => $user->id,
                'leave_type_id'           => $type->id,
                'start_date'              => $start,
                'end_date'                => $end,
                'is_half_day'             => $isHalfDay,
                'half_day_period'         => $isHalfDay ? ($validated['half_day_period'] ?? null) : null,
                'working_days_requested'  => $workingDays,
                'calendar_days_requested' => $start->diffInDays($end) + 1,
                'reason'                  => $validated['reason'],
                'notes'                   => $validated['notes'],
                'status'                  => 'submitted',
                'submitted_at'            => now(),
                'affects_payroll'         => $type->affects_payroll,
            ]);

            // No reservation transaction created at submit — pending balance
            // is derived from application status query in getBalance().
            // Transaction created only at admin approval (Option C fix).

            return $app;
        });

        // Upload documents if provided
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $ext = $file->getClientOriginalExtension();
                $path = $file->storeAs(
                    'leave-documents/' . $application->id,
                    \Illuminate\Support\Str::uuid() . ($ext ? ".{$ext}" : ''),
                    'local'
                );

                $document = \App\Models\Document::create([
                    'agency_id'     => $employee->agency_id,
                    'original_name' => $file->getClientOriginalName(),
                    'storage_path'  => $path,
                    'disk'          => 'local',
                    'mime_type'     => $file->getMimeType(),
                    'size'          => $file->getSize(),
                    'source_type'   => 'leave_application',
                    'source_id'     => $application->id,
                    'uploaded_by'   => $user->id,
                ]);

                $application->documents()->attach($document->id, [
                    'document_role'       => $this->resolveDocumentRole($type->category),
                    'uploaded_by_user_id' => $user->id,
                ]);
            }
        }

        // Notify BMs + admins
        try {
            $dispatcher = app(\App\Services\CommandCenter\NotificationDispatcher::class);
            // Notify branch managers + admins who can approve
            $approvers = \App\Models\User::withoutGlobalScopes()
                ->where('agency_id', $employee->agency_id)
                ->where('is_active', true)
                ->get()
                ->filter(fn($u) => $u->hasPermission('approve_leave'));
            foreach ($approvers as $approver) {
                $dispatcher->fire($approver, 'leave.submitted', $application, [
                    'title'         => "Leave application: {$application->application_number}",
                    'body'          => "{$user->name} applied for {$type->label} ({$workingDays} days, {$start->format('d M')} — {$end->format('d M Y')})",
                    'subject_label' => $application->application_number,
                    'action_url'    => route('payroll.leave.applications.show', $application),
                    'severity'      => 'info',
                    'threshold_hit_at' => now()->startOfHour(),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Leave submit notification failed', ['error' => $e->getMessage()]);
        }

        return redirect()->route('my-portal.leave.show', $application)
            ->with('success', "Leave application {$application->application_number} submitted. Your BM/admin will review it.");
    }

    public function show($applicationId)
    {
        $application = LeaveApplication::with('leaveType', 'decidedBy', 'cancelledBy', 'documents')
            ->where('user_id', auth()->id())
            ->findOrFail($applicationId);

        $transactions = LeaveTransaction::withoutGlobalScopes()
            ->where('source_type', 'leave_application')
            ->where('source_id', $application->id)
            ->with('createdBy')
            ->orderByDesc('created_at')
            ->get();

        return view('my-portal.leave.show', compact('application', 'transactions'));
    }

    public function cancel(Request $request, $applicationId)
    {
        $user = auth()->user();
        $application = LeaveApplication::where('user_id', $user->id)
            ->where('status', 'submitted')
            ->findOrFail($applicationId);

        // No transaction to reverse — Option C: no reservation txn at submit.
        // Just update application status. Pending query in getBalance() auto-adjusts.
        $application->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancelled_by_user_id' => $user->id,
            'cancellation_reason' => request('cancellation_reason', 'Cancelled by applicant'),
        ]);

        // Remove calendar event if it exists
        try {
            (new \App\Services\Leave\LeaveCalendarService())->removeEventForApplication($application);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Leave calendar event removal failed', ['error' => $e->getMessage()]);
        }

        return redirect()->route('my-portal.leave.index')
            ->with('success', "Application {$application->application_number} cancelled.");
    }

    public function calculateDays(Request $request)
    {
        $validated = $request->validate([
            'leave_type_id' => 'required|integer',
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'is_half_day'   => 'boolean',
        ]);

        $user = auth()->user();
        $employee = PayrollEmployee::where('user_id', $user->id)->where('is_active', true)->first();
        if (!$employee) {
            return response()->json(['error' => 'No active employee record'], 404);
        }

        $type = LeaveType::find($validated['leave_type_id']);
        if (!$type) {
            return response()->json(['error' => 'Invalid leave type'], 404);
        }

        $start = Carbon::parse($validated['start_date']);
        $end = Carbon::parse($validated['end_date']);
        $holidayService = new PublicHolidayService();

        $workingDays = $holidayService->countWorkingDays($start, $end, $employee->workingDaysMaskArray());
        if ($validated['is_half_day'] ?? false) {
            $workingDays = 0.5;
        }

        $holidays = $holidayService->getHolidaysInRange($start, $end);

        $balanceService = new LeaveBalanceService();
        $balance = $balanceService->getBalance($employee, $type);
        $balanceAfter = bcsub($balance['available_days'], (string) $workingDays, 3);

        $warnings = [];
        if (bccomp($balanceAfter, '0', 3) < 0 && !$type->allows_negative_balance) {
            $warnings[] = 'Insufficient balance — this application would create a negative balance.';
        }

        return response()->json([
            'working_days'   => $workingDays,
            'calendar_days'  => $start->diffInDays($end) + 1,
            'holidays'       => $holidays->map(fn($h) => ['date' => $h->holiday_date->format('Y-m-d'), 'name' => $h->name]),
            'balance_before' => $balance['available_days'],
            'balance_after'  => $balanceAfter,
            'warnings'       => $warnings,
        ]);
    }

    private function resolveDocumentRole(string $category): string
    {
        return match ($category) {
            'sick' => 'medical_certificate',
            'family_responsibility', 'parental' => 'supporting',
            default => 'other',
        };
    }
}
