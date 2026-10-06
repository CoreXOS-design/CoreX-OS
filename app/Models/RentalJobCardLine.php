<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-442 — a job card's parts/labour line, from the catalogue or free text.
 * `rental_vat_type_id`/`custom_vat_rate` are the agent's live VAT choice for
 * this line (Pastel-style, per CLAUDE.md agency-VAT ruling); the
 * `vat_*_snapshot` columns are null until frozen once, at "send to owner as
 * quote" or job-card completion (RentalJobCardVatService::snapshotLines()).
 *
 * `code` (2026-10-05) — the catalogue item's own code, copied at add-time,
 * same discipline as `type`/`description`/`unit`: null for a free-text
 * line, never re-derived from the catalogue item after the line is saved.
 */
class RentalJobCardLine extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    /**
     * .ai/specs/rental-work-orders.md §17.2/§17.5 — where a line stands with the office.
     * ONLY `accepted` lines count anywhere (totals, quote PDFs, VAT breakdown, content
     * signature, "what to load", reports). Every reader goes through scopeAccepted().
     */
    public const OFFICE_CREW_DRAFT = 'crew_draft';
    public const OFFICE_AWAITING = 'awaiting_office';
    public const OFFICE_ACCEPTED = 'accepted';
    public const OFFICE_REJECTED = 'rejected';
    public const OFFICE_DECLINED_BY_OWNER = 'declined_by_owner';

    /** Who created the line. */
    public const ORIGIN_OFFICE = 'office';
    public const ORIGIN_CREW_PRICING = 'crew_pricing';
    public const ORIGIN_CREW_EXTRA = 'crew_extra';

    /** §17.4.3 — how the line's selling price was arrived at. */
    public const BASIS_MANUAL = 'manual';
    public const BASIS_LINE_MARKUP = 'line_markup';
    public const BASIS_JOB_MARKUP = 'job_markup';
    public const BASIS_CATALOGUE_PRICE = 'catalogue_price';
    public const BASIS_AGENCY_DEFAULT = 'agency_default';

    /** §17.5.1 — rental_work_order_photos.photo_type of a photo that explains ONE crew line; never shown to a tenant/landlord and not in the job gallery. */
    public const PHOTO_TYPE = 'crew_line';

    public const MARKUP_PERCENT = 'percent';
    public const MARKUP_AMOUNT = 'amount';

    protected $fillable = [
        'agency_id',
        'rental_job_card_id',
        'rental_job_card_task_id',
        'rental_catalogue_item_id',
        'code',
        'type',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'line_total',
        'rental_vat_type_id',
        'custom_vat_rate',
        'vat_type_name_snapshot',
        'vat_rate_snapshot',
        'vat_excl_snapshot',
        'vat_amount_snapshot',
        'vat_incl_snapshot',
        'sort_order',
        'created_by_user_id',
        // §17.4.2 — COST (new) and how SELLING (unit_price / line_total) was resolved.
        'unit_cost',
        'cost_total',
        'markup_type',
        'markup_value',
        'selling_basis',
        // §17.5.2 — crew-added lines and the office's decision on them.
        'origin',
        'office_status',
        'crew_note',
        'crew_added_by_label',
        'crew_added_at',
        'office_decided_by_user_id',
        'office_decided_at',
        'reject_reason',
        'rental_job_card_price_request_id',
        'rental_work_order_variation_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'cost_total' => 'decimal:2',
        'markup_value' => 'decimal:2',
        'crew_added_at' => 'datetime',
        'office_decided_at' => 'datetime',
        'custom_vat_rate' => 'decimal:2',
        'vat_rate_snapshot' => 'decimal:2',
        'vat_excl_snapshot' => 'decimal:2',
        'vat_amount_snapshot' => 'decimal:2',
        'vat_incl_snapshot' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    /** Null means this line sits in the built-in "General" group (no task). */
    public function task(): BelongsTo
    {
        return $this->belongsTo(RentalJobCardTask::class, 'rental_job_card_task_id');
    }

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(RentalCatalogueItem::class, 'rental_catalogue_item_id');
    }

    public function vatType(): BelongsTo
    {
        return $this->belongsTo(RentalVatType::class, 'rental_vat_type_id');
    }

    public function isVatSnapshotted(): bool
    {
        return $this->vat_rate_snapshot !== null || $this->vat_amount_snapshot !== null;
    }

    /** §17.2 — the ONLY lines that count in any total, document, signature or report. */
    public function scopeAccepted($query)
    {
        return $query->where($query->getModel()->getTable() . '.office_status', self::OFFICE_ACCEPTED);
    }

    public function isAccepted(): bool
    {
        return $this->office_status === self::OFFICE_ACCEPTED;
    }

    public function priceRequest(): BelongsTo
    {
        return $this->belongsTo(RentalJobCardPriceRequest::class, 'rental_job_card_price_request_id');
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrderVariation::class, 'rental_work_order_variation_id');
    }
}
