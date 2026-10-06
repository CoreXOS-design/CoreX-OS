<?php

namespace App\Services\Rentals;

use App\Models\Agency;
use App\Models\RentalCatalogueItem;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;

/**
 * VAT-per-line, Pastel-style (Johan, 2026-10-05) — computes the excl/VAT/
 * incl breakdown for a job card's lines, grouped by rate for the totals
 * block, and freezes it once (per line + on the card) at "send to owner as
 * quote" or job-card completion so a later change to the agency's VAT
 * registration, rate, capture mode, or VAT type list never alters an
 * issued quote or a closed job card.
 *
 * An agency that is NOT VAT registered never touches any of this — every
 * method below short-circuits to the pre-VAT behaviour (plain line_total,
 * no VAT anywhere) so totals are byte-identical to before this feature.
 */
class RentalJobCardVatService
{
    /**
     * The VAT type a NEW line should start with — the catalogue item's own
     * default when one is picked, else the agency's default VAT type.
     * Returns null for an agency that isn't VAT registered (nothing to
     * default).
     */
    public function defaultVatTypeIdFor(int $agencyId, ?RentalCatalogueItem $catalogueItem): ?int
    {
        if (! Agency::withoutGlobalScopes()->whereKey($agencyId)->value('vat_registered')) {
            return null;
        }

        if ($catalogueItem?->default_rental_vat_type_id) {
            return $catalogueItem->default_rental_vat_type_id;
        }

        return RentalVatType::defaultFor($agencyId)?->id;
    }

    /**
     * One line's live (unsnapshotted) excl/VAT/incl, given the agency's
     * current registration/capture-mode/rate state. Returns nulls when the
     * agency isn't VAT registered or has no amount captured at all.
     * $captureMode overrides the agency's live capture mode — a card that is
     * already frozen computes any line added after the freeze in ITS frozen
     * mode, so one card never mixes two capture modes.
     *
     * @return array{rate: ?float, label: ?string, excl: ?float, vat: ?float, incl: ?float}
     */
    public function lineVat(RentalJobCardLine $line, Agency $agency, ?string $captureMode = null): array
    {
        $amount = $line->line_total !== null ? (float) $line->line_total : null;

        if (! $agency->vat_registered || $amount === null) {
            return ['rate' => null, 'label' => null, 'excl' => null, 'vat' => null, 'incl' => null];
        }

        $type = $line->rental_vat_type_id ? ($line->vatType ?? RentalVatType::find($line->rental_vat_type_id)) : null;
        $rate = $this->rateFor($type, $line->custom_vat_rate);
        $label = $type?->name ?? 'No VAT';

        return $this->splitAmount($amount, $rate, $captureMode ?? $agency->vat_capture_mode) + ['rate' => $rate, 'label' => $label];
    }

    /**
     * §14.25 — a line's EFFECTIVE VAT type as the agent reads it in the
     * add-line select: Standard / None / Custom (or the agency's own type
     * name). A free-text line, or one picked from a catalogue item with no
     * default VAT type, carries no vat type at all — and is charged 0%, i.e.
     * None. Used wherever a line has no entry in breakdown()['lineFigures']
     * (no price yet), so the on-screen column never falls back to a dash.
     */
    public function effectiveTypeLabel(RentalJobCardLine $line): string
    {
        $type = $line->rental_vat_type_id ? ($line->vatType ?? RentalVatType::find($line->rental_vat_type_id)) : null;

        return $type ? $type->shortLabel() : 'None';
    }

    /** A VAT type's live rate — custom_per_line reads the caller's own typed rate, everything else reads the type itself. Null type (free-text line with no VAT type) is 0%. */
    private function rateFor(?RentalVatType $type, $customRate): float
    {
        if (! $type) {
            return 0.0;
        }

        return $type->rate_mode === RentalVatType::RATE_MODE_CUSTOM_PER_LINE
            ? (float) ($customRate ?? 0)
            : (float) $type->liveRate();
    }

    /**
     * Pastel-style enhancement, 2026-10-05 — a catalogue item's own
     * excl/VAT/incl breakdown for the catalogue list/form, given its
     * ALWAYS-excl `default_price` (see RentalCatalogueItem's own docblock)
     * and its default VAT type/custom rate. Null when the item has no
     * default price at all — nothing to break down.
     *
     * @return array{rate: ?float, label: ?string, excl: ?float, vat: ?float, incl: ?float}
     */
    public function catalogueItemPrices(RentalCatalogueItem $item, Agency $agency): array
    {
        if ($item->default_price === null) {
            return ['rate' => null, 'label' => null, 'excl' => null, 'vat' => null, 'incl' => null];
        }

        $excl = (float) $item->default_price;

        if (! $agency->vat_registered) {
            return ['rate' => null, 'label' => null, 'excl' => $excl, 'vat' => null, 'incl' => $excl];
        }

        $type = $item->default_rental_vat_type_id ? ($item->defaultVatType ?? RentalVatType::find($item->default_rental_vat_type_id)) : null;
        $rate = $this->rateFor($type, $item->default_custom_vat_rate);
        $vat = round($excl * $rate / 100, 2);

        return [
            'rate' => $rate, 'label' => $type?->name ?? 'No VAT',
            'excl' => $excl, 'vat' => $vat, 'incl' => round($excl + $vat, 2),
        ];
    }

