<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Comps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MIC step 5.3 (.ai/specs/mic-work-tab.md sections 7, 8, 11.1; AT-524): flag CMA duplicate sales. NEVER deletes a row.
 *
 * A "sale row" is a live market_report_comp_rows row of type comp or subject with a sale price and a sale date. Rows of one agency
 * with the same date and price form a bucket; inside a bucket CompSaleIdentity decides which rows are the same sale. Per sale ONE
 * row stays canonical (duplicate_of_id NULL, the row with the most identity data, oldest id on a tie) and each repeat gets
 * duplicate_of_id = canonical id. Incremental and idempotent: rows already checked (dup_checked_at set) keep their place, only new
 * rows are placed, so the same call serves the one-off command, the nightly pass and the save-time observer.
 * Presentation comps that are copies of a CMA row (raw_row_json.mic_comp_row_id) get copy_of_comp_row_id = that sale's canonical id.
 * Every change by a command run is journalled in mic_data_journals (step 5.3) and can be rolled back by run id.
 */
final class CompDuplicateFlagger
{
    public const STEP = '5.3';

    private const SALE_ROW_TYPES = ['comp', 'subject'];

    /**
     * Process every bucket that holds at least one unchecked sale row (optionally one agency).
     *
     * @return array<string, mixed> report
     */
    public function run(?int $agencyId, bool $apply, ?string $runId = null): array
    {
        $runId ??= (string) Str::uuid();
        $report = [
            'run_id' => $runId, 'apply' => $apply, 'healed_orphans' => 0,
            'sale_rows' => 0, 'buckets_touched' => 0, 'rows_checked' => 0, 'groups_with_repeats' => 0,
            'duplicates_flagged' => 0, 'canonical_after' => 0, 'by_row_type' => [], 'samples' => [],
            'psc_copies' => 0, 'psc_copies_to_deleted_comp' => 0,
        ];

        $report['healed_orphans'] = $this->healOrphans($agencyId, $apply, $runId);

        $report['sale_rows'] = $this->saleRows($agencyId)->count();

        $buckets = $this->saleRows($agencyId)->whereNull('dup_checked_at')
            ->select('agency_id', 'sale_date', 'sale_price')->distinct()->get();
        $report['buckets_touched'] = $buckets->count();

        foreach ($buckets as $b) {
            $r = $this->flagBucket((int) $b->agency_id, (string) $b->sale_date, (int) $b->sale_price, $apply, $runId);
            $report['rows_checked'] += $r['checked'];
            $report['duplicates_flagged'] += $r['duplicates'];
            $report['groups_with_repeats'] += $r['groups_with_repeats'];
            foreach ($r['by_row_type'] as $t => $n) {
                $report['by_row_type'][$t] = ($report['by_row_type'][$t] ?? 0) + $n;
            }
            if (count($report['samples']) < 12 && $r['samples']) {
                $report['samples'][] = $r['samples'][0];
            }
        }

        $report['canonical_after'] = $apply
            ? $this->saleRows($agencyId)->whereNull('duplicate_of_id')->count()
            : $report['sale_rows'] - $report['duplicates_flagged'];

        $psc = $this->flagPresentationCopies($agencyId, $apply, $runId);
        $report['psc_copies'] = $psc['copies'];
        $report['psc_copies_to_deleted_comp'] = $psc['to_deleted'];

        return $report;
    }

