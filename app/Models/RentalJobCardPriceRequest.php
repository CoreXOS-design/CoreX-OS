<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/rental-work-orders.md §17.5.4 — "Ask the crew to price this job".
 * One OPEN request per card at a time; never deleted (a withdrawn request is
 * `cancelled`).
 */
class RentalJobCardPriceRequest extends Model
{
    use BelongsToAgency;

    public const STATUS_OPEN = 'open';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Matches the column default so a just-created instance reads correctly without a refresh. */
    protected $attributes = [
        'status' => self::STATUS_OPEN,
    ];

    protected $fillable = [
        'agency_id',
        'rental_job_card_id',
        'requested_by_user_id',
        'requested_at',
        'note',
        'status',
        'submitted_at',
        'submitted_label',
        'submitted_ip',
        'submitted_device',
        'closed_at',
        'closed_by_user_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'submitted_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** The crew lines submitted against this request. */
    public function lines(): HasMany
    {
        return $this->hasMany(RentalJobCardLine::class, 'rental_job_card_price_request_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
