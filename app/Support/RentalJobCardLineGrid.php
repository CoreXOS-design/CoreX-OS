<?php

namespace App\Support;

/**
 * The ONE source of truth for the job card line row's column widths —
 * shared by the column-header row, the existing-lines table, and the
 * add-line row (2026-10-05) so the three can never drift apart and the
 * one-line fit at 1366/1536 (Johan, 2026-10-05 round 2) stays true no
 * matter which of the three files gets touched next.
 *
 * Column order, fixed: Description, [Qty, [Cost,] Unit price (the SELLING price), Total,] actions.
 * Qty/Price/Total only exist at all when $pricesOn. Cost (§17.4.5) exists only when $showCost -
 * the viewer holds `rental_job_cards.view_costs` - and is then absent from the markup entirely
 * for everyone else (server-side, not CSS). Item, Type, Unit, VAT and Margin are not columns
 * (19 Oct 2026): a saved line shows them in a small line under the description.
 */
class RentalJobCardLineGrid
{
    /**
     * The MAIN row's tracks: Description, [Qty,] [Cost,] [Selling, Total,] actions.
     *
     * 19 Oct 2026 (Johan, QA1): the old ten-column grid (Item, Description, Type, Unit, Qty, Cost, Selling, VAT, Margin, actions)
     * needed ~980px and scrolled sideways on a laptop. The low-value columns (item code, type, unit, VAT, margin) now sit in a small
     * line UNDER the description on a saved line, and in a second row of fields (see pickColumns()) on the add / edit row, so the
     * main row is only what the office reads and types every time: Description, Qty, Cost, Selling and the line Total. Every money
     * track is still wide enough for R99,999.99 (66px of text + 18px padding). Description takes every spare pixel.
     *
     * @return array<int, string> grid-template-columns track list
     */
    public static function columns(bool $pricesOn, bool $vatRegistered, bool $showCost = false): array
    {
        $cols = ['minmax(120px,1fr)'];
        if ($pricesOn) {
            $cols[] = '56px';  // Qty
            if ($showCost) {
                $cols[] = '88px'; // Cost
            }
            $cols[] = '88px';  // Selling
            $cols[] = '92px';  // Total
        }
        $cols[] = '40px';      // end-of-row controls (edit, archive)

        return $cols;
    }

    public static function gridStyle(bool $pricesOn, bool $vatRegistered, bool $showCost = false): string
    {
        $cols = implode(' ', self::columns($pricesOn, $vatRegistered, $showCost));

        $gap = '6px';

        return "display:grid; grid-template-columns: {$cols}; gap: {$gap}; align-items:center; min-width:0;";
    }

    /**
     * The add / edit row's FIRST line - the fields that do not earn a column of their own: the item picker, Type, [Unit,] [VAT type].
     * Item takes the spare width; the rest are fixed. (Not used on a saved line - there they are the small line under the description.)
     *
     * @return array<int, string>
     */
    public static function pickColumns(bool $pricesOn, bool $vatRegistered): array
    {
        $cols = ['minmax(130px,1fr)', '104px'];
        if ($pricesOn) {
            $cols[] = '84px';       // Unit
            if ($vatRegistered) {
                $cols[] = '104px';  // VAT type
            }
        }

        return $cols;
    }

    public static function pickGridStyle(bool $pricesOn, bool $vatRegistered): string
    {
        $cols = implode(' ', self::pickColumns($pricesOn, $vatRegistered));

        return "display:grid; grid-template-columns: {$cols}; gap: 6px; align-items:center; min-width:0; margin-bottom:6px;";
    }

    /** Every control inside a row track also needs min-width:0 explicitly — a <select>'s own intrinsic min-content width (driven by its longest option text) otherwise forces the track wider than assigned regardless of the track size itself (every grid item defaults to min-width:auto). The actual fix for the one-line-fit, not the column widths alone. */
    public static function fieldStyle(): string
    {
        // one control height for every input / select in the row (AT-466)
        return 'border: 1px solid var(--border); min-width:0; height:30px; box-sizing:border-box;';
    }

    /** The header row's own labels, in the same fixed order as columns() - one per track. */
    public static function labels(bool $pricesOn, bool $vatRegistered, bool $showCost = false): array
    {
        $labels = ['Description'];
        if ($pricesOn) {
            $labels[] = 'Qty';
            if ($showCost) {
                $labels[] = 'Cost';
            }
            $labels[] = $showCost ? 'Selling' : 'Unit price';
            $labels[] = 'Total';
        }
        $labels[] = '';

        return $labels;
    }

    /** AT-466 - the money / count columns: their header label, saved value and input are all right-aligned. */
    public static function isNumericLabel(string $label): bool
    {
        return in_array($label, ['Qty', 'Cost', 'Selling', 'Unit price', 'Total'], true);
    }

    /** AT-466 - right-aligned input text for the amount boxes (same 9px inset as cellStyle(), on the right). */
    public static function amountFieldStyle(): string
    {
        return self::fieldStyle() . ' text-align:right; font-variant-numeric: tabular-nums;';
    }

    /**
     * §14.24 — the text of a header label / a saved value starts where the text
     * INSIDE an input in the same column starts: 8px padding (px-2) + 1px border.
     * Without it the labels and saved values sat flush at the track's edge, 9px to
     * the left of what the agent types in the boxes right below them.
     */
    public static function cellStyle(): string
    {
        return 'padding-left: 9px;';
    }

    /**
     * §14.24 — the compact icon-sized control at the end of a row (edit ✎, archive ×,
     * add +). `.hfc-card button[type="submit"]` (corex.css) paints EVERY submit button
     * in a card as a big solid block with `!important` padding/background/border, which
     * is what turned the × into a 44x36 dark/blue block; only an inline `!important`
     * beats that, so every declaration here carries it. $bordered = the + (a small
     * outlined square); the pencil and × are plain glyphs.
     */
    public static function iconButtonStyle(string $color, bool $bordered = false): string
    {
        $border = $bordered ? '1px solid var(--border)' : 'none';

        return "display:inline-flex; align-items:center; justify-content:center; width:17px; height:17px; min-width:0; padding:0 !important; margin:0; "
            . "background:transparent !important; border:{$border} !important; border-radius:4px !important; "
            . "color:{$color} !important; font-size:14px; font-weight:600 !important; line-height:1; cursor:pointer;";
    }
}
