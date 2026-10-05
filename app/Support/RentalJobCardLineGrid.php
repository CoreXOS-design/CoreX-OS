<?php

namespace App\Support;

/**
 * The ONE source of truth for the job card line row's column widths —
 * shared by the column-header row, the existing-lines table, and the
 * add-line row (2026-10-05) so the three can never drift apart and the
 * one-line fit at 1366/1536 (Johan, 2026-10-05 round 2) stays true no
 * matter which of the three files gets touched next.
 *
 * Column order, fixed: Item, Description, Type, Unit, Qty, Unit price,
 * VAT, (blank for the +/archive action). Unit/Qty/Unit price/VAT only
 * exist at all when $pricesOn; VAT only when the agency is $vatRegistered
 * on top of that.
 */
class RentalJobCardLineGrid
{
    /** @return array<int, string> grid-template-columns track list */
    public static function columns(bool $pricesOn, bool $vatRegistered): array
    {
        $cols = ['minmax(0,130px)', 'minmax(0,1fr)', '110px'];
        if ($pricesOn) {
            $cols[] = '70px';
            $cols[] = '56px';
            $cols[] = '92px';
            if ($vatRegistered) {
                $cols[] = '96px';
            }
        }
        $cols[] = '36px';

        return $cols;
    }

    public static function gridStyle(bool $pricesOn, bool $vatRegistered): string
    {
        $cols = implode(' ', self::columns($pricesOn, $vatRegistered));

        return "display:grid; grid-template-columns: {$cols}; gap: 6px; align-items:center; min-width:0;";
    }

    /** Every control inside a row track also needs min-width:0 explicitly — a <select>'s own intrinsic min-content width (driven by its longest option text) otherwise forces the track wider than assigned regardless of the track size itself (every grid item defaults to min-width:auto). The actual fix for the one-line-fit, not the column widths alone. */
    public static function fieldStyle(): string
    {
        return 'border: 1px solid var(--border); min-width:0;';
    }

    /** The header row's own labels, in the same fixed order as columns() -- callers slice this to match whichever optional columns are actually rendered. */
    public static function labels(bool $pricesOn, bool $vatRegistered): array
    {
        $labels = ['Item', 'Description', 'Type'];
        if ($pricesOn) {
            $labels[] = 'Unit';
            $labels[] = 'Qty';
            $labels[] = 'Unit price';
            if ($vatRegistered) {
                $labels[] = 'VAT';
            }
        }
        $labels[] = '';

        return $labels;
    }
}