    /**
     * The amount a job card line should pre-fill when it picks this
     * catalogue item — the item's always-excl default_price, converted to
     * whatever the agency currently captures on job card lines. Not
     * registered, or the item has no default price: passed through
     * unchanged (identical to the pre-VAT behaviour).
     */
    public function catalogueDefaultPriceForLine(RentalCatalogueItem $item, Agency $agency): ?float
    {
        if ($item->default_price === null) {
            return null;
        }

        $excl = (float) $item->default_price;

        if (! $agency->vat_registered || $agency->vat_capture_mode !== Agency::VAT_CAPTURE_INCL) {
            return $excl;
        }

        $type = $item->default_rental_vat_type_id ? ($item->defaultVatType ?? RentalVatType::find($item->default_rental_vat_type_id)) : null;
        $rate = $this->rateFor($type, $item->default_custom_vat_rate);

        return round($excl * (1 + $rate / 100), 2);
    }

    /** @return array{excl: float, vat: float, incl: float} */
    public function splitAmount(float $amount, float $ratePercent, string $captureMode): array
    {
        if ($captureMode === Agency::VAT_CAPTURE_INCL) {
            $incl = round($amount, 2);
            $excl = $ratePercent > 0 ? round($incl / (1 + $ratePercent / 100), 2) : $incl;
            $vat = round($incl - $excl, 2);
        } else {
            $excl = round($amount, 2);
            $vat = round($excl * $ratePercent / 100, 2);
            $incl = round($excl + $vat, 2);
        }

        return ['excl' => $excl, 'vat' => $vat, 'incl' => $incl];
    }

    /**
     * The full breakdown for a job card — live for a draft/open card, or
     * read straight off each line's frozen snapshot once one exists (the
     * card is snapshotted all-or-nothing, so checking the card's own
     * vat_snapshotted_at is enough to know which source to read).
     *
     * Appends vat_display_* attributes onto each line instance so the
     * show/print/quote views can render per-line figures without a second
     * lookup. Not registered, or prices off entirely: returns
     * registered=false / pricesOn=false and nothing else is computed —
     * totals stay exactly the pre-VAT total_amount figure.
     *
     * @return array{
     *   registered: bool, pricesOn: bool, captureMode: ?string,
     *   subtotalExcl: ?float, totalVat: ?float, totalIncl: ?float,
     *   groups: array<int, array{label: string, rate: float, amount: float}>,
     *   lineFigures: array<int, array{excl: float, vat: float, incl: float, rate: float, label: ?string}>,
     * }

     * lineFigures (2026-10-05 evening, §14.21) — the same per-line figures
     * keyed by line id. The PDF partials iterate `$task->lines`, which are
     * DIFFERENT model instances from the `$jobCard->lines` the vat_display_*
     * attributes are written onto, so reading those attributes there always
     * came back empty (the "—" Johan saw in the VAT type column). Anything
     * rendering a line outside the show screen reads lineFigures instead.
     */
    public function breakdown(RentalJobCard $jobCard): array
    {
        $agency = $jobCard->agency ?? Agency::withoutGlobalScopes()->find($jobCard->agency_id);
        $pricesOn = RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id);

        if (! $agency?->vat_registered || ! $pricesOn) {
            return [
                'registered' => false, 'pricesOn' => $pricesOn, 'captureMode' => null,
                'subtotalExcl' => null, 'totalVat' => null, 'totalIncl' => null, 'groups' => [], 'lineFigures' => [],
            ];
        }

        $useSnapshot = $jobCard->vat_snapshotted_at !== null;
        $captureMode = $useSnapshot ? $jobCard->vat_capture_mode_snapshot : $agency->vat_capture_mode;

        $subtotalExcl = 0.0;
        $totalVat = 0.0;
        $groups = []; // rate (string key, 2dp) => ['label' => ..., 'rate' => float, 'amount' => float]
        $lineFigures = [];

