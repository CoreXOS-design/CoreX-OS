<?php

namespace App\Models\Payroll;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollEmployeeDeduction extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'payroll_employee_id',
        'deduction_type_id',
        'amount',
        'effective_from',
        'effective_to',
        'override_statutory',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount'             => 'decimal:2',
        'effective_from'     => 'date',
        'effective_to'       => 'date',
        'override_statutory' => 'boolean',
    ];

    // ── Relationships ──

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(PayrollDeductionType::class, 'deduction_type_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──

    public function scopeCurrent($query, ?Carbon $date = null)
    {
        $d = ($date ?? now())->toDateString();

        return $query->where('effective_from', '<=', $d)
            ->where(function ($q) use ($d) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $d);
            });
    }
}
