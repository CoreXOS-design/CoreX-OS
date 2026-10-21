<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Services\Rentals\TakeOnImport\RentalTakeOnRowParser;

/**
 * AT-479 / rental-takeon-import.md R2 — the reOS "Tenancy Schedule" report. Sheet "Tenancy Schedule",
 * 21 columns, the header row found by scanning (see ReosWorkbookReader). Yields one normalised payload per data row with the errors / warnings found reading it.
 *
 * NOT kept: commission (incl/excl VAT), fixed and variable invoices, lease segment (Johan's money-side ruling) — the
 * commission cells are read only to notice an unreadable one; none of the four ever reaches the payload.
 */
class ReosTenancyScheduleParser
{
    public const SHEET = 'Tenancy Schedule';

    public const HEADERS = [
        'Lease ID', 'Property Address', 'Owner', 'Owner Email', 'Owner Phone', 'Tenant', 'Tenant Email', 'Tenant Phone',
        'Lease Type', 'Lease Status', 'Lease Start', 'Lease End', 'Rollover', 'Deposit Required', 'Current Rent',
        'Fixed Invoices', 'Variable Invoices', 'Current Commission (including VAT)', 'Current Commission (excluding VAT)',
        'Agent', 'Lease Segment',
    ];

    /** reOS Lease Status → what the import does with the lease. */
    public const STATUS_ACTIVATE = 'activate';
    public const STATUS_DRAFT = 'draft';

    private const STATUS_MAP = [
        'active' => self::STATUS_ACTIVATE,
        'expiring' => self::STATUS_ACTIVATE,
        'draft' => self::STATUS_DRAFT,
    ];

    private RentalTakeOnRowParser $cells;

    public function __construct(?RentalTakeOnRowParser $cells = null)
    {
        $this->cells = $cells ?? new RentalTakeOnRowParser();
    }

    /** Checks the sheet and finds the header row only, so a wrong file is refused before a run exists. */
    public function assertLayout(string $path): void
    {
        ReosWorkbookReader::locate($path, self::SHEET, self::HEADERS, 'Tenancy Schedule');
    }

    /**
     * @return \Generator<int, array{row_number:int, payload:array<string,mixed>, errors:list<string>, warnings:list<string>}>
     */
    public function rows(string $path): \Generator
    {
        $seenIds = [];
        $where = ReosWorkbookReader::locate($path, self::SHEET, self::HEADERS, 'Tenancy Schedule');

        foreach (ReosWorkbookReader::dataRows($path, self::SHEET, $where, 'Tenancy Schedule') as $rowNumber => $cells) {
            if ($this->isBlank($cells)) {
                continue;
            }

            $row = $this->normalise($rowNumber, $cells);

            // The same reOS lease twice in one file would import it twice — the second is refused.
            $id = $row['payload']['external_id'];
            if ($id !== null) {
                if (isset($seenIds[$id])) {
                    $row['errors'][] = "This reOS lease appears twice in the file (also on row {$seenIds[$id]}) — only the first is imported.";
                } else {
                    $seenIds[$id] = $rowNumber;
                }
            }

            yield $row;
        }
    }

