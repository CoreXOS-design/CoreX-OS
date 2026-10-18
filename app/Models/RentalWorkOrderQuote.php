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
        // Q2 (9 Oct 2026) - the owner declined THIS quote: when, and why. A declined quote is never the selected one.
        'declined_at',
        'decline_reason',
        'captured_by_user_id',
        // §17.11 — the estimate-term wording in force when this quote was sent.
        'term_text',
        // §17.9.1a — the agency's fee on an outside contractor's quote; `amount` stays the contractor's own quote.
        'fee_type',
        'fee_value',
        'fee_amount',
        'selling_amount',
    ];

    protected $casts = [
        'fee_value' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'selling_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'quote_date' => 'date',
        'is_selected' => 'boolean',
        'revision' => 'integer',
        'superseded_at' => 'datetime',
        'declined_at' => 'datetime',
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

    /**
     * §17.9.1a — the quote columns an OUTSIDE contractor's quote snapshots at capture/edit: the agency's fee on top
     * of the contractor's own quote (the work order's own override, else the agency setting), and the estimate-term
     * wording in force (§17.11). `amount` is never touched. A job card's own quote has no fee. With the fee at 0
     * (the default) nothing changes: no fee columns, selling_amount stays null (= the quote's own amount).
     *
     * @return array<string, mixed>
     */
    public static function feeAttributes(RentalWorkOrder $workOrder, float $amount, bool $isJobCardQuote): array
    {
        if ($isJobCardQuote) {
            return [];
        }

        $type = $workOrder->external_markup_type ?? RentalWorkOrderSetting::externalQuoteMarkupTypeFor($workOrder->agency_id);
        $value = $workOrder->external_markup_value !== null
            ? (float) $workOrder->external_markup_value
            : RentalWorkOrderSetting::externalQuoteMarkupValueFor($workOrder->agency_id);

        $attrs = ['term_text' => RentalWorkOrderSetting::quoteEstimateTermFor($workOrder->agency_id)];
        if ($value <= 0) {
            return $attrs + ['fee_type' => null, 'fee_value' => null, 'fee_amount' => 0, 'selling_amount' => null];
        }

        $fee = $type === RentalWorkOrderSetting::EXTERNAL_MARKUP_AMOUNT ? round($value, 2) : round($amount * $value / 100, 2);

        return $attrs + [
            'fee_type' => $type,
            'fee_value' => $value,
            'fee_amount' => $fee,
            'selling_amount' => round($amount + $fee, 2),
        ];
    }

    /**
     * §17.9.1a — the ONLY figure the approval gate, the owner mails/PDFs and the owner/portal
     * payloads may use: the selling amount when a fee was applied, else the quote's own amount.
     * (The office also sees `amount` and `fee_amount`; the contractor's documents show `amount` only.)
     */
    /**
     * P2 (9 Oct 2026): the contractor's own document shows the contractor's total. The owner sees the total THEY pay and no more, so the document
     * is opened to them only when no agency fee sits on top of it (the two totals are then the same).
     */
    public function ownerMayOpenDocument(): bool
    {
        return $this->selling_amount === null || round((float) $this->selling_amount, 2) === round((float) $this->amount, 2);
    }

    public function ownerFacingAmount(): float
    {
        return (float) ($this->selling_amount ?? $this->amount);
    }
}
