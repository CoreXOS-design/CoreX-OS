<?php

namespace App\Services\Rentals;

use App\Models\Agency;
use App\Models\RentalCatalogueItem;
use App\Models\RentalVatType;

/**
 * .ai/specs/rental-work-orders.md §17.38 — the ONE place the catalogue item's cost / markup / selling maths lives.
 *
 * The edit screen runs the same rules in Alpine for instant feedback; this class is what actually decides the figures
 * that get stored (the posted figures are only trusted for the field the user last edited — the "driver"). Pure maths:
 * no database writes, and the only reads are the VAT type's rate.
 *
 * Rules (all amounts rounded to 2 decimals, markup % to 4):
 *   - excl and incl are BOTH typeable on cost and on selling; the other one follows through the item's VAT rate.
 *   - Markup is on COST excl: rand = selling excl − cost excl; percent = (selling excl − cost excl) ÷ cost excl × 100.
 *   - Last edit wins between markup and selling: driver `markup` sets selling from cost + markup; driver `selling_excl` /
 *     `selling_incl` sets selling and recalculates the markup from cost. A selling below cost is allowed (negative markup).
 */
class CatalogueItemPricing
{
    public const MARKUP_RAND = 'rand';
    public const MARKUP_PERCENT = 'percent';

    public const DRIVER_MARKUP = 'markup';
    public const DRIVER_SELLING_EXCL = 'selling_excl';
    public const DRIVER_SELLING_INCL = 'selling_incl';

    public const COST_BASIS_EXCL = 'excl';
    public const COST_BASIS_INCL = 'incl';

    /** The VAT rate (%) an item's figures are held at: 0 for an agency that is not VAT registered or an item with no VAT type. */
    public function rateFor(?Agency $agency, ?int $vatTypeId, mixed $customRate): float
    {
        if (! $agency?->vat_registered || ! $vatTypeId) {
            return 0.0;
        }

        $type = RentalVatType::withoutGlobalScopes()->where('agency_id', $agency->id)->find($vatTypeId);
        if (! $type) {
            return 0.0;
        }

        return $type->rate_mode === RentalVatType::RATE_MODE_CUSTOM_PER_LINE
            ? max(0.0, (float) ($customRate ?? 0))
            : max(0.0, (float) $type->liveRate());
    }

    public function rateForItem(RentalCatalogueItem $item): float
    {
        $agency = Agency::withoutGlobalScopes()->find($item->agency_id);

        return $this->rateFor($agency, $item->default_rental_vat_type_id, $item->default_custom_vat_rate);
    }

    public function inclFromExcl(float $excl, float $rate): float
    {
        return round($excl * (1 + $rate / 100), 2);
    }

    public function exclFromIncl(float $incl, float $rate): float
    {
        return $rate > 0 ? round($incl / (1 + $rate / 100), 2) : round($incl, 2);
    }

    /** The markup, in the chosen unit, that takes cost excl to selling excl. Null when it cannot be expressed (percent of a nil cost). */
    public function markupFrom(float $costExcl, float $sellingExcl, string $type): ?float
    {
        if ($type === self::MARKUP_RAND) {
            return round($sellingExcl - $costExcl, 2);
        }

        return $costExcl > 0 ? round(($sellingExcl - $costExcl) / $costExcl * 100, 4) : null;
    }

    public function sellingFromMarkup(float $costExcl, float $markupValue, string $type): float
    {
        return round($type === self::MARKUP_RAND ? $costExcl + $markupValue : $costExcl * (1 + $markupValue / 100), 2);
    }

    /** Converts the current markup to the other unit without moving the selling price (R↔% toggle). Null when percent of a nil cost. */
    public function convertMarkup(float $costExcl, float $markupValue, string $from, string $to): ?float
    {
        if ($from === $to) {
            return $markupValue;
        }

        return $this->markupFrom($costExcl, $this->sellingFromMarkup($costExcl, $markupValue, $from), $to);
    }

