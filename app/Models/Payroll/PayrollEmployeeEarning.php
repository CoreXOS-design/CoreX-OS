<?php

namespace App\Models\Payroll;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollEmployeeEarning extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'payroll_employee_id',
        'earning_type_id',
        'amount',
        'effective_from',
        'effective_to',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    // ── Relationships ──

    public function employee(): BelongsTo
    {
        return $this->belongsTo(PayrollEmployee::class, 'payroll_employee_id');
    }

    public function earningType(): BelongsTo
    {
        return $this->belongsTo(PayrollEarningType::class, 'earning_type_id');
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
