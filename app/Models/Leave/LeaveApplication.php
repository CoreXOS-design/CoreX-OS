<?php

namespace App\Models\Leave;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Document;
use App\Models\Payroll\PayrollEmployee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveApplication extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'agency_id', 'branch_id', 'payroll_employee_id', 'user_id',
        'leave_type_id', 'application_number', 'start_date', 'end_date',
        'is_half_day', 'half_day_period', 'working_days_requested',
        'calendar_days_requested', 'reason', 'status',
        'submitted_at', 'decided_at', 'decided_by_user_id', 'decided_by_role',
        'decision_reason', 'taken_at', 'cancelled_at', 'cancelled_by_user_id',
        'cancellation_reason', 'payslip_id', 'affects_payroll',
        'payroll_impact_amount', 'notes',
    ];

    protected $casts = [
        'start_date'              => 'date',
        'end_date'                => 'date',
        'is_half_day'             => 'boolean',
        'affects_payroll'         => 'boolean',
        'working_days_requested'  => 'decimal:2',
        'payroll_impact_amount'   => 'decimal:2',
        'calendar_days_requested' => 'integer',
        'submitted_at'            => 'datetime',
        'decided_at'              => 'datetime',
        'taken_at'                => 'datetime',
        'cancelled_at'            => 'datetime',
    ];

    // ── Relationships ──

    public function agency(): BelongsTo { return $this->belongsTo(Agency::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function payrollEmployee(): BelongsTo { return $this->belongsTo(PayrollEmployee::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }
    public function decidedBy(): BelongsTo { return $this->belongsTo(User::class, 'decided_by_user_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'leave_application_documents')
            ->withPivot('document_role', 'uploaded_by_user_id')
            ->withTimestamps();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveTransaction::class, 'source_id')
            ->where('source_type', 'leave_application');
    }

    // ── Scopes ──

    public function scopePending($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeForDateRange($query, $start, $end)
    {
        return $query->where('start_date', '<=', $end)
                     ->where('end_date', '>=', $start);
    }

    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('payroll_employee_id', $employeeId);
    }

    // ── Status helpers ──

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isSubmitted(): bool { return $this->status === 'submitted'; }
    public function isApproved(): bool { return $this->status === 'approved'; }
    public function isRejected(): bool { return $this->status === 'rejected'; }
    public function isCancelled(): bool { return $this->status === 'cancelled'; }
    public function isTaken(): bool { return $this->status === 'taken'; }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['draft', 'submitted', 'approved']);
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'submitted';
    }

    // ── Lifecycle setters ──

    public function markSubmitted(): void
    {
        $this->update(['status' => 'submitted', 'submitted_at' => now()]);
    }

    public function markApproved(User $user, ?string $reason = null): void
    {
        $this->update([
            'status'          => 'approved',
            'decided_at'      => now(),
            'decided_by_user_id' => $user->id,
            'decision_reason' => $reason,
        ]);
    }

    public function markRejected(User $user, string $reason): void
    {
        $this->update([
            'status'          => 'rejected',
            'decided_at'      => now(),
            'decided_by_user_id' => $user->id,
            'decision_reason' => $reason,
        ]);
    }

    public function markCancelled(User $user, string $reason): void
    {
        $this->update([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancelled_by_user_id' => $user->id,
            'cancellation_reason' => $reason,
        ]);
    }

    // ── Application number ──

    public static function generateNumber(int $agencyId): string
    {
        $year = now()->format('Y');
        $lastId = static::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereYear('created_at', $year)
            ->max('id') ?? 0;
        $seq = $lastId + 1;

        return 'LV-' . $year . '-' . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::creating(function (self $app) {
            if (empty($app->application_number)) {
                $app->application_number = static::generateNumber(
                    $app->agency_id ?? auth()->user()?->effectiveAgencyId() ?? 0
                );
            }
        });
    }
}
