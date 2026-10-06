<?php

namespace App\Services\Rentals;

use App\Models\Agency;
use App\Models\RentalCatalogueItem;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;

/**
 * .ai/specs/rental-work-orders.md §17.4 — the ONE place a line's SELLING price is
 * resolved from its COST (and the ONLY place that knows the §17.4.3 rule order:
 * manual → line markup → card parts/labour % → card all-lines % → catalogue
 * default price → agency default markup).
 *
 * Vocabulary (§17.2): COST is what the work actually cost the agency (`unit_cost`/`cost_total`); SELLING is
 * what the owner is charged and lives in the EXISTING `unit_price`/`line_total`. Both are held on the SAME VAT
 * basis (the agency's capture mode) and the line's VAT type applies to both; margin is always read on the
 * excl-VAT figures.
 *
 * Nothing here ever invents a cost: a line with no `unit_cost` has no cost-based selling (blank, never 0), and a
 * pre-existing line keeps `selling_basis = manual` with its typed price untouched.
 */
class RentalPricingService
{
    public function __construct(private RentalJobCardVatService $vat)
    {
    }

    /**
     * What the selling price of this line should be right now, and which rule decided it.
     * Never writes. A blank resolution (no manual price and nothing to price from) has null unit price/total.
     */
    public function resolveSelling(RentalJobCardLine $line, RentalJobCard $card): SellingResolution
    {
        $qty = $line->quantity !== null ? (float) $line->quantity : 1.0;
        $cost = $line->unit_cost !== null ? (float) $line->unit_cost : null;

        // 1 — the office typed a selling price on this line.
        if ($line->selling_basis === RentalJobCardLine::BASIS_MANUAL && $line->unit_price !== null) {
            $unit = (float) $line->unit_price;

            return new SellingResolution($unit, round($qty * $unit, 2), RentalJobCardLine::BASIS_MANUAL);
        }

        // 2 — the line has its own markup (needs a cost to mark up).
        if ($cost !== null && $line->markup_type !== null && $line->markup_value !== null) {
            if ($line->markup_type === RentalJobCardLine::MARKUP_AMOUNT) {
                $costTotal = round($qty * $cost, 2);
                $lineTotal = round($costTotal + (float) $line->markup_value, 2);

                // `amount` is a set sum on top of the LINE (not per unit): line_total is authoritative, unit_price is display only.
                return new SellingResolution($qty > 0 ? round($lineTotal / $qty, 2) : null, $lineTotal, RentalJobCardLine::BASIS_LINE_MARKUP);
            }

            return $this->percent($cost, $qty, (float) $line->markup_value, RentalJobCardLine::BASIS_LINE_MARKUP);
        }

        $isPart = $line->type === RentalCatalogueItem::TYPE_PART;

        // 3 — the card has a % for this kind of line; 4 — the card has a % across the whole job.
        if ($cost !== null) {
            $kindPercent = $isPart ? $card->markup_parts_percent : $card->markup_labour_percent;
            if ($kindPercent !== null) {
                return $this->percent($cost, $qty, (float) $kindPercent, RentalJobCardLine::BASIS_JOB_MARKUP);
            }
            if ($card->markup_all_percent !== null) {
                return $this->percent($cost, $qty, (float) $card->markup_all_percent, RentalJobCardLine::BASIS_JOB_MARKUP);
            }
        }

        // 5 — the catalogue item has a default selling price, in the agency's capture mode.
        $item = $line->rental_catalogue_item_id ? ($line->catalogueItem ?? RentalCatalogueItem::find($line->rental_catalogue_item_id)) : null;
        if ($item && $item->default_price !== null) {
            $agency = $this->agencyFor($card);
            $unit = $agency ? $this->vat->catalogueDefaultPriceForLine($item, $agency) : (float) $item->default_price;
            if ($unit !== null) {
                return new SellingResolution($unit, round($qty * $unit, 2), RentalJobCardLine::BASIS_CATALOGUE_PRICE);
            }
        }

        // 6 — the agency's default markup for this kind of line (0 % until the agency sets its own numbers).
        if ($cost !== null) {
            $default = $isPart
                ? RentalWorkOrderSetting::defaultPartsMarkupPercentFor($card->agency_id)
                : RentalWorkOrderSetting::defaultLabourMarkupPercentFor($card->agency_id);

            return $this->percent($cost, $qty, $default, RentalJobCardLine::BASIS_AGENCY_DEFAULT);
        }

        // Nothing to price from: blank, never a 0.
        return new SellingResolution(null, null, RentalJobCardLine::BASIS_MANUAL);
    }

