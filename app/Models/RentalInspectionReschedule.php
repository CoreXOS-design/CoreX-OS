<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspections.md §43 — "reschedule keeps history of old
 * date + who/when." One immutable row per reschedule — never edited or
 * hard-deleted, mirroring ContactMatchReassignment's own pattern exactly.
 */
class RentalInspectionReschedule extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'old_scheduled_for',
        'old_scheduled_time',
        'old_inspector_user_id',
        'new_scheduled_for',
        'new_scheduled_time',
        'new_inspector_user_id',
        'reason',
        'changed_by_user_id',
    ];

    protected $casts = [
        'old_scheduled_for' => 'date',
        'new_scheduled_for' => 'date',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function oldInspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'old_inspector_user_id');
    }

    public function newInspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_inspector_user_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * The ONE place a reschedule row is ever written — called from
     * RentalInspection::reschedule(), never directly. $new carries only
     * the keys actually changing; anything absent falls back to the
     * inspection's CURRENT value for both audit sides, so a reschedule
     * that only moves the time (say) still records a coherent old/new
     * pair rather than a null-vs-value diff that looks like a bigger
     * change than it was.
     */
    public static function record(RentalInspection $inspection, array $new, User $by, ?string $reason): self
    {
        return self::create([
            'agency_id' => $inspection->agency_id,
            'rental_inspection_id' => $inspection->id,
            'old_scheduled_for' => $inspection->scheduled_for,
            'old_scheduled_time' => $inspection->scheduled_time,
            'old_inspector_user_id' => $inspection->inspector_user_id,
            'new_scheduled_for' => $new['scheduled_for'] ?? $inspection->scheduled_for,
            'new_scheduled_time' => array_key_exists('scheduled_time', $new) ? $new['scheduled_time'] : $inspection->scheduled_time,
            'new_inspector_user_id' => $new['inspector_user_id'] ?? $inspection->inspector_user_id,
            'reason' => $reason,
            'changed_by_user_id' => $by->id,
        ]);
    }
}
