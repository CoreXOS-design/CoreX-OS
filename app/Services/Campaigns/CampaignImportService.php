<?php

namespace App\Services\Campaigns;

use App\Models\Campaigns\Campaign;
use App\Models\Campaigns\CampaignLead;
use App\Models\Campaigns\CampaignList;
use App\Models\Campaigns\CampaignSuppression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * AT-534 — CSV/XLSX lead import with on-screen column mapping (.ai/specs/campaigns.md §5). Streaming read copied from
 * RentalTakeOnRowParser / ContactImportController::streamRows(). Rows are validated in full first; the write is one transaction
 * so any exception leaves nothing behind (§5.3). Rejected rows are reported, never thrown.
 */
class CampaignImportService
{
    /** Where uploaded files wait (local disk) between the upload and the import finishing. */
    public const IMPORT_DIR = 'campaign-imports';

    /** field => [label, column length, header aliases] */
    public const FIELDS = [
        'first_name' => ['First name', 150, ['first name', 'firstname', 'first', 'name', 'given name']],
        'last_name' => ['Last name', 150, ['last name', 'lastname', 'surname', 'family name']],
        'company' => ['Company', 150, ['company', 'agency', 'company name', 'organisation', 'organization', 'business']],
        'town' => ['Town', 150, ['town', 'city', 'suburb', 'area', 'location']],
        'email' => ['Email', 254, ['email', 'e-mail', 'email address', 'e-mail address', 'mail']],
        'phone' => ['Phone', 40, ['phone', 'cell', 'mobile', 'telephone', 'tel', 'cell number', 'phone number', 'contact number']],
        'notes' => ['Notes', 65535, ['notes', 'note', 'comments', 'comment', 'info']],
    ];

    /** @return list<string> the file's header row, trimmed (BOM removed) */
    public function readHeaders(string $path, string $extension): array
    {
        foreach ($this->streamRows($path, $extension) as $cells) {
            $headers = array_map(fn ($c) => trim($this->cellToString($c)), $cells);
            if ($headers !== []) {
                $headers[0] = ltrim($headers[0], "\u{FEFF}");
            }

            return $headers;
        }

        return [];
    }