        foreach ($jobCard->lines as $line) {
            if ($useSnapshot && $line->isVatSnapshotted()) {
                $excl = (float) $line->vat_excl_snapshot;
                $vat = (float) $line->vat_amount_snapshot;
                $incl = (float) $line->vat_incl_snapshot;
                $rate = (float) $line->vat_rate_snapshot;
                $label = $line->vat_type_name_snapshot;
            } else {
                // Live card — or a frozen card's line that has no snapshot
                // (added after the freeze by a path that did not freeze it).
                // §14.21: never skip such a line, or the totals silently drop
                // it. Computed live, in the card's frozen capture mode.
                $calc = $this->lineVat($line, $agency, $useSnapshot ? $captureMode : null);
                if ($calc['excl'] === null) {
                    continue;
                }
                ['excl' => $excl, 'vat' => $vat, 'incl' => $incl, 'rate' => $rate, 'label' => $label] = $calc;
            }

            $lineFigures[$line->id] = [
                'excl' => $excl, 'vat' => $vat, 'incl' => $incl, 'rate' => $rate, 'label' => $label,
                // §14.25 — the on-screen VAT column's wording (Standard / None / Custom, as in the add-line select).
                'type_label' => $label !== null && $label !== '' ? RentalVatType::shortenName((string) $label) : 'None',
            ];

            $line->setAttribute('vat_display_excl', $excl);
            $line->setAttribute('vat_display_vat', $vat);
            $line->setAttribute('vat_display_incl', $incl);
            $line->setAttribute('vat_display_label', $label);
            $line->setAttribute('vat_display_rate', $rate);

            $subtotalExcl += $excl;
            $totalVat += $vat;

            if ($rate > 0) {
                $key = number_format($rate, 2, '.', '');
                $groups[$key] ??= ['label' => "VAT @ {$this->formatRate($rate)}%", 'rate' => $rate, 'amount' => 0.0];
                $groups[$key]['amount'] += $vat;
            }
        }

        ksort($groups, SORT_NUMERIC);

        return [
            'registered' => true,
            'pricesOn' => true,
            'captureMode' => $captureMode,
            'subtotalExcl' => round($subtotalExcl, 2),
            'totalVat' => round($totalVat, 2),
            'totalIncl' => round($subtotalExcl + $totalVat, 2),
            'groups' => array_values(array_map(fn ($g) => $g + ['amount' => round($g['amount'], 2)], $groups)),
            'lineFigures' => $lineFigures,
        ];
    }

    private function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2), '0'), '.');
    }

    /**
     * Freezes the VAT state onto the card and every current line — called
     * once at "send to owner as quote" and again at completion (whichever
     * happens first; re-freezing on a second quote send is deliberate, same
     * "freeze at the moment of truth" reasoning the Proforma invoice
     * snapshot already uses). A no-op for an agency that isn't VAT
     * registered or has prices off — nothing to freeze.
     */
    public function snapshot(RentalJobCard $jobCard): void
    {
        $agency = $jobCard->agency ?? Agency::withoutGlobalScopes()->find($jobCard->agency_id);
        $pricesOn = RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id);

        $jobCard->forceFill([
            'vat_registered_snapshot' => (bool) $agency?->vat_registered,
            'vat_capture_mode_snapshot' => $agency?->vat_capture_mode,
            'vat_snapshotted_at' => now(),
        ])->save();

        if (! $agency?->vat_registered || ! $pricesOn) {
            return;
        }

        foreach ($jobCard->lines as $line) {
            $calc = $this->lineVat($line, $agency);
            if ($calc['excl'] === null) {
                continue;
            }

            $line->forceFill([
                'vat_type_name_snapshot' => $calc['label'],
                'vat_rate_snapshot' => $calc['rate'],
                'vat_excl_snapshot' => $calc['excl'],
                'vat_amount_snapshot' => $calc['vat'],
                'vat_incl_snapshot' => $calc['incl'],
            ])->save();
        }
    }

    /**
     * Re-freezes ONE line's VAT figures on a card that is already frozen
     * (quote sent, not completed) — called after that line is edited, ADDED
     * or RESTORED (§14.21), so breakdown() (which reads the snapshots once a
     * card is frozen) shows the change, in the card's frozen capture mode. Every other line keeps the figures it was issued with.
     * A line left with no amount (price cleared) loses its snapshot rather
     * than keeping the previous excl/VAT/incl behind.
     */
    public function refreshLineSnapshot(RentalJobCard $jobCard, RentalJobCardLine $line): void
    {
        $agency = $jobCard->agency ?? Agency::withoutGlobalScopes()->find($jobCard->agency_id);
        $pricesOn = RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id);

        if (! $agency?->vat_registered || ! $pricesOn) {
            return;
        }

        $calc = $this->lineVat($line, $agency, $jobCard->vat_snapshotted_at !== null ? $jobCard->vat_capture_mode_snapshot : null);

        $line->forceFill($calc['excl'] === null ? [
            'vat_type_name_snapshot' => null, 'vat_rate_snapshot' => null, 'vat_excl_snapshot' => null,
            'vat_amount_snapshot' => null, 'vat_incl_snapshot' => null,
        ] : [
            'vat_type_name_snapshot' => $calc['label'],
            'vat_rate_snapshot' => $calc['rate'],
            'vat_excl_snapshot' => $calc['excl'],
            'vat_amount_snapshot' => $calc['vat'],
            'vat_incl_snapshot' => $calc['incl'],
        ])->save();
    }

    /**
     * The figure that must go to the owner as the quote amount, and the
     * figure the landlord no-approval spend threshold is compared against
     * — VAT-INCLUSIVE, because that is what the landlord actually pays.
     * Not registered (or prices off): identical to total_amount, unchanged
     * behaviour.
     */
    public function inclusiveTotal(RentalJobCard $jobCard): float
    {
        $breakdown = $this->breakdown($jobCard);

        return $breakdown['registered'] ? (float) $breakdown['totalIncl'] : (float) ($jobCard->total_amount ?? 0);
    }
}
