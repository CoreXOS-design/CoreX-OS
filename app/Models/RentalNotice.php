<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-portal-access.md §8/§10 — AT-445. An append-only record
 * of a sent notice. Not edited after sending — a sending error is handled
 * by the normal soft-delete floor, never an update.
 */
class RentalNotice extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'lease_id',
        'rental_notice_template_id',
        'notice_type',
        'figures',
        'sent_to_tenant',
        'sent_to_landlord',
        'document_id',
        'sent_at',
        'sent_by_user_id',
    ];

    protected $casts = [
        'figures' => 'array',
        'sent_to_tenant' => 'boolean',
        'sent_to_landlord' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RentalNoticeTemplate::class, 'rental_notice_template_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
