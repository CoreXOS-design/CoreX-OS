<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\Prospecting\TrackedProperty;
use App\Support\Presentations\SuburbMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent backfill for market_report_comp_rows.suburb_normalised.
 *
 * Pre-fix bug (Phase A): all 6 CMA parsers bound `$suburb` from
 * market_reports.source_suburb at parse-entry and applied it to every
 * comp row they wrote. When upload didn't capture source_suburb, every
 * row landed with NULL suburb → comp pool filters dropped everything →
 * "0 comparable sales found" on every presentation. Tier 1 of the fix
 * (parsers + job) handles all future imports. This command recovers
 * the historic rows.
 *
 * Resolution priority per row where suburb_normalised IS NULL/empty:
 *   1. parent report.source_suburb        — user-supplied or backfilled by Tier 1
 *   2. parent report.subject_address      — trailing comma-segment matched against p24_suburbs
 *   3. comp row's own address text        — longest p24_suburbs.name substring match
 *   4. leave NULL                         — flag as unrecoverable
 *
 * Stored values preserve source-detected case ("Uvongo Beach") run through
 * TrackedProperty::normaliseSuburb (lowercase + collapse). SuburbMatcher
 * bridges casing/suffix at match time downstream — never persists lossy.
 *
 * Idempotent: only operates on rows where suburb_normalised IS NULL/empty.
 * Soft on failure: per-row exceptions caught and logged; the run completes
 * with the resolved count.
 */
final class BackfillCompRowSuburb extends Command
{
    protected $signature = 'market-reports:backfill-suburb
                            {--agency= : Limit to a specific agency_id}
                            {--dry-run : Count only, no writes}';

    protected $description = 'Backfill market_report_comp_rows.suburb_normalised for historic rows where suburb was lost in the parser bind-once-at-entry bug.';

