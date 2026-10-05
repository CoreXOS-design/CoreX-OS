<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md §3.4c — a quote an agent obtains from a
 * supplier before work starts. RentalWorkOrder::selectQuote() is the only
 * place `is_selected` is flipped, and the SELECTED quote's `amount` is what
 * the approval-limit gate compares against a property's (or the agency's
 * default) no-approval spend threshold — never `cost_amount`, which is only
 * ever known after the job is done (RentalWorkOrder::complete()). Soft
 * delete only — archive(), never destroy.
 */
class RentalWorkOrderQuote extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'rental_job_card_id',
        'revision',
        'superseded_at',
        'content_signature',
        'agency_service_provider_id',
        'amount',
        'quote_date',
        'document_storage_path',
        'detail_text',
        'is_selected',
        'captured_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quote_date' => 'date',
        'is_selected' => 'boolean',
        'revision' => 'integer',
        'superseded_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    /** AT-442 — set when this quote was generated from an internal job card, instead of a named supplier. */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    /** Deleted-related-record rule (.ai/BUILD_STANDARD.md §4) — see Lease::property(). */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DealV2\AgencyServiceProvider::class, 'agency_service_provider_id')->withTrashed();
    }

    /**
     * §14.21 — a job card quote that a later re-send has replaced. Kept (never
     * deleted) and still viewable, but never the current/selected quote again.
     */
    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    public function capturedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