    /**
     * Place the unchecked sale rows of ONE bucket (agency + sale date + sale price). Used by run() and by the save-time observer.
     *
     * @return array{checked:int,duplicates:int,groups_with_repeats:int,by_row_type:array<string,int>,samples:list<array<string,mixed>>}
     */
    public function flagBucket(int $agencyId, string $saleDate, int $price, bool $apply = true, ?string $runId = null): array
    {
        $rows = $this->bucketRows($agencyId, $saleDate, $price);

        // clusters start from the rows already checked and canonical
        $clusters = [];
        foreach ($rows as $r) {
            if ($r['dup_checked_at'] !== null && $r['duplicate_of_id'] === null) {
                $clusters[] = ['head' => $r, 'members' => []];
            }
        }
        $headIds = array_map(fn ($c) => $c['head']['id'], $clusters);

        $new = array_values(array_filter($rows, fn ($r) => $r['dup_checked_at'] === null));
        usort($new, fn ($x, $y) => [CompSaleIdentity::completeness($y), $x['id']] <=> [CompSaleIdentity::completeness($x), $y['id']]);

        $dupOf = [];
        foreach ($new as $r) {
            $placed = false;
            foreach ($clusters as $ci => $c) {
                if (CompSaleIdentity::same($c['head'], $r)) {
                    $clusters[$ci]['members'][] = $r['id'];
                    $dupOf[$r['id']] = $c['head']['id'];
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                $clusters[] = ['head' => $r, 'members' => []];
            }
        }

        $byType = [];
        $samples = [];
        foreach ($new as $r) {
            $byType[$r['row_type']] = ($byType[$r['row_type']] ?? 0) + (isset($dupOf[$r['id']]) ? 1 : 0);
        }
        $byType = array_filter($byType);
        $repeatGroups = 0;
        foreach ($clusters as $c) {
            if ($c['members']) {
                $repeatGroups++;
                $samples[] = [
                    'sale_date' => $saleDate, 'sale_price' => $price, 'canonical' => $c['head']['id'],
                    'repeats' => $c['members'], 'scheme' => $c['head']['scheme_name'], 'section' => $c['head']['section_number'],
                    'address' => $c['head']['address'],
                ];
            }
        }

        if ($apply && $new) {
            $this->write($agencyId, $new, $dupOf, $runId);
        }

        return [
            'checked' => count($new), 'duplicates' => count($dupOf), 'groups_with_repeats' => $repeatGroups,
            'by_row_type' => $byType, 'samples' => $samples,
        ];
    }

    /**
     * A repeat whose canonical row has since been soft-deleted (or never existed) loses its sale from the canonical set. Put those
     * rows (and all repeats of that canonical) back to "unchecked" so the next placement elects a new canonical.
     */
    public function healOrphans(?int $agencyId, bool $apply, ?string $runId = null): int
    {
        $q = DB::table('market_report_comp_rows as d')
            ->whereNull('d.deleted_at')->whereNotNull('d.duplicate_of_id')
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('market_report_comp_rows as c')
                ->whereColumn('c.id', 'd.duplicate_of_id')->whereNull('c.deleted_at'));
        if ($agencyId !== null) {
            $q->where('d.agency_id', $agencyId);
        }
        $ids = $q->pluck('d.id')->all();
        if ($ids && $apply) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $this->journal($runId, $chunk, 'duplicate_of_id');
                $this->journal($runId, $chunk, 'dup_checked_at');
                DB::table('market_report_comp_rows')->whereIn('id', $chunk)->update(['duplicate_of_id' => null, 'dup_checked_at' => null]);
            }
        }

        return count($ids);
    }

    /** Presentation comps that are copies of a CMA comp row point at that sale's canonical row. */
    public function flagPresentationCopies(?int $agencyId, bool $apply, ?string $runId = null): array
    {
        $q = DB::table('presentation_sold_comps as p')
            ->join('market_report_comp_rows as c', 'c.id', '=', DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(p.raw_row_json, '$.mic_comp_row_id')) AS UNSIGNED)"))
            ->whereColumn('c.agency_id', 'p.agency_id')
            ->whereNull('p.deleted_at')->whereNull('p.copy_of_comp_row_id')
            ->whereRaw("JSON_EXTRACT(p.raw_row_json, '$.mic_comp_row_id') IS NOT NULL")
            ->select('p.id', 'p.agency_id', 'c.id as comp_id', 'c.duplicate_of_id', 'c.deleted_at as comp_deleted');
        if ($agencyId !== null) {
            $q->where('p.agency_id', $agencyId);
        }
        $rows = $q->get();

        $copies = 0;
        $toDeleted = 0;
        foreach ($rows->chunk(500) as $chunk) {
            $updates = [];
            foreach ($chunk as $r) {
                $target = $r->duplicate_of_id ?: $r->comp_id;
                $updates[$r->id] = (int) $target;
                $copies++;
                $toDeleted += $r->comp_deleted !== null ? 1 : 0;
            }
            if ($apply) {
                $this->journalRows($runId, 'presentation_sold_comps', $chunk->pluck('agency_id', 'id')->all(), 'copy_of_comp_row_id', $updates);
                foreach ($updates as $id => $target) {
                    DB::table('presentation_sold_comps')->where('id', $id)->update(['copy_of_comp_row_id' => $target]);
                }
            }
        }

        return ['copies' => $copies, 'to_deleted' => $toDeleted];
    }

    /** Put every flag of a run back (only where the column still holds what the run wrote). */
    public function rollback(string $runId): int
    {
        $restored = 0;
        DB::table('mic_data_journals')->where('run_id', $runId)->where('step', self::STEP)->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$restored) {
                foreach ($rows as $j) {
                    $old = $j->old_value === null ? null : $j->old_value;
                    $current = DB::table($j->table_name)->where('id', $j->row_id)->value($j->column_name);
                    if ((string) $current !== (string) $j->new_value) {
                        continue;
                    }
                    DB::table($j->table_name)->where('id', $j->row_id)->update([$j->column_name => $old]);
                    $restored++;
                }
            });

        return $restored;
    }

    /** A canonical row was soft-deleted: elect a new canonical for its repeats now. Safe to call for any row. */
    public function onRowRemoved(int $rowId): void
    {
        $row = DB::table('market_report_comp_rows')->where('id', $rowId)->first(['agency_id', 'sale_date', 'sale_price', 'duplicate_of_id']);
        if (! $row || $row->sale_price === null || $row->sale_date === null) {
            return;
        }
        $this->healOrphans((int) $row->agency_id, true);
        $this->flagBucket((int) $row->agency_id, (string) $row->sale_date, (int) $row->sale_price);
    }

    /** @return \Illuminate\Database\Query\Builder */
    public function saleRows(?int $agencyId = null)
    {
        $q = DB::table('market_report_comp_rows')->whereNull('deleted_at')
            ->whereIn('row_type', self::SALE_ROW_TYPES)->whereNotNull('sale_price')->where('sale_price', '>', 0)->whereNotNull('sale_date');
        if ($agencyId !== null) {
            $q->where('agency_id', $agencyId);
        }

        return $q;
    }

    /** @return list<array<string, mixed>> */
    private function bucketRows(int $agencyId, string $saleDate, int $price): array
    {
        return $this->saleRows($agencyId)->where('sale_date', $saleDate)->where('sale_price', $price)
            ->selectRaw("id, row_type, scheme_name, section_number, ss_number, address, suburb_normalised, p24_suburb_id, extent_m2, latitude, "
                . "duplicate_of_id, dup_checked_at, JSON_UNQUOTE(JSON_EXTRACT(raw_row_json, '$.erf_number')) as erf")
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    /** @param list<array<string, mixed>> $new  @param array<int,int> $dupOf */
    private function write(int $agencyId, array $new, array $dupOf, ?string $runId): void
    {
        $now = now();
        $ids = array_column($new, 'id');
        DB::transaction(function () use ($agencyId, $new, $dupOf, $runId, $now, $ids) {
            if ($runId !== null) {
                $this->journalRows($runId, 'market_report_comp_rows', array_fill_keys($ids, $agencyId), 'dup_checked_at', array_fill_keys($ids, $now->toDateTimeString()));
                if ($dupOf) {
                    $this->journalRows($runId, 'market_report_comp_rows', array_fill_keys(array_keys($dupOf), $agencyId), 'duplicate_of_id', $dupOf);
                }
            }
            DB::table('market_report_comp_rows')->whereIn('id', $ids)->update(['dup_checked_at' => $now]);
            foreach ($dupOf as $id => $canonical) {
                DB::table('market_report_comp_rows')->where('id', $id)->update(['duplicate_of_id' => $canonical]);
            }
        });
    }

    /** @param list<int> $ids  journal "old" = current value of the column */
    private function journal(?string $runId, array $ids, string $column): void
    {
        if ($runId === null) {
            return;
        }
        $rows = DB::table('market_report_comp_rows')->whereIn('id', $ids)->get(['id', 'agency_id', $column]);
        DB::table('mic_data_journals')->insert($rows->map(fn ($r) => [
            'run_id' => $runId, 'step' => self::STEP, 'agency_id' => $r->agency_id, 'table_name' => 'market_report_comp_rows',
            'row_id' => $r->id, 'column_name' => $column, 'old_value' => $r->{$column}, 'new_value' => null, 'created_at' => now(),
        ])->all());
    }

    /** @param array<int,int> $agencyById  @param array<int,mixed> $new */
    private function journalRows(?string $runId, string $table, array $agencyById, string $column, array $new): void
    {
        if ($runId === null || ! $new) {
            return;
        }
        $rows = [];
        foreach ($new as $id => $value) {
            $rows[] = [
                'run_id' => $runId, 'step' => self::STEP, 'agency_id' => $agencyById[$id] ?? null, 'table_name' => $table,
                'row_id' => $id, 'column_name' => $column, 'old_value' => null, 'new_value' => (string) $value, 'created_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $c) {
            DB::table('mic_data_journals')->insert($c);
        }
    }
}
