<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.4 — evidence photos, before AND
 * after, distinguished by photo_type. Immutable, no deleted_at.
 */
class RentalWorkOrderPhoto extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'photo_type',
        'storage_path',
        'uploaded_by_user_id',
        'client_idempotency_key',
        'file_size_bytes',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $photo) {
            if (empty($photo->client_idempotency_key)) {
                $photo->client_idempotency_key = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($photo->created_at)) {
                $photo->created_at = now();
            }
        });
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