    public function handle(): int
    {
        $agencyId = $this->option('agency') !== null ? (int) $this->option('agency') : null;
        $dryRun   = (bool) $this->option('dry-run');

        // Load the SA suburb reference list — p24_suburbs is HFC's curated
        // South Coast list (~19 rows locally; staging similar). Names
        // sorted by token-length DESC so longest-match wins ("Uvongo Beach"
        // before "Uvongo" — avoid eager-shortest-match).
        $suburbRef = DB::table('p24_suburbs')
            ->whereNotNull('name')
            ->orderByRaw('CHAR_LENGTH(name) DESC')
            ->pluck('name')
            ->all();
        $this->info('Loaded ' . count($suburbRef) . ' suburb reference entries from p24_suburbs.');

        $query = MarketReportCompRow::query()
            ->where(function ($q) {
                $q->whereNull('suburb_normalised')->orWhere('suburb_normalised', '');
            });
        if ($agencyId !== null) {
            $query->where('agency_id', $agencyId);
        }

        $total = (clone $query)->count();
        $this->info('Found ' . $total . ' comp rows with NULL/blank suburb_normalised' . ($agencyId !== null ? " for agency {$agencyId}" : '') . '.');
        if ($total === 0) {
            return self::SUCCESS;
        }

        $resolvedFromParent     = 0;
        $resolvedFromAddress    = 0;
        $resolvedFromCompText   = 0;
        $unrecoverable          = 0;
        $unrecoverableSample    = [];

        // Cache parent report lookups to avoid N+1.
        $reportCache = [];

        $query->orderBy('id')->chunkById(500, function ($rows) use (
            &$resolvedFromParent, &$resolvedFromAddress, &$resolvedFromCompText,
            &$unrecoverable, &$unrecoverableSample, &$reportCache,
            $suburbRef, $dryRun
        ) {
            foreach ($rows as $row) {
                try {
                    $reportId = (int) $row->market_report_id;
                    if (!isset($reportCache[$reportId])) {
                        $reportCache[$reportId] = MarketReport::withTrashed()->find($reportId);
                    }
                    $report = $reportCache[$reportId];

                    $resolved = null;
                    $source   = null;

                    // 1. parent source_suburb (incl. Tier-1 backfill)
                    if ($report && !empty(trim((string) $report->source_suburb))) {
                        $resolved = trim((string) $report->source_suburb);
                        $source   = 'parent';
                    }

                    // 2. parent subject_address trailing token + p24_suburbs match
                    if ($resolved === null && $report && !empty($report->subject_address)) {
                        $candidate = $this->tailAfterLastComma($report->subject_address);
                        if ($candidate !== null) {
                            $hit = $this->refMatch($candidate, $suburbRef);
                            if ($hit !== null) {
                                $resolved = $hit;
                                $source   = 'parent_subject_address';
                            }
                        }
                    }

                    // 3. comp row's own address vs p24_suburbs longest-match
                    if ($resolved === null && !empty($row->address)) {
                        $hit = $this->longestSuburbMatchInText($row->address, $suburbRef);
                        if ($hit !== null) {
                            $resolved = $hit;
                            $source   = 'comp_address';
                        }
                    }

                    if ($resolved === null) {
                        $unrecoverable++;
                        if (count($unrecoverableSample) < 10) {
                            $unrecoverableSample[] = [
                                'row_id'     => $row->id,
                                'report_id'  => $reportId,
                                'address'    => $row->address,
                            ];
                        }
                        continue;
                    }

                    $normalised = TrackedProperty::normaliseSuburb($resolved);
                    if ($normalised === null || $normalised === '') {
                        $unrecoverable++;
                        continue;
                    }

                    if (!$dryRun) {
                        DB::table('market_report_comp_rows')
                            ->where('id', $row->id)
                            ->update(['suburb_normalised' => $normalised]);
                    }

                    if ($source === 'parent') {
                        $resolvedFromParent++;
                    } elseif ($source === 'parent_subject_address') {
                        $resolvedFromAddress++;
                    } else {
                        $resolvedFromCompText++;
                    }
                } catch (\Throwable $e) {
                    $this->warn('Row ' . $row->id . ' failed: ' . $e->getMessage());
                    $unrecoverable++;
                }
            }
        });

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Backfill summary:');
        $this->line('  Resolved from parent.source_suburb:       ' . $resolvedFromParent);
        $this->line('  Resolved from parent.subject_address:     ' . $resolvedFromAddress);
        $this->line('  Resolved from comp.address text:          ' . $resolvedFromCompText);
        $this->line('  Unrecoverable (left NULL):                ' . $unrecoverable);
        $this->line('  Total processed:                          ' . ($resolvedFromParent + $resolvedFromAddress + $resolvedFromCompText + $unrecoverable));

        if (!empty($unrecoverableSample)) {
            $this->newLine();
            $this->warn('Sample of unrecoverable rows (first 10):');
            foreach ($unrecoverableSample as $s) {
                $this->line('  row=' . $s['row_id'] . ' report=' . $s['report_id'] . ' address=' . ($s['address'] ?? '(null)'));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Return the trimmed text after the LAST comma in a multi-part address,
     * or null when there's no comma. "MADEIRA GARDENS, 4 TUCKER AVENUE, UVONGO"
     * → "UVONGO".
     */
    private function tailAfterLastComma(string $address): ?string
    {
        $trimmed = trim($address);
        if ($trimmed === '') return null;
        $pos = strrpos($trimmed, ',');
        if ($pos === false) return null;
        $tail = trim(substr($trimmed, $pos + 1));
        return $tail !== '' ? $tail : null;
    }

    /**
     * Validate a candidate string against the suburb reference list and
     * return the SOURCE-DETECTED form when valid (or null when no match).
     *
     * Source-detected fidelity is the locked decision: if the parent
     * subject_address says "UVONGO", we persist "uvongo" — not "uvongo
     * beach" just because the reference list happens to contain a longer
     * variant. SuburbMatcher bridges them at match time downstream.
     *
     * Reference list is only consulted to verify the candidate is a
     * real SA suburb (vs free-text noise like "BEACH ROAD" or "Cadastral
     * extent").
     */
    private function refMatch(string $candidate, array $suburbRef): ?string
    {
        foreach ($suburbRef as $ref) {
            if (SuburbMatcher::matches($candidate, $ref)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Longest-name-wins substring scan against the suburb reference list.
     * "1 ADAR ROAD MARINA BEACH" containing the reference "Marina Beach"
     * returns "Marina Beach". The reference list is already sorted by
     * name length DESC so the first hit IS the longest. Case-insensitive
     * whitespace-bounded.
     */
    private function longestSuburbMatchInText(string $text, array $suburbRef): ?string
    {
        $hay = ' ' . mb_strtolower($text) . ' ';
        foreach ($suburbRef as $ref) {
            $needle = ' ' . mb_strtolower(trim($ref)) . ' ';
            if (mb_strpos($hay, $needle) !== false) {
                return $ref;
            }
        }
        return null;
    }
}
