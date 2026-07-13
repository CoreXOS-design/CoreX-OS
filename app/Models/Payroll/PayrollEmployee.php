<?php

namespace App\Models\Payroll;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollEmployee extends Model
{
    use HasFactory, SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'agency_id',
        'branch_id',
        'user_id',
        'employment_date',
        'termination_date',
        'designation_snapshot',
        'pay_frequency',
        'pay_day_of_month',
        'working_days_per_week',
        'working_pattern',
        'working_days_mask',
        'daily_rate_basis',
        'hours_per_day',
        'take_on_completed_at',
        'is_active',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'employment_date'  => 'date',
        'termination_date' => 'date',
        'is_active'            => 'boolean',
        'pay_day_of_month'     => 'integer',
        'working_days_per_week' => 'integer',
        'working_days_mask'    => 'integer',
        'hours_per_day'        => 'decimal:2',
        'take_on_completed_at' => 'datetime',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(PayrollEmployeeEarning::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(PayrollEmployeeDeduction::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(PayrollPayslip::class);
    }

    public function currentEarnings(): HasMany
    {
        return $this->hasMany(PayrollEmployeeEarning::class)
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', now()->toDateString());
            });
    }

    public function currentDeductions(): HasMany
    {
        return $this->hasMany(PayrollEmployeeDeduction::class)
            ->where(function ($q) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', now()->toDateString());
            });
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('termination_date');
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false)->whereNull('termination_date');
    }

    public function scopeTerminated($query)
    {
        return $query->whereNotNull('termination_date');
    }

    // ── Leave ──

    public function leaveEntitlements(): HasMany
    {
        return $this->hasMany(\App\Models\Leave\LeaveEntitlement::class);
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(\App\Models\Leave\LeaveApplication::class);
    }

    public function leaveTransactions(): HasMany
    {
        return $this->hasMany(\App\Models\Leave\LeaveTransaction::class);
    }

    public function staffTakeOnRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\Leave\StaffTakeOnRecord::class);
    }

    /**
     * Calculate daily rate per the employee's daily_rate_basis.
     * Returns bcmath string.
     */
    public function dailyRate(?\Carbon\Carbon $periodMonth = null): string
    {
        $basic = $this->basicSalaryAmount();
        $period = $periodMonth ?? now(); // AT-237 D9 — was hardcoded to the CURRENT month; now the run's period.

        return match ($this->daily_rate_basis ?? 'fixed_21_67') {
            'fixed_21_67' => bcdiv($basic, '21.67', 2),
            'calendar_working_days' => bcdiv($basic, (string) $this->workingDaysInMonth($period), 2),
            default => throw new \RuntimeException("Daily rate basis '{$this->daily_rate_basis}' not yet implemented."),
        };
    }

    /**
     * Sum of current Basic Salary earnings. bcmath string.
     */
    public function basicSalaryAmount(): string
    {
        $basicType = PayrollEarningType::where('code', 'basic')
            ->where('agency_id', $this->agency_id)
            ->first();

        if (!$basicType) {
            return '0.00';
        }

        $amount = $this->currentEarnings()
            ->where('earning_type_id', $basicType->id)
            ->value('amount');

        return $amount ? (string) $amount : '0.00';
    }

    /**
     * Bitmap to named-day array.
     */
    public function workingDaysMaskArray(): array
    {
        $mask = $this->working_days_mask ?? 31;
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $result = [];
        foreach ($days as $i => $day) {
            $result[$day] = (bool) ($mask & (1 << $i));
        }
        return $result;
    }

    /**
     * Check if a given date is a working day for this employee.
     */
    public function isWorkingDay(\Carbon\Carbon $date): bool
    {
        $dayIndex = $date->dayOfWeekIso - 1; // 0=Mon, 6=Sun
        $mask = $this->working_days_mask ?? 31;
        return (bool) ($mask & (1 << $dayIndex));
    }

    /**
     * AT-237 — count this employee's working days in [start, end] inclusive,
     * respecting their working_days_mask. The period-aware primitive behind both
     * partial-period proration and (via dailyRate) unpaid-leave day rates.
     */
    public function workingDaysBetween(\Carbon\Carbon $start, \Carbon\Carbon $end): int
    {
        if ($end->lt($start)) {
            return 0;
        }
        $count = 0;
        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();
        while ($cursor->lte($last)) {
            if ($this->isWorkingDay($cursor)) {
                $count++;
            }
            $cursor->addDay();
        }
        return $count;
    }

    /** Working days in the given period month (min 1). */
    public function workingDaysInMonth(\Carbon\Carbon $periodMonth): int
    {
        return max($this->workingDaysBetween(
            $periodMonth->copy()->startOfMonth(),
            $periodMonth->copy()->endOfMonth()
        ), 1);
    }

    /** BC shim — AT-237 D9: daily-rate/leave math is now period-aware; this stays for any legacy caller. */
    private function workingDaysInCurrentMonth(): int
    {
        return $this->workingDaysInMonth(now());
    }
}
