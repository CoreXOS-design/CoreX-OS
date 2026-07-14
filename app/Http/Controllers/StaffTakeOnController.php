<?php

namespace App\Http\Controllers;

use App\Models\Leave\LeaveType;
use App\Models\Leave\StaffTakeOnRecord;
use App\Models\Payroll\PayrollDeductionType;
use App\Models\Payroll\PayrollEarningType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEmployeeDeduction;
use App\Models\Payroll\PayrollEmployeeEarning;
use App\Models\User;
use App\Models\UserBankingDetail;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffTakeOnController extends Controller
{
    private const STEPS = ['user', 'personal', 'tax_banking', 'employment', 'compensation', 'leave', 'compliance', 'review'];

    // ── INDEX ──

    public function index(Request $request)
    {
        $status = $request->query('status', 'all');

        $query = StaffTakeOnRecord::with('user', 'completedBy')
            ->orderByDesc('created_at');

        if ($status === 'in_progress') {
            $query->whereNull('completed_at');
        } elseif ($status === 'completed') {
            $query->whereNotNull('completed_at');
        } elseif ($status === 'this_month') {
            $query->where('created_at', '>=', now()->startOfMonth());
        }

        $records = $query->paginate(25)->withQueryString();

        $counts = [
            'all'         => StaffTakeOnRecord::count(),
            'in_progress' => StaffTakeOnRecord::whereNull('completed_at')->count(),
            'completed'   => StaffTakeOnRecord::whereNotNull('completed_at')->count(),
            'this_month'  => StaffTakeOnRecord::where('created_at', '>=', now()->startOfMonth())->count(),
        ];

        return view('staff-take-on.index', compact('records', 'status', 'counts'));
    }

    // ── CREATE (Step 1: user selection) ──

    public function create()
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        $eligibleUsers = User::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereDoesntHave('payrollEmployee')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'designation', 'id_number', 'branch_id']);

        return view('staff-take-on.create', compact('eligibleUsers'));
    }

    // ── STORE (Create the record + advance to step 2) ──

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'       => 'required|integer|exists:users,id',
            'take_on_type'  => 'required|in:new_hire,migration_from_old_system,transfer_from_other_branch',
            'take_on_date'  => 'required|date',
        ]);

        $user = User::findOrFail($validated['user_id']);

        // Prevent duplicate
        $existing = StaffTakeOnRecord::where('user_id', $user->id)->whereNull('completed_at')->first();
        if ($existing) {
            return redirect()->route('staff-take-on.wizard', [$existing, $existing->nextStep() ?? 'personal'])
                ->with('info', 'A take-on is already in progress for this user.');
        }

        $record = StaffTakeOnRecord::create([
            'user_id'                       => $user->id,
            'branch_id'                     => $user->branch_id,
            'take_on_date'                  => $validated['take_on_date'],
            'take_on_type'                  => $validated['take_on_type'],
            'original_employment_start_date' => $validated['take_on_date'],
            'current_step'                  => 'personal',
        ]);

        return redirect()->route('staff-take-on.wizard', [$record, 'personal']);
    }

    // ── WIZARD ──

    public function wizard(StaffTakeOnRecord $takeOn, string $step)
    {
        if (!in_array($step, self::STEPS)) {
            abort(404);
        }

        $takeOn->load('user', 'user.branch', 'user.bankingDetail', 'payrollEmployee');

        $data = compact('takeOn', 'step');
        $data['steps'] = self::STEPS;
        $data['currentIndex'] = array_search($step, self::STEPS);

        // Step-specific data
        if ($step === 'employment' || $step === 'compensation') {
            $data['earningTypes'] = PayrollEarningType::active()->orderBy('sort_order')->get();
            $data['deductionTypes'] = PayrollDeductionType::active()->orderBy('sort_order')->get();
            $data['payrollEmployee'] = $takeOn->payrollEmployee;
        }

        if ($step === 'leave') {
            $data['leaveTypes'] = LeaveType::withoutGlobalScopes()
                ->where('agency_id', $takeOn->agency_id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();
            $data['balanceService'] = new LeaveBalanceService();
            $data['payrollEmployee'] = $takeOn->payrollEmployee;
        }

        if ($step === 'compliance') {
            $data['uploadedDocs'] = $takeOn->user->documents()
                ->orderByDesc('created_at')
                ->get();
        }

        return view('staff-take-on.wizard', $data);
    }

    // ── SAVE STEP ──

    public function saveStep(Request $request, StaffTakeOnRecord $takeOn, string $step)
    {
        if (!in_array($step, self::STEPS)) {
            abort(404);
        }

        $user = $takeOn->user;
        $nextStep = $this->getNextStep($step);

        switch ($step) {
            case 'personal':
                $validated = $request->validate([
                    'date_of_birth'                  => 'nullable|date',
                    'phone'                          => 'nullable|string|max:30',
                    'emergency_contact_name'         => 'nullable|string|max:150',
                    'emergency_contact_phone'        => 'nullable|string|max:30',
                    'emergency_contact_relationship' => 'nullable|string|max:50',
                    'next_of_kin_name'               => 'nullable|string|max:150',
                    'next_of_kin_phone'              => 'nullable|string|max:30',
                    'next_of_kin_relationship'       => 'nullable|string|max:50',
                    'home_address'                   => 'nullable|string',
                    'marital_status'                 => 'nullable|in:single,married,divorced,widowed,life_partner,other',
                    'dependents_count'               => 'nullable|integer|min:0',
                ]);
                $user->update($validated);
                $takeOn->update(['personal_details_verified' => true, 'current_step' => $nextStep]);
                break;

            case 'tax_banking':
                $validated = $request->validate([
                    'tax_reference_number' => 'nullable|string|max:20',
                    'bank_name'            => 'nullable|string|max:100',
                    'account_holder'       => 'nullable|string|max:150',
                    'branch_code'          => 'nullable|string|max:10',
                    'account_number'       => 'nullable|string|max:30',
                    'account_type'         => 'nullable|in:cheque,savings,transmission',
                    'medical_aid_provider' => 'nullable|string|max:100',
                    'medical_aid_number'   => 'nullable|string|max:50',
                    'medical_aid_main_member' => 'boolean',
                    'medical_aid_dependents_count' => 'nullable|integer|min:0',
                ]);

                $user->update([
                    'tax_reference_number' => $validated['tax_reference_number'],
                    'medical_aid_provider' => $validated['medical_aid_provider'] ?? null,
                    'medical_aid_number'   => $validated['medical_aid_number'] ?? null,
                    'medical_aid_main_member' => $validated['medical_aid_main_member'] ?? false,
                    'medical_aid_dependents_count' => $validated['medical_aid_dependents_count'] ?? 0,
                ]);

                if (!empty($validated['bank_name']) && !empty($validated['account_number'])) {
                    UserBankingDetail::updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'account_holder' => $validated['account_holder'] ?? $user->name,
                            'bank_name'      => $validated['bank_name'],
                            'branch_code'    => $validated['branch_code'],
                            'account_number' => $validated['account_number'],
                            'account_type'   => $validated['account_type'] ?? 'cheque',
                            'is_primary'     => true,
                        ]
                    );
                }

                $takeOn->update(['banking_details_verified' => true, 'tax_details_verified' => true, 'current_step' => $nextStep]);
                break;

            case 'employment':
                $validated = $request->validate([
                    'original_employment_start_date' => 'required|date',
                    'designation_snapshot'            => 'required|string|max:100',
                    'branch_id'                      => 'nullable|integer',
                    'working_days_per_week'          => 'required|integer|min:1|max:7',
                    'working_pattern'                => 'required|in:monday_to_friday,monday_to_saturday,custom',
                    'hours_per_day'                  => 'required|numeric|min:1|max:24',
                    'pay_day_of_month'               => 'required|integer|min:1|max:31',
                    'daily_rate_basis'               => 'required|in:fixed_21_67,calendar_working_days,hours_per_day',
                ]);

                $takeOn->update(['original_employment_start_date' => $validated['original_employment_start_date']]);

                $mask = match ($validated['working_pattern']) {
                    'monday_to_friday'  => 31,
                    'monday_to_saturday' => 63,
                    default => 31,
                };

                $empData = [
                    'user_id'              => $user->id,
                    'branch_id'            => $validated['branch_id'] ?? $user->branch_id,
                    'employment_date'      => $validated['original_employment_start_date'],
                    'designation_snapshot'  => $validated['designation_snapshot'],
                    'pay_frequency'        => 'monthly',
                    'pay_day_of_month'     => $validated['pay_day_of_month'],
                    'working_days_per_week' => $validated['working_days_per_week'],
                    'working_pattern'      => $validated['working_pattern'],
                    'working_days_mask'    => $mask,
                    'daily_rate_basis'     => $validated['daily_rate_basis'],
                    'hours_per_day'        => $validated['hours_per_day'],
                    'is_active'            => true,
                    'created_by'           => auth()->id(),
                ];

                $payrollEmp = PayrollEmployee::updateOrCreate(
                    ['user_id' => $user->id],
                    $empData
                );

                $takeOn->update([
                    'payroll_employee_id'       => $payrollEmp->id,
                    'employment_terms_verified' => true,
                    'current_step'              => $nextStep,
                ]);
                break;

            case 'compensation':
                // Compensation is saved via existing payroll employee earnings/deductions endpoints.
                // This step just marks verified and advances.
                $payrollEmp = $takeOn->payrollEmployee;
                if ($payrollEmp) {
                    // Ensure at least Basic Salary exists
                    $basicType = PayrollEarningType::where('code', 'basic')->first();
                    if ($basicType && $payrollEmp->currentEarnings()->where('earning_type_id', $basicType->id)->doesntExist()) {
                        PayrollEmployeeEarning::create([
                            'payroll_employee_id' => $payrollEmp->id,
                            'earning_type_id'     => $basicType->id,
                            'amount'              => $request->input('basic_salary', 0),
                            'effective_from'      => $payrollEmp->employment_date,
                            'created_by'          => auth()->id(),
                        ]);
                    } elseif ($basicType && $request->filled('basic_salary')) {
                        $existing = $payrollEmp->currentEarnings()->where('earning_type_id', $basicType->id)->first();
                        if ($existing && bccomp((string) $existing->amount, (string) $request->input('basic_salary'), 2) !== 0) {
                            $existing->update(['amount' => $request->input('basic_salary')]);
                        }
                    }

                    // Ensure statutory deductions exist
                    $statutoryTypes = PayrollDeductionType::where('is_statutory', true)->get();
                    foreach ($statutoryTypes as $st) {
                        PayrollEmployeeDeduction::firstOrCreate(
                            ['payroll_employee_id' => $payrollEmp->id, 'deduction_type_id' => $st->id],
                            ['amount' => 0, 'effective_from' => $payrollEmp->employment_date, 'override_statutory' => false, 'created_by' => auth()->id()]
                        );
                    }
                }

                $takeOn->update(['compensation_setup_verified' => true, 'current_step' => $nextStep]);
                break;

            case 'leave':
                $payrollEmp = $takeOn->payrollEmployee;
                if (!$payrollEmp) {
                    return back()->with('error', 'Employment must be set up before leave balances.');
                }

                $accrualService = new LeaveAccrualService();
                $leaveTypes = LeaveType::withoutGlobalScopes()
                    ->where('agency_id', $takeOn->agency_id)
                    ->where('is_active', true)->get();

                foreach ($leaveTypes as $type) {
                    $takenKey = "taken_{$type->id}";
                    $carryoverKey = "carryover_{$type->id}";

                    $taken = (string) ($request->input($takenKey, '0') ?: '0');
                    $carryover = (string) ($request->input($carryoverKey, '0') ?: '0');

                    // Run accrual first to establish baseline
                    $accrualService->accrueForEmployee($payrollEmp);

                    // Record taken days as negative opening balance
                    if (bccomp($taken, '0', 3) > 0) {
                        try {
                            $accrualService->manualAdjustment(
                                $payrollEmp, $type, bcmul($taken, '-1', 3),
                                "Take-on: {$taken} days already taken this cycle",
                                auth()->user()
                            );
                        } catch (\Throwable $e) {
                            // Zero delta — skip
                        }
                    }

                    // Record carryover as positive
                    if (bccomp($carryover, '0', 3) > 0) {
                        try {
                            $accrualService->manualAdjustment(
                                $payrollEmp, $type, $carryover,
                                "Take-on: {$carryover} days carryover from previous cycle",
                                auth()->user()
                            );
                        } catch (\Throwable $e) {
                            // Zero delta — skip
                        }
                    }
                }

                $takeOn->update(['leave_balances_captured' => true, 'current_step' => $nextStep]);
                break;

            case 'compliance':
                // Documents uploaded via AJAX endpoint separately.
                $takeOn->update([
                    'compliance_documents_uploaded'       => true,
                    'signed_employment_contract_uploaded' => $request->boolean('contract_uploaded', false) || $takeOn->signed_employment_contract_uploaded,
                    'current_step'                       => $nextStep,
                ]);
                break;

            case 'review':
                // Review doesn't save — it redirects to complete
                return redirect()->route('staff-take-on.complete', $takeOn);
        }

        if ($request->input('_action') === 'save_exit') {
            return redirect()->route('staff-take-on.index')
                ->with('success', 'Progress saved. You can resume later.');
        }

        return redirect()->route('staff-take-on.wizard', [$takeOn, $nextStep ?? 'review']);
    }

    // ── COMPLETE ──

    public function complete(Request $request, StaffTakeOnRecord $takeOn)
    {
        if ($takeOn->isComplete()) {
            return redirect()->route('staff-take-on.index')
                ->with('info', 'This take-on is already completed.');
        }

        // Check minimum requirements
        if (!$takeOn->employment_terms_verified || !$takeOn->compensation_setup_verified) {
            return redirect()->route('staff-take-on.wizard', [$takeOn, $takeOn->nextStep() ?? 'review'])
                ->with('error', 'Employment and compensation must be completed before finalising.');
        }

        $takeOn->update([
            'completed_at'        => now(),
            'completed_by_user_id' => auth()->id(),
            'current_step'        => 'review',
        ]);

        // Mark payroll employee as take-on completed
        if ($takeOn->payrollEmployee) {
            $takeOn->payrollEmployee->update(['take_on_completed_at' => now()]);
        }

        return redirect()->route('staff-take-on.index')
            ->with('success', "Take-on for {$takeOn->user->name} completed successfully.");
    }

    // ── UPLOAD DOCUMENT ──

    public function uploadDocument(Request $request, StaffTakeOnRecord $takeOn)
    {
        $request->validate([
            'file'          => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'document_type' => 'required|string|max:50',
        ]);

        $file = $request->file('file');
        $path = $file->store('take-on-docs/' . $takeOn->user_id, 'public');

        \App\Models\UserDocument::create([
            'user_id'            => $takeOn->user_id,
            'agency_id'          => $takeOn->agency_id,
            'document_type'      => $request->document_type,
            'file_path'          => $path,
            'file_name'          => $file->getClientOriginalName(),
            'file_size'          => $file->getSize(),
            'mime_type'          => $file->getMimeType(),
            'status'             => 'verified',
            'verified_by'        => auth()->id(),
            'verified_at'        => now(),
            'uploaded_by'        => auth()->id(),
            'uploaded_by_admin'  => true,
            'admin_upload_reason' => "Uploaded during staff take-on",
        ]);

        if ($request->document_type === 'other' && str_contains(strtolower($file->getClientOriginalName()), 'contract')) {
            $takeOn->update(['signed_employment_contract_uploaded' => true]);
        }

        return back()->with('success', 'Document uploaded.');
    }

    // ── HELPERS ──

    private function getNextStep(string $current): ?string
    {
        $idx = array_search($current, self::STEPS);
        return $idx !== false && $idx < count(self::STEPS) - 1 ? self::STEPS[$idx + 1] : null;
    }
}
