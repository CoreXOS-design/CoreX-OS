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
     *
     * @return array{rate: ?float, label: ?string, excl: ?float, vat: ?float, incl: ?float}
     */
    public function lineVat(RentalJobCardLine $line, Agency $agency): array
    {
        $amount = $line->line_total !== null ? (float) $line->line_total : null;

        if (! $agency->vat_registered || $amount === null) {
            return ['rate' => null, 'label' => null, 'excl' => null, 'vat' => null, 'incl' => null];
        }

        $type = $line->rental_vat_type_id ? ($line->vatType ?? RentalVatType::find($line->rental_vat_type_id)) : null;
        $rate = $type
            ? ($type->rate_mode === RentalVatType::RATE_MODE_CUSTOM_PER_LINE
                ? (float) ($line->custom_vat_rate ?? 0)
                : (float) $type->liveRate())
            : 0.0;
        $label = $type?->name ?? 'No VAT';

        return $this->splitAmount($amount, $rate, $agency->vat_capture_mode) + ['rate' => $rate, 'label' => $label];
    }

    /** @return array{excl: float, vat: float, incl: float} */
    private function splitAmount(float $amount, float $ratePercent, string $captureMode): array
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
     * }
     */
    public function breakdown(RentalJobCard $jobCard): array
    {
        $agency = $jobCard->agency ?? Agency::withoutGlobalScopes()->find($jobCard->agency_id);
        $pricesOn = RentalWorkOrderSetting::capturePricesOnJobCardsFor($jobCard->agency_id);

        if (! $agency?->vat_registered || ! $pricesOn) {
            return [
                'registered' => false, 'pricesOn' => $pricesOn, 'captureMode' => null,
                'subtotalExcl' => null, 'totalVat' => null, 'totalIncl' => null, 'groups' => [],
            ];
        }

        $useSnapshot = $jobCard->vat_snapshotted_at !== null;
        $captureMode = $useSnapshot ? $jobCard->vat_capture_mode_snapshot : $agency->vat_capture_mode;

        $subtotalExcl = 0.0;
        $totalVat = 0.0;
        $groups = []; // rate (string key, 2dp) => ['label' => ..., 'rate' => float, 'amount' => float]

        foreach ($jobCard->lines as $line) {
            if ($useSnapshot) {
                if (! $line->isVatSnapshotted()) {
                    continue; // a line added after the freeze (shouldn't happen on a closed card) — no figures to show
                }
                $excl = (float) $line->vat_excl_snapshot;
                $vat = (float) $line->vat_amount_snapshot;
                $incl = (float) $line->vat_incl_snapshot;
                $rate = (float) $line->vat_rate_snapshot;
                $label = $line->vat_type_name_snapshot;
            } else {
                $calc = $this->lineVat($line, $agency);
                if ($calc['excl'] === null) {
                    continue;
                }
                ['excl' => $excl, 'vat' => $vat, 'incl' => $incl, 'rate' => $rate, 'label' => $label] = $calc;
            }

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
