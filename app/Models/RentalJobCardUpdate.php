<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AT-442 — append-only, same evidence-integrity shape as RentalWorkOrderUpdate. */
class RentalJobCardUpdate extends Model
{
    use BelongsToAgency;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'rental_job_card_id',
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

    protected static function booted(): void
    {
        static::creating(function (RentalJobCardUpdate $update) {
            $update->created_at ??= now();
        });
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
