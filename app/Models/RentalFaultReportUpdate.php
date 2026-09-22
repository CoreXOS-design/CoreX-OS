<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-work-orders.md §3a — "who did what" for a fault report.
 * Mirrors RentalWorkOrderUpdate exactly — same shape, same reasoning, the
 * closest existing sibling in this same feature family. Append-only, never
 * edited or deleted.
 */
class RentalFaultReportUpdate extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const TYPE_LOGGED = 'logged';
    public const TYPE_APPROVAL_REQUESTED = 'approval_requested';
    public const TYPE_APPROVAL_RECORDED = 'approval_recorded';
    public const TYPE_WORK_ORDER_RAISED = 'work_order_raised';
    public const TYPE_OUTCOME_SET = 'outcome_set';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_NOTE = 'note';
    public const TYPE_ARCHIVED = 'archived';
    public const TYPE_RESTORED = 'restored';

    protected $fillable = [
        'agency_id',
        'rental_fault_report_id',
        'update_type',
        'from_status',
        'to_status',
        'note',
        'created_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $update) {
            if (empty($update->created_at)) {
                $update->created_at = now();
            }
        });
    }

    public function faultReport(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReport::class, 'rental_fault_report_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
