<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

/**
 * AT-479 / rental-takeon-import.md R2 — the optional reOS "Rent Roll" report. Sheet "Rent Roll", headers on row 4. Only the
 * first six columns are read and checked; ONLY the Tenant Wallet is of any use to CoreX — the money columns are neither
 * read nor kept.
 */
class ReosRentRollParser
{
    public const SHEET = 'Rent Roll';

    public const HEADERS = ['Property Address', 'Agent', 'Owner', 'Tenant', 'Tenant Wallet', 'Lease End'];

    public function assertLayout(string $path): void
    {
        foreach (ReosWorkbookReader::rows($path, self::SHEET, self::HEADERS, 'Rent Roll') as $_) {
            return;
        }
    }

    /** @return list<array{row_number:int, address:?string, tenant:?string, wallet:?string}> */
    public function rows(string $path): array
    {
        $out = [];
        foreach (ReosWorkbookReader::rows($path, self::SHEET, self::HEADERS, 'Rent Roll') as $rowNumber => $c) {
            if ($rowNumber < ReosWorkbookReader::FIRST_DATA_ROW) {
                continue;
            }
            $t = fn (int $i): ?string => ($v = trim((string) ($c[$i] ?? ''))) === '' ? null : $v;
            if ($t(0) === null && $t(3) === null && $t(4) === null) {
                continue;
            }
            $out[] = ['row_number' => $rowNumber, 'address' => $t(0), 'tenant' => $t(3), 'wallet' => $t(4)];
        }

        return $out;
    }
}
