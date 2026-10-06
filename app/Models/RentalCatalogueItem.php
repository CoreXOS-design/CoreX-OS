<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-442 — an agency's own labour/part catalogue, consumed by internal job
 * cards (rental-work-orders.md §14). Full CRUD, per-agency, soft-delete
 * only. No seeded defaults of its own — unlike RentalFaultType/
 * AgencyServiceType, item pricing and naming is agency-specific from day
 * one with nothing sensible to seed; its `type`/`unit`, however, now pick
 * FROM an agency-configurable list each (RentalCatalogueItemType/
 * RentalCatalogueUnit — Pastel-style enhancement, 2026-10-05).
 *
 * `default_price` (and, §17.4.4, `default_cost`) is ALWAYS the excl-VAT amount, regardless of the agency's
 * capture mode — "store unambiguously" (Johan). When the agency types
 * prices incl-VAT, the create/edit form converts what was typed down to
 * excl before saving (RentalCatalogueItemController); when a job card line
 * pre-fills from this item, RentalJobCardVatService converts back up to
 * whatever the agency currently captures on lines.
 *
 * `code`/`description` (2026-10-05, Johan QA1 finding) replace the old
 * single `name` column — a short, agency-unique-among-active CODE an agent
 * recognises/searches by, and a full DESCRIPTION that prints on the job
 * card/quote. Every existing row's old `name` was migrated to BOTH on
 * 2026_10_05_270100 (nothing lost); `name` itself no longer exists
 * (dropped 2026_10_05_270200).
 */
class RentalCatalogueItem extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    /** @deprecated kept as shared "kind" vocabulary — see RentalCatalogueItemType::KIND_*. The old `type` column no longer exists. */
    public const TYPE_LABOUR = RentalCatalogueItemType::KIND_LABOUR;
    public const TYPE_PART = RentalCatalogueItemType::KIND_PART;

    protected $fillable = [
        'agency_id',
        'rental_catalogue_item_type_id',
        'code',
        'description',
        'rental_catalogue_unit_id',
        'default_price',
        // §17.4.4 — what a part/labour item usually COSTS the agency (same always-excl-VAT rule as default_price). Prefills the cost on a new line; nothing is derived from it silently.
        'default_cost',
        'default_rental_vat_type_id',
        'default_custom_vat_rate',
        'is_active',
        'sort_order',
        'created_by_user_id',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'default_cost' => 'decimal:2',
        'default_custom_vat_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function catalogueItemType(): BelongsTo
    {
        return $this->belongsTo(RentalCatalogueItemType::class, 'rental_catalogue_item_type_id');
    }

    public function catalogueUnit(): BelongsTo
    {
        return $this->belongsTo(RentalCatalogueUnit::class, 'rental_catalogue_unit_id');
    }

    public function defaultVatType(): BelongsTo
    {
        return $this->belongsTo(RentalVatType::class, 'default_rental_vat_type_id');
    }

    /** The underlying kind (labour|part) this item's type maps to — what RentalReportService groups by. Never re-derive type-level logic from this; it's a read, not a classification decision. */
    public function kind(): ?string
    {
        return $this->catalogueItemType?->kind;
    }

    /** "CODE — Description" — the picker/search display label everywhere this item is chosen from. */
    public function label(): string
    {
        return $this->code.' — '.$this->description;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function archive(): void
    {
        $this->delete();
    }

    public function restoreRecord(): void
    {
        $this->restore();
    }
}
