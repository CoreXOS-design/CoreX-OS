<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DocumentFiling;
use App\Models\FilingLinkReview;
use App\Models\Property;
use App\Services\Filing\FilingPropertyLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AT-238 — match the historical filing register against the property records.
 *
 * REPORT-ONLY BY DEFAULT. It writes nothing unless you pass --apply, because attaching a
 * legal filing to the wrong house is worse than leaving it as text. Johan sees the proposed
 * links before anything is written (Johan's ruling, 2026-07-13).
 *
 * Three honest buckets:
 *   exactly-one match  → linked (link_source=auto_address_match, confidence=exact)
 *   several matches    → queued for a human to choose. Never auto-picked.
 *   no match           → left as free text, with no shame. On qa1 that is ~42% of the
 *                        register — 2020-era files that predate the property records, plus
 *                        real typos ("3 Forset Walk"). They are still valid filings.
 *
 * Idempotent: rows that already carry a property_id are skipped, so re-running never
 * re-links or overwrites a human's decision.
 */
class FilingLinkProperties extends Command
{
    protected $signature = 'filing:link-properties
                            {--apply : Actually write the links + review queue (default is report-only)}
                            {--agency= : Restrict to one agency_id}
                            {--limit=0 : Only process the first N rows (0 = all)}';

    protected $description = 'AT-238 — match filing-register rows to properties. Reports by default; --apply writes.';

    public function handle(FilingPropertyLinker $linker): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $query = DocumentFiling::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNull('property_id')          // idempotent: never touch an already-linked row
            ->whereNotNull('property_address')
            ->where('property_address', '!=', '')
            ->orderBy('id');

        if ($agency = $this->option('agency')) {
            $query->where('agency_id', (int) $agency);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get();

        $this->info(($apply ? 'APPLYING' : 'REPORT ONLY (nothing will be written — pass --apply to write)'));
        $this->line("Unlinked filing rows to consider: {$rows->count()}");
        $this->newLine();

        $matched = [];
        $ambiguous = [];
        $unmatched = 0;

        foreach ($rows as $filing) {
            $result = $linker->match($filing);

            if ($result['status'] === 'matched') {
                $matched[] = [$filing, $result['property']];
            } elseif ($result['status'] === 'ambiguous') {
                $ambiguous[] = [$filing, $result['candidates']];
            } else {
                $unmatched++;
            }
        }

        // ── the report ──
        $this->line('<options=bold>EXACTLY ONE MATCH — safe to link automatically</>');
        foreach (array_slice($matched, 0, 15) as [$filing, $property]) {
            $this->line(sprintf('  #%-6s "%s"  →  property %s  "%s"',
                $filing->id, $this->trim($filing->property_address),
                $property->id, $this->trim($property->buildDisplayAddress())));
        }
        if (count($matched) > 15) {
            $this->line('  … and ' . (count($matched) - 15) . ' more');
        }
        $this->newLine();

        $this->line('<options=bold>SEVERAL MATCHES — a human must choose (queued, never guessed)</>');
        foreach (array_slice($ambiguous, 0, 10) as [$filing, $candidates]) {
            $this->line(sprintf('  #%-6s "%s"  →  %d candidates: %s',
                $filing->id, $this->trim($filing->property_address), $candidates->count(),
                $candidates->take(3)->map(fn ($p) => '#' . $p->id)->implode(', ')
                . ($candidates->count() > 3 ? ', …' : '')));
        }
        if (count($ambiguous) > 10) {
            $this->line('  … and ' . (count($ambiguous) - 10) . ' more');
        }
        $this->newLine();

        $total = max(1, $rows->count());
        $this->table(
            ['Outcome', 'Rows', '%'],
            [
                ['Linked (exactly one match)', count($matched),   round(count($matched) / $total * 100, 1) . '%'],
                ['Queued for review (several)', count($ambiguous), round(count($ambiguous) / $total * 100, 1) . '%'],
                ['Left as free text (no match)', $unmatched,       round($unmatched / $total * 100, 1) . '%'],
            ]
        );

        if (! $apply) {
            $this->newLine();
            $this->warn('Nothing was written. Re-run with --apply to write these links and queue the ambiguous ones.');

            return self::SUCCESS;
        }

        // ── the write ──
        DB::transaction(function () use ($matched, $ambiguous) {
            foreach ($matched as [$filing, $property]) {
                /** @var DocumentFiling $filing */
                /** @var Property $property */
                $filing->forceFill([
                    'property_id'     => $property->id,
                    'link_source'     => 'auto_address_match',
                    'link_confidence' => 'exact',
                ])->saveQuietly();
            }

            foreach ($ambiguous as [$filing, $candidates]) {
                FilingLinkReview::withoutGlobalScopes()->updateOrCreate(
                    ['filing_id' => $filing->id],
                    [
                        'agency_id'       => $filing->agency_id,
                        'matched_at'      => now(),
                        'match_status'    => 'pending',
                        'matched_address' => (string) $filing->property_address,
                        'candidates_json' => $candidates->map(fn ($p) => [
                            'id'      => $p->id,
                            'address' => $p->buildDisplayAddress(),
                            'status'  => $p->status,
                        ])->values()->all(),
                    ]
                );
            }
        });

        $this->info(sprintf('Written: %d linked, %d queued for review. %d left as free text.',
            count($matched), count($ambiguous), $unmatched));

        return self::SUCCESS;
    }

    private function trim(?string $s, int $len = 34): string
    {
        $s = (string) $s;

        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
    }
}
