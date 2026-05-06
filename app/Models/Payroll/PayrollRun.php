<?php

namespace App\Models\Payroll;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRun extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'run_number',
        'period_month',
        'pay_date',
        'status',
        'finalised_at',
        'finalised_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'payslip_count',
        'total_gross',
        'total_paye',
        'total_uif_employee',
        'total_uif_employer',
        'total_sdl',
        'total_net',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'period_month'       => 'date',
        'pay_date'           => 'date',
        'finalised_at'       => 'datetime',
        'cancelled_at'       => 'datetime',
        'payslip_count'      => 'integer',
        'total_gross'        => 'decimal:2',
        'total_paye'         => 'decimal:2',
        'total_uif_employee' => 'decimal:2',
        'total_uif_employer' => 'decimal:2',
        'total_sdl'          => 'decimal:2',
        'total_net'          => 'decimal:2',
    ];

    // ── Relationships ──

    public function payslips(): HasMany
    {
        return $this->hasMany(PayrollPayslip::class);
    }

    public function finalisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalised_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeFinalised($query)
    {
        return $query->where('status', 'finalised');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeForMonth($query, Carbon $month)
    {
        return $query->where('period_month', $month->startOfMonth()->toDateString());
    }

    // ── Helpers ──

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFinalised(): bool
    {
        return $this->status === 'finalised';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