    /** selling = cost + p %, rounded per unit and then per line (§17.4.3). */
    private function percent(float $cost, float $qty, float $percent, string $basis): SellingResolution
    {
        $unit = round($cost * (1 + $percent / 100), 2);

        return new SellingResolution($unit, round($qty * $unit, 2), $basis, $percent, abs($percent) < 0.005);
    }

    /**
     * Keep `cost_total` = qty × unit_cost in step with the line. A line with no unit cost keeps a null cost_total —
     * no cost is ever back-filled or invented (§17.4.2). Does not save.
     */
    public function syncCostTotal(RentalJobCardLine $line): void
    {
        $qty = $line->quantity !== null ? (float) $line->quantity : 1.0;
        $line->cost_total = $line->unit_cost !== null ? round($qty * (float) $line->unit_cost, 2) : null;
    }

    /**
     * Recompute ONE line from its cost and persist selling (+ cost_total). Prices off for the agency = nothing to price.
     * A line whose price the office typed by hand (`manual`) keeps its price (its line total still follows quantity).
     */
    public function repriceLine(RentalJobCardLine $line): void
    {
        $card = $line->relationLoaded('jobCard') && $line->jobCard ? $line->jobCard : $line->jobCard()->withoutGlobalScopes()->first();
        if (! $card || ! RentalWorkOrderSetting::capturePricesOnJobCardsFor($card->agency_id)) {
            return;
        }

        $this->syncCostTotal($line);
        $resolution = $this->resolveSelling($line, $card);

        $line->forceFill([
            'unit_price' => $resolution->unitPrice,
            'line_total' => $resolution->lineTotal,
            'selling_basis' => $resolution->basis,
        ])->save();

        // A card whose VAT is already frozen (quote sent) keeps every OTHER line's figures; this line gets its own straight away.
        if ($card->vat_snapshotted_at !== null && ! $card->isClosed()) {
            $this->vat->refreshLineSnapshot($card, $line->refresh());
        }
    }

    /**
     * Recompute every ACCEPTED line that is not `manual` / `line_markup` (those two are the office's own words and are never
     * touched here), then refresh the card's cached totals. Called after any markup change (§17.4.4).
     */
    public function repriceCard(RentalJobCard $card): void
    {
        $card->lines()->accepted()->get()->each(function (RentalJobCardLine $line) use ($card) {
            $typedByHand = $line->selling_basis === RentalJobCardLine::BASIS_MANUAL && $line->unit_price !== null;
            if ($typedByHand || $line->selling_basis === RentalJobCardLine::BASIS_LINE_MARKUP) {
                return;
            }
            $line->setRelation('jobCard', $card);
            $this->repriceLine($line);
        });

        $card->recalcTotal();
    }

    /**
     * Set (or clear with null) a card-level markup — $scope is `all`, `parts` or `labour` — reprice, and log
     * one history row ("Parts markup set to 20 %"). The caller enforces `rental_job_cards.price`.
     */
    public function applyJobMarkup(RentalJobCard $card, string $scope, ?float $percent, ?User $by): void
    {
        $column = match ($scope) {
            'all' => 'markup_all_percent',
            'parts' => 'markup_parts_percent',
            'labour' => 'markup_labour_percent',
            default => throw new \InvalidArgumentException('Markup scope must be all, parts or labour.'),
        };
        if ($percent !== null && ($percent < 0 || $percent > 1000)) {
            throw new \InvalidArgumentException('A markup percentage must be between 0 and 1000.');
        }
        $card->assertContentEditable();

        $card->forceFill([$column => $percent !== null ? round($percent, 2) : null])->save();
        $this->repriceCard($card);

        $label = match ($scope) {
            'all' => 'All-lines markup',
            'parts' => 'Parts markup',
            'labour' => 'Labour markup',
        };
        $card->logUpdate(
            'markup_set',
            $by,
            $percent !== null ? "{$label} set to " . rtrim(rtrim(number_format($percent, 2), '0'), '.') . ' %' : "{$label} cleared",
        );
    }