    /**
     * @param array<int,mixed> $c
     * @return array{row_number:int, payload:array<string,mixed>, errors:list<string>, warnings:list<string>}
     */
    public function normalise(int $rowNumber, array $c): array
    {
        $errors = [];
        $warnings = [];

        $text = fn (int $i): ?string => ($v = trim((string) ($c[$i] ?? ''))) === '' ? null : $v;

        // Lease ID
        $lease = ReosLeaseIdCell::parse(is_string($c[0] ?? null) ? $c[0] : (isset($c[0]) ? (string) $c[0] : null));
        if ($lease === null) {
            $errors[] = 'No readable reOS lease id in the Lease ID cell — without it this lease cannot be matched on a later re-import.';
        }

        // Status
        $statusRaw = $text(9);
        $statusAction = $statusRaw !== null ? (self::STATUS_MAP[mb_strtolower($statusRaw)] ?? null) : null;
        if ($statusAction === null) {
            $errors[] = $statusRaw === null
                ? 'Lease Status is empty (Active, Expiring or Draft expected).'
                : "Lease Status \"{$statusRaw}\" is not one CoreX imports (Active, Expiring or Draft).";
        }

        // Dates — a filled-but-unreadable date is an error, an empty end date is month-to-month.
        $start = $this->date($c[10] ?? null, 'Lease Start', true, $errors);
        $end = $this->date($c[11] ?? null, 'Lease End', false, $errors);
        if ($start !== null && $end !== null && $end <= $start) {
            $errors[] = "Lease End ({$end}) is not after Lease Start ({$start}).";
        }

        // Money
        $rent = $this->money($c[14] ?? null, 'Current Rent', $errors);
        if ($rent === null && ! $this->hasError($errors, 'Current Rent')) {
            $errors[] = 'Current Rent is missing.';
        } elseif ($rent !== null && $rent <= 0) {
            $errors[] = 'Current Rent must be more than zero.';
        }
        $deposit = $this->money($c[13] ?? null, 'Deposit Required', $errors);

        // Commission: read only to notice a garbled cell. Never stored (the four money/segment columns stay in reOS).
        foreach ([17, 18] as $i) {
            $raw = $text($i);
            if ($raw !== null && self::parseCommission($raw) === null) {
                $warnings[] = 'Commission value "' . $raw . '" is not readable — ignored (commission is not imported).';
            }
        }

        // Rollover
        $rolloverRaw = $text(12);
        $rollover = self::parseBool($rolloverRaw);
        if ($rolloverRaw !== null && $rollover === null) {
            $warnings[] = "Rollover \"{$rolloverRaw}\" is not True/False — left as not known.";
        }

        $payload = [
            'external_id' => $lease['uuid'] ?? null,
            'external_url' => $lease['url'] ?? null,
            'address' => $text(1),
            'owner_name' => $text(2),
            'owner_email' => $text(3),
            'owner_phone' => $text(4),
            'tenant_name' => $text(5),
            'tenant_email' => $text(6),
            'tenant_phone' => $text(7),
            'lease_type' => $text(8),
            'lease_status' => $statusRaw,
            'status_action' => $statusAction,
            'lease_start_date' => $start,
            'lease_end_date' => $end,
            'rollover' => $rollover,
            'deposit' => $deposit,
            'rent' => $rent,
            'agent_text' => $text(19),
            'tenant_wallet_ref' => null,
        ];

        if ($payload['address'] === null) {
            $errors[] = 'Property Address is empty.';
        }
        if ($payload['owner_name'] === null) {
            $warnings[] = 'No owner named — the lease is imported without an owner.';
        }
        if ($payload['tenant_name'] === null) {
            $warnings[] = 'No tenant named — the lease is imported without a tenant.';
        }

        return ['row_number' => $rowNumber, 'payload' => $payload, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** "759.00" → 759.0, "0.00%" → 0.0, "R 1 250,50"-style noise absorbed; anything else null. Public for the test. */
    public static function parseCommission(string $raw): ?float
    {
        $clean = trim(str_replace(['%', 'R', 'r', ' ', ','], '', $raw));

        return $clean !== '' && is_numeric($clean) ? (float) $clean : null;
    }

    public static function parseBool(?string $raw): ?bool
    {
        if ($raw === null) {
            return null;
        }

        return match (mb_strtolower(trim($raw))) {
            'true', '1', 'yes', 'y' => true,
            'false', '0', 'no', 'n' => false,
            default => null,
        };
    }

    /** @param list<string> $errors */
    private function date(mixed $raw, string $label, bool $required, array &$errors): ?string
    {
        $empty = $raw === null || (is_string($raw) && trim($raw) === '');
        if ($empty) {
            if ($required) {
                $errors[] = "{$label} is missing.";
            }

            return null;
        }
        // reOS writes YYYY-MM-DD. A text date in any other shape is refused rather than guessed: Carbon would read
        // "03/04/2025" the American way and "next week" as a date, and a wrong lease date is worse than a stopped row.
        // (A real Excel date cell arrives as a number / DateTime and is fine.)
        if (is_string($raw) && ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', trim($raw))) {
            $errors[] = "{$label} \"" . trim($raw) . '" is not a date (YYYY-MM-DD expected).';

            return null;
        }
        $date = $this->cells->parseDate(is_string($raw) ? trim($raw) : $raw);
        if ($date === null) {
            $errors[] = "{$label} \"" . (is_scalar($raw) ? $raw : '?') . '" is not a date (YYYY-MM-DD expected).';
        }

        return $date;
    }

    /** @param list<string> $errors */
    private function money(mixed $raw, string $label, array &$errors): ?float
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        $n = $this->cells->parseNumber(is_string($raw) ? trim($raw) : $raw);
        if ($n === null) {
            $errors[] = "{$label} \"" . (is_scalar($raw) ? $raw : '?') . '" is not an amount.';
        }

        return $n;
    }

    /** @param list<string> $errors */
    private function hasError(array $errors, string $needle): bool
    {
        foreach ($errors as $e) {
            if (str_contains($e, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,mixed> $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