    /** field => column index suggested from the header names. @return array<string, int> */
    public function suggestMapping(array $headers): array
    {
        $norm = fn (string $s) => trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)));
        $mapping = [];
        foreach (self::FIELDS as $field => [, , $aliases]) {
            $aliasNorm = array_map($norm, $aliases);
            foreach ($headers as $i => $header) {
                if (in_array($norm($header), $aliasNorm, true) && ! in_array($i, $mapping, true)) {
                    $mapping[$field] = $i;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Import $path into $list using $mapping (field => column index).
     *
     * @return array{imported:int, suppressed:int, skipped:int, rejected:int, truncated:int, rows:list<array{row:int, email:string, outcome:string, reason:string}>}
     */
    public function import(CampaignList $list, string $path, string $extension, array $mapping, int $userId): array
    {
        if (! isset($mapping['email'])) {
            throw new CampaignImportException('Choose which column holds the email address.');
        }

        // Pass 1 — read and validate every row; nothing is written yet.
        $candidates = [];
        $report = [];
        $seen = [];
        $truncated = 0;
        $rowNumber = 0;
        $maxRows = (int) config('campaigns.max_import_rows');
        $dataRows = 0;

        foreach ($this->streamRows($path, $extension) as $cells) {
            $rowNumber++;
            if ($rowNumber === 1) {
                continue; // header row
            }
            if ($this->isBlank($cells)) {
                continue;
            }
            $dataRows++;
            if ($dataRows > $maxRows) {
                throw new CampaignImportException('This file has more than ' . number_format($maxRows) . ' rows, the most one import can take. Split it into smaller files.');
            }

            $values = [];
            foreach (self::FIELDS as $field => [, $length]) {
                $raw = isset($mapping[$field]) ? $this->stripFormulaGuard(trim($this->cellToString($cells[$mapping[$field]] ?? null))) : '';
                $raw = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $raw));
                if (mb_strlen($raw) > $length) {
                    $raw = mb_substr($raw, 0, $length);
                    $truncated++;
                }
                $values[$field] = $raw;
            }
            $values['email'] = CampaignLead::normaliseEmail($values['email']);

            if ($values['email'] === '') {
                $report[] = ['row' => $rowNumber, 'email' => '', 'outcome' => 'rejected', 'reason' => 'Missing email'];
                continue;
            }
            if (filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
                $report[] = ['row' => $rowNumber, 'email' => $values['email'], 'outcome' => 'rejected', 'reason' => 'Not a valid email address'];
                continue;
            }
            if (isset($seen[$values['email']])) {
                $report[] = ['row' => $rowNumber, 'email' => $values['email'], 'outcome' => 'skipped', 'reason' => 'Duplicate of row ' . $seen[$values['email']]];
                continue;
            }
            $seen[$values['email']] = $rowNumber;
            $candidates[] = ['row' => $rowNumber, 'values' => $values];
        }

        if ($dataRows === 0) {
            throw new CampaignImportException('This file has no rows to import.');
        }

        // Existing leads (any list) and suppressed addresses, looked up in chunks.
        $emails = array_keys($seen);
        $existing = [];
        $suppressed = [];
        foreach (array_chunk($emails, 1000) as $chunk) {
            CampaignLead::with('list:id,name')->whereIn('email', $chunk)->get(['id', 'email', 'campaign_list_id'])
                ->each(function ($lead) use (&$existing) {
                    $existing[$lead->email] = $lead->list?->name ?? 'another list';
                });
            foreach (CampaignSuppression::whereIn('email', $chunk)->pluck('email') as $e) {
                $suppressed[$e] = true;
            }
        }

        $campaign = Campaign::where('campaign_list_id', $list->id)->first();
        $newStatus = $campaign && in_array($campaign->status, [Campaign::STATUS_RUNNING, Campaign::STATUS_PAUSED], true)
            ? CampaignLead::STATUS_QUEUED : CampaignLead::STATUS_NEW;

        $counts = ['imported' => 0, 'suppressed' => 0, 'skipped' => 0, 'rejected' => 0, 'truncated' => $truncated];

        // Pass 2 — one transaction: any exception rolls back every row of this import (§5.3).
        DB::transaction(function () use ($candidates, $existing, $suppressed, $newStatus, $list, $userId, &$report, &$counts) {
            foreach ($candidates as $c) {
                $v = $c['values'];
                if (isset($existing[$v['email']])) {
                    $report[] = ['row' => $c['row'], 'email' => $v['email'], 'outcome' => 'skipped', 'reason' => 'Already on list ' . $existing[$v['email']]];
                    continue;
                }
                $isSuppressed = isset($suppressed[$v['email']]);
                CampaignLead::create([
                    'campaign_list_id' => $list->id,
                    'first_name' => $v['first_name'],
                    'last_name' => $v['last_name'],
                    'company' => $v['company'],
                    'town' => $v['town'],
                    'email' => $v['email'],
                    'phone' => $v['phone'],
                    'notes' => $v['notes'] !== '' ? $v['notes'] : null,
                    'status' => $isSuppressed ? CampaignLead::STATUS_SUPPRESSED : $newStatus,
                    'status_reason' => $isSuppressed ? 'suppressed' : null,
                    'status_changed_at' => now(),
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]);
                if ($isSuppressed) {
                    $counts['suppressed']++;
                    $report[] = ['row' => $c['row'], 'email' => $v['email'], 'outcome' => 'suppressed', 'reason' => 'On the suppressed list — imported but will not be emailed'];
                } else {
                    $counts['imported']++;
                }
            }
            $list->forceFill(['last_imported_at' => now(), 'updated_by' => $userId])->save();
        });

        foreach ($report as $r) {
            if ($r['outcome'] === 'skipped') {
                $counts['skipped']++;
            } elseif ($r['outcome'] === 'rejected') {
                $counts['rejected']++;
            }
        }
        usort($report, fn ($a, $b) => $a['row'] <=> $b['row']);

        return $counts + ['rows' => $report];
    }

    private function streamRows(string $path, string $extension): \Generator
    {
        if ($extension === 'csv') {
            $options = new CsvOptions();
            $options->FIELD_DELIMITER = $this->detectDelimiter($path);
            $options->ENCODING = $this->detectEncoding($path);
            $reader = new CsvReader($options);
        } else {
            $reader = new XlsxReader();
        }

        try {
            $reader->open($path);
        } catch (\Throwable $e) {
            throw new CampaignImportException('This file could not be read. Save it again as .xlsx or .csv and retry.');
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield $row->toArray();
                }
                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    private function detectDelimiter(string $path): string
    {
        $line = (string) (fgets($h = fopen($path, 'r')) ?: '');
        fclose($h);
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts) ?: ',';
    }

    /**
     * Uploaded prospect files are personal data. The import deletes its file when it finishes, but an abandoned or failed import
     * leaves it behind — so anything older than campaigns.import_upload_keep_hours is removed. Returns how many were deleted.
     */
    public function pruneStaleUploads(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subHours(max(1, (int) config('campaigns.import_upload_keep_hours')))->getTimestamp();
        $deleted = 0;
        foreach ($disk->files(self::IMPORT_DIR) as $file) {
            if ($disk->lastModified($file) < $cutoff && $disk->delete($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function detectEncoding(string $path): string
    {
        $sample = (string) file_get_contents($path, false, null, 0, 65536);

        // The sample is cut at a byte boundary, which can land inside a multi-byte character (an accented name) of a perfectly good
        // UTF-8 file — so up to 3 trailing bytes are allowed to be an incomplete sequence.
        if (mb_check_encoding($sample, 'UTF-8')) {
            return 'UTF-8';
        }
        if (strlen($sample) === 65536) { // only a sample that was actually cut short can end mid-character
            for ($trim = 1; $trim <= 3; $trim++) {
                if (mb_check_encoding(substr($sample, 0, -$trim), 'UTF-8')) {
                    return 'UTF-8';
                }
            }
        }

        return 'Windows-1252';
    }

    private function cellToString(mixed $cell): string
    {
        if ($cell instanceof \DateTimeInterface) {
            return $cell->format('Y-m-d');
        }
        if (is_float($cell) && floor($cell) === $cell && abs($cell) < 1e15) {
            return (string) (int) $cell; // a phone stored as a number must not become "27821234567.0"
        }

        return is_scalar($cell) ? (string) $cell : '';
    }

    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($this->cellToString($cell)) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripFormulaGuard(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return substr($value, 1);
        }

        return $value;
    }
}