    /**
     * Cost, selling and margin for a card on the EXCL-VAT basis (§17.4.5), over ACCEPTED lines only.
     *
     * `linesWithoutCost` is the count of accepted lines with no cost recorded, so a partial margin is never presented as
     * complete. The margin itself is taken over the lines that carry BOTH a cost and a selling price (`marginableLines`),
     * so a line with no cost can never inflate it; `sellingExcl` is the whole job (what the owner pays, excl VAT) and
     * `partial` is true whenever any accepted line is missing a cost or a selling price.
     *
     * @return array{costExcl: float, sellingExcl: float, marginExcl: float, marginPct: ?float, linesWithoutCost: int, marginableLines: int, partial: bool}
     */
    public function marginFor(RentalJobCard $card): array
    {
        $agency = $this->agencyFor($card);
        $costBreakdown = $this->vat->costBreakdown($card);
        $registered = $costBreakdown['registered'];
        $mode = $card->vat_snapshotted_at !== null ? $card->vat_capture_mode_snapshot : null;

        $sellingExcl = 0.0;
        $sellingMarginable = 0.0;
        $costMarginable = 0.0;
        $withoutCost = 0;
        $marginable = 0;
        $incomplete = false;

        foreach ($card->lines->filter(fn (RentalJobCardLine $l) => $l->isAccepted()) as $line) {
            $sellExcl = null;
            if ($line->line_total !== null) {
                $sellExcl = $registered && $agency
                    ? (float) ($this->vat->lineVat($line, $agency, $mode)['excl'] ?? $line->line_total)
                    : (float) $line->line_total;
                $sellingExcl += $sellExcl;
            } else {
                $incomplete = true;
            }

            if ($line->cost_total === null) {
                $withoutCost++;
                continue;
            }
            if ($sellExcl === null) {
                continue;
            }
            $marginable++;
            $sellingMarginable += $sellExcl;
            $costMarginable += (float) ($costBreakdown['lineFigures'][$line->id]['excl'] ?? 0);
        }

        $margin = round($sellingMarginable - $costMarginable, 2);

        return [
            'costExcl' => round((float) ($costBreakdown['subtotalExcl'] ?? 0), 2),
            'sellingExcl' => round($sellingExcl, 2),
            'marginExcl' => $margin,
            'marginPct' => $sellingMarginable > 0 ? round($margin / $sellingMarginable * 100, 1) : null,
            'linesWithoutCost' => $withoutCost,
            'marginableLines' => $marginable,
            'partial' => $withoutCost > 0 || $incomplete,
        ];
    }

    /** §17.4.5 — the small label under a line's selling price saying how it was arrived at. */
    public static function basisLabel(RentalJobCardLine $line, RentalJobCard $card): string
    {
        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . ' %';
        $isPart = $line->type === RentalCatalogueItem::TYPE_PART;

        return match ($line->selling_basis) {
            RentalJobCardLine::BASIS_LINE_MARKUP => $line->markup_type === RentalJobCardLine::MARKUP_AMOUNT
                ? '+ R' . number_format((float) $line->markup_value, 2) . ' line'
                : '+' . $pct($line->markup_value) . ' line',
            RentalJobCardLine::BASIS_JOB_MARKUP => $isPart && $card->markup_parts_percent !== null
                ? 'parts ' . $pct($card->markup_parts_percent)
                : (! $isPart && $card->markup_labour_percent !== null
                    ? 'labour ' . $pct($card->markup_labour_percent)
                    : 'job ' . $pct($card->markup_all_percent)),
            RentalJobCardLine::BASIS_CATALOGUE_PRICE => 'catalogue',
            RentalJobCardLine::BASIS_AGENCY_DEFAULT => ($isPart
                ? RentalWorkOrderSetting::defaultPartsMarkupPercentFor($card->agency_id)
                : RentalWorkOrderSetting::defaultLabourMarkupPercentFor($card->agency_id)) < 0.005
                    ? 'no markup applied'
                    : 'agency default',
            default => $line->unit_price !== null ? 'set by hand' : '',
        };
    }

    private function agencyFor(RentalJobCard $card): ?Agency
    {
        return $card->agency ?? Agency::withoutGlobalScopes()->find($card->agency_id);
    }
}
