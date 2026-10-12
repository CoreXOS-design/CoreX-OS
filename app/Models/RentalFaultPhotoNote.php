<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** .ai/specs/rentals-faults-work-orders.md §18 — the agent's short description of one fault photo (one per photo). Soft delete only. */
class RentalFaultPhotoNote extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const MAX_LENGTH = 500;

    protected $fillable = [
        'agency_id', 'rental_fault_report_id', 'rental_fault_report_photo_id', 'description',
        'recorded_by_user_id', 'recorded_at', 'deleted_by_user_id',
    ];

    protected $casts = ['recorded_at' => 'datetime'];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReportPhoto::class, 'rental_fault_report_photo_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
