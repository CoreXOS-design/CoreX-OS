<?php

namespace App\Models\Leave;

use App\Models\Agency;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Payroll\PayrollEmployee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IMMUTABLE ledger record. Cannot be updated or deleted.
 * Corrections are done by inserting a reversal transaction.
 */
class LeaveTransaction extends Model
{
    use BelongsToAgency;

    public $timestamps = false;

    protected $fillable = [
        'agency_id', 'payroll_employee_id', 'user_id', 'leave_type_id',
        'cycle_start_date', 'transaction_type', 'days_delta',
        'effective_date', 'description', 'source_type', 'source_id',
        'created_by_user_id', 'reversal_of_transaction_id', 'created_at',
    ];

    protected $casts = [
        'cycle_start_date' => 'date',
        'effective_date'   => 'date',
        'days_delta'       => 'decimal:3',
        'created_at'       => 'datetime',
    ];

    // ── Immutability guard ──

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Leave transactions are immutable. Reverse with a new transaction.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Leave transactions are immutable. Reverse with a new transaction.');
        });

        static::creating(function (self $txn) {
            if (bccomp((string) ($txn->days_delta ?? '0'), '0', 3) === 0) {
                throw new \RuntimeException('Cannot create a leave transaction with zero days_delta.');
            }
            if (empty($txn->created_at)) {
                $txn->created_at = now();
            }
        });
    }

    // ── Relationships ──

    public function agency(): BelongsTo { return $this->belongsTo(Agency::class); }
    public function payrollEmployee(): BelongsTo { return $this->belongsTo(PayrollEmployee::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_transaction_id');
    }

    // ── Factory ──

    public static function record(array $data): self
    {
        return static::create($data);
    }
}