    /**
     * Resolve the whole pricing block from what was posted. Every key of $in is nullable; empty strings count as blank.
     *
     * @param  array{cost_excl?: mixed, cost_incl?: mixed, selling_excl?: mixed, selling_incl?: mixed, markup_type?: mixed, markup_value?: mixed}  $in
     * @return array{cost_excl: ?float, cost_incl: ?float, markup_type: string, markup_value: ?float, selling_excl: ?float, selling_incl: ?float, pricing_driver: ?string}
     */
    public function resolve(array $in, float $rate, string $costBasis, string $driver, string $defaultMarkupType = self::MARKUP_PERCENT): array
    {
        $num = fn ($k) => isset($in[$k]) && $in[$k] !== '' && is_numeric($in[$k]) ? (float) $in[$k] : null;

        $markupType = in_array($in['markup_type'] ?? null, [self::MARKUP_RAND, self::MARKUP_PERCENT], true) ? $in['markup_type'] : $defaultMarkupType;
        $markupValue = $num('markup_value');

        // COST — the basis the user last typed in is authoritative; the other follows.
        $costExcl = $costIncl = null;
        if ($costBasis === self::COST_BASIS_INCL && $num('cost_incl') !== null) {
            $costIncl = round($num('cost_incl'), 2);
            $costExcl = $this->exclFromIncl($costIncl, $rate);
        } elseif ($num('cost_excl') !== null) {
            $costExcl = round($num('cost_excl'), 2);
            $costIncl = $this->inclFromExcl($costExcl, $rate);
        }

        $sellExcl = $sellIncl = null;

        // A markup can only drive the selling price when there is a markup to add. A markup with no cost yet stays the driver (the
        // selling price is simply not known yet); a selling price that WAS typed alongside it, with nothing to add the markup to,
        // is the user's word and wins. No markup at all = a selling price (or nothing).
        if ($driver === self::DRIVER_MARKUP && $markupValue === null) {
            $driver = $num('selling_excl') !== null ? self::DRIVER_SELLING_EXCL : self::DRIVER_SELLING_INCL;
        } elseif ($driver === self::DRIVER_MARKUP && $costExcl === null && ($num('selling_excl') !== null || $num('selling_incl') !== null)) {
            $driver = $num('selling_excl') !== null ? self::DRIVER_SELLING_EXCL : self::DRIVER_SELLING_INCL;
        }

        if ($driver === self::DRIVER_MARKUP) {
            if ($costExcl !== null) {
                $sellExcl = $this->sellingFromMarkup($costExcl, $markupValue, $markupType);
                $sellIncl = $this->inclFromExcl($sellExcl, $rate);
                // A derived R0.00 (no real cost behind a % markup) is not a price: store the markup and leave the selling blank.
                if ($sellExcl == 0.0) {
                    $sellExcl = $sellIncl = null;
                }
            }
        } elseif ($driver === self::DRIVER_SELLING_INCL && $num('selling_incl') !== null) {
            $sellIncl = round($num('selling_incl'), 2);
            $sellExcl = $this->exclFromIncl($sellIncl, $rate);
        } elseif ($num('selling_excl') !== null) {
            $sellExcl = round($num('selling_excl'), 2);
            $sellIncl = $this->inclFromExcl($sellExcl, $rate);
        }

        // Selling typed → the markup follows from cost. A selling price with no cost behind it has no markup at all.
        // Otherwise (a markup typed, nothing else yet) the markup stays as typed.
        if ($driver !== self::DRIVER_MARKUP && $sellExcl !== null) {
            $markupValue = $costExcl !== null ? $this->markupFrom($costExcl, $sellExcl, $markupType) : null;
        }

        return [
            'cost_excl' => $costExcl,
            'cost_incl' => $costIncl,
            'markup_type' => $markupType,
            'markup_value' => $markupValue,
            'selling_excl' => $sellExcl,
            'selling_incl' => $sellIncl,
            // What PRICES this item: the markup (a job card line works its selling out from the line's own cost) or a fixed selling price.
            'pricing_driver' => $driver === self::DRIVER_MARKUP ? 'markup' : ($sellExcl !== null ? 'selling' : null),
        ];
    }
}
