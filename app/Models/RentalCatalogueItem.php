<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\Rentals\CatalogueItemPricing;
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
        // §17.38 — the pricing block. selling_excl/cost_excl are the figures job cards read; default_price/default_cost mirror them.
        'cost_excl',
        'cost_incl',
        'selling_excl',
        'selling_incl',
        'markup_type',
        'markup_value',
        'pricing_driver',
        'default_rental_vat_type_id',
        'default_custom_vat_rate',
        'is_active',
        'sort_order',
        'created_by_user_id',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'default_cost' => 'decimal:2',
        'cost_excl' => 'decimal:2',
        'cost_incl' => 'decimal:2',
        'selling_excl' => 'decimal:2',
        'selling_incl' => 'decimal:2',
        'markup_value' => 'decimal:4',
        'default_custom_vat_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * §17.38 — `default_price` (SELLING excl) and `default_cost` (COST excl) are the legacy columns; `selling_excl` /
     * `cost_excl` are the source of truth now. A writer that only knows the legacy names (the CSV import, older
     * fixtures) is carried across: the new figure is taken from it and the incl figure worked out at the item's VAT rate.
     * A writer that sets the new figure has the legacy column kept in step. Neither is ever derived from a stale value.
     */
    public const DRIVER_MARKUP = 'markup';
    public const DRIVER_SELLING = 'selling';

    protected static function booted(): void
    {
        static::saving(function (RentalCatalogueItem $item) {
            $pricing = null;
            foreach (['default_price' => ['selling_excl', 'selling_incl'], 'default_cost' => ['cost_excl', 'cost_incl']] as $legacy => [$excl, $incl]) {
                if ($item->isDirty($excl)) {
                    $item->{$legacy} = $item->{$excl};
                } elseif ($item->isDirty($legacy)) {
                    $pricing ??= app(CatalogueItemPricing::class);
                    $item->{$excl} = $item->{$legacy};
                    $item->markup_value = null; // the stored markup was for the old figure — re-derived below
                    if ($legacy === 'default_price' && $item->{$excl} !== null && ! $item->isDirty('pricing_driver')) {
                        $item->pricing_driver = self::DRIVER_SELLING; // a writer that hands over a price is typing a selling price
                    }
                    $item->{$incl} = $item->{$legacy} === null ? null : $pricing->inclFromExcl((float) $item->{$legacy}, $pricing->rateForItem($item));
                }
            }

            if ($item->pricing_driver === null && $item->selling_excl !== null) {
                $item->pricing_driver = self::DRIVER_SELLING;
            }

            if ($item->markup_value === null && $item->selling_excl !== null && $item->cost_excl !== null && ($item->isDirty('selling_excl') || $item->isDirty('cost_excl'))) {
                $pricing ??= app(CatalogueItemPricing::class);
                $type = $item->markup_type ?: CatalogueItemPricing::MARKUP_PERCENT;
                $markup = $pricing->markupFrom((float) $item->cost_excl, (float) $item->selling_excl, $type);
                if ($markup !== null) {
                    $item->markup_type = $type;
                    $item->markup_value = $markup;
                }
            }
        });
    }

    /** True when this item is priced by cost + markup (a job card line works its selling out from the LINE's cost), not by a fixed selling price. */
    public function pricedByMarkup(): bool
    {
        return $this->pricing_driver === self::DRIVER_MARKUP && $this->markup_value !== null;
    }

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
