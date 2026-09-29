<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/leases.md §3.4 — escalation as a RATE, not only the new rand
 * amount. Append-only: no updated_at is used, nothing here is ever edited
 * or deleted once recorded — same evidence-integrity principle as
 * rental_inspection_observations (a correction gets its own new row).
 */
class LeaseEscalation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'lease_id',
        'effective_date',
        'previous_rental_amount',
        'new_rental_amount',
        'escalation_rate_percent',
        'note',
        'created_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'previous_rental_amount' => 'decimal:2',
        'new_rental_amount' => 'decimal:2',
        'escalation_rate_percent' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The one place this rate is ever computed. Called at entry time so the
     * result is snapshotted, never re-derived from amount history later.
     */
    public static function computeRatePercent(float $previousAmount, float $newAmount): float
    {
        if ($previousAmount <= 0) {
            return 0.0;
        }

        return round((($newAmount - $previousAmount) / $previousAmount) * 100, 2);
    }
}
