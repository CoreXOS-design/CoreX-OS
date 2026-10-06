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
        // Item capped lower (100px, not 130px) and Description given a real
        // floor (70px, not 0) — 2026-10-05 round 3 (Johan browser check at
        // 1366px): with the old minmax(0,1fr) Description had NO floor, so
        // at 1366 the grid's auto-sizing gave it ~18px (effectively
        // invisible/unusable) once Type/Unit/Qty/Price/VAT's fixed widths
        // ate the row. Those fixed columns are also trimmed here (still
        // comfortably wide enough for their longest realistic value) so the
        // combined floor-respecting budget fits inside the ~604px actually
        // available at 1366px alongside the crew/sign-off right panel.
        $cols = ['minmax(0,100px)', 'minmax(70px,1fr)', '90px'];
        if ($pricesOn) {
            $cols[] = '64px';
            $cols[] = '50px';
            $cols[] = '84px';
            if ($vatRegistered) {
                $cols[] = '84px';
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
