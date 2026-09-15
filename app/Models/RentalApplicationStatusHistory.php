<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-392 — one immutable row per rental application status change. Mirrors
 * FicaStatusHistory (the existing append-only status-trail pattern): no
 * updated_at, written only through ::record(), never updated or deleted —
 * a decision on a tenant application needs a permanent trail.
 */
class RentalApplicationStatusHistory extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $table = 'rental_application_status_history';

    protected $fillable = [
        'rental_application_id', 'agency_id', 'from_status', 'to_status',
        'changed_by_user_id', 'note', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public static function record(
        RentalApplication $rentalApplication,
        ?string $fromStatus,
        string $toStatus,
        ?User $actor = null,
        ?string $note = null,
    ): self {
        return static::create([
            'rental_application_id' => $rentalApplication->id,
            'agency_id'             => $rentalApplication->agency_id,
            'from_status'           => $fromStatus,
            'to_status'             => $toStatus,
            'changed_by_user_id'    => $actor?->id,
            'note'                  => $note !== null && trim($note) !== '' ? $note : null,
            'created_at'            => now(),
        ]);
    }
}
