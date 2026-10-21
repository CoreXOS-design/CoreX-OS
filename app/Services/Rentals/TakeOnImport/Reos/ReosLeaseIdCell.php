<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

/**
 * AT-479 / rental-takeon-import.md R2 — the reOS "Lease ID" cell.
 *
 * In the real export the cell is a formula, `=HYPERLINK("https://app.reos.co.za/leases/<uuid>", "<uuid>")`, so a reader
 * that returns cached values gets nothing; OpenSpout returns the formula text instead (FormulaCell::getValue()), and this
 * class takes the uuid and the link out of it. The argument separator is `,` or `;` (a file saved by a semicolon-locale
 * Excel). A cell that is just a bare uuid is accepted for matching and keeps no link — CoreX does not invent one.
 */
final class ReosLeaseIdCell
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /** @return array{uuid:string,url:?string}|null  null = nothing readable in the cell */
    public static function parse(mixed $cell): ?array
    {
        if (! is_string($cell)) {
            return null;
        }
        $text = trim($cell);
        if ($text === '') {
            return null;
        }

        // Bare uuid (values pasted over the formulas).
        if (preg_match('/^(' . self::UUID . ')$/', $text, $m)) {
            return ['uuid' => strtolower($m[1]), 'url' => null];
        }

        // =HYPERLINK("url" , "friendly") — either separator, optional second argument, optional spaces.
        if (preg_match('/^=\s*HYPERLINK\s*\(\s*"([^"]*)"\s*(?:[,;]\s*"([^"]*)"\s*)?\)\s*$/i', $text, $m)) {
            $url = trim($m[1]);
            $friendly = isset($m[2]) ? trim($m[2]) : '';

            $fromUrl = preg_match('#/leases/(' . self::UUID . ')(?:[/?\#]|$)#', $url, $u) ? strtolower($u[1]) : null;
            $fromFriendly = preg_match('/^(' . self::UUID . ')$/', $friendly, $f) ? strtolower($f[1]) : null;

            // The link wins; the friendly text only stands in when the link carries no id. Both present and different
            // is not a cell we trust.
            if ($fromUrl !== null && $fromFriendly !== null && $fromUrl !== $fromFriendly) {
                return null;
            }
            $uuid = $fromUrl ?? $fromFriendly;
            if ($uuid === null) {
                return null;
            }

            return ['uuid' => $uuid, 'url' => $fromUrl !== null && filter_var($url, FILTER_VALIDATE_URL) ? $url : null];
        }

        return null;
    }
}
