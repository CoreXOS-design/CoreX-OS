<?php

namespace App\Models\Leave;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Payroll\PayrollEmployee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveEntitlement extends Model
{
    use BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'agency_id', 'branch_id', 'payroll_employee_id', 'user_id',
        'leave_type_id', 'cycle_start_date', 'cycle_end_date',
        'entitlement_days', 'accrued_days', 'carryover_from_previous_cycle',
        'taken_days', 'pending_days', 'available_days',
        'last_accrual_run_at', 'notes',
    ];

    protected $casts = [
        'cycle_start_date'              => 'date',
        'cycle_end_date'                => 'date',
        'entitlement_days'              => 'decimal:2',
        'accrued_days'                  => 'decimal:2',
        'carryover_from_previous_cycle' => 'decimal:2',
        'taken_days'                    => 'decimal:2',
        'pending_days'                  => 'decimal:2',
        'available_days'                => 'decimal:2',
        'last_accrual_run_at'           => 'datetime',
    ];

    // ── Relationships ──

    public function agency(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Agency::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payrollEmployee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveTransaction::class, 'payroll_employee_id', 'payroll_employee_id')
            ->where('leave_type_id', $this->leave_type_id ?? 0)
            ->where('cycle_start_date', $this->cycle_start_date);
    }

    // ── Methods ──

    public function recalculateAvailableDays(): void
    {
        $available = bcsub(
            bcadd((string) $this->accrued_days, (string) $this->carryover_from_previous_cycle, 2),
            bcadd((string) $this->taken_days, (string) $this->pending_days, 2),
            2
        );

        $this->available_days = $available;
        $this->save();
    }

    public function isCurrent(): bool
    {
        $today = now()->toDateString();
        return $today >= $this->cycle_start_date->toDateString()
            && $today <= $this->cycle_end_date->toDateString();
    }

    public function daysUntilCycleEnd(): int
    {
        return (int) now()->diffInDays($this->cycle_end_date, false);
    }
}
