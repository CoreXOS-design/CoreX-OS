<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Suburbs;

use App\Services\Address\SuburbResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MIC step 5.1 (.ai/specs/mic-work-tab.md section 8): fill `p24_suburb_id` (OUR p24_suburbs.id) on the MIC tables from the suburb TEXT
 * they already hold, using the existing SuburbResolver (exact / normalised / spelling-twin / alias). Nothing is guessed: text the
 * resolver cannot place stays NULL and is reported. "Uvongo" and "Uvongo Beach" are two real P24 suburbs and each text maps only to
 * its own id.
 *
 * Works per DISTINCT (agency, text, province): one resolution, then every still-empty row with that text is filled. Only NULL
 * columns are ever filled (a value that is already there is never changed), every filled row is journalled, and a run can be
 * rolled back by run id. Dry run (default) writes nothing.
 */
final class SuburbIdBackfill
{
    /** table => [text column, province column|null, lat column|null, lng column|null, has agency_id] */
    public const TARGETS = [
        'properties'             => ['text' => 'suburb',            'province' => null,       'lat' => null, 'lng' => null],
        'tracked_properties'     => ['text' => 'suburb',            'province' => 'province', 'lat' => null, 'lng' => null],
        'prospecting_listings'   => ['text' => 'suburb',            'province' => null,       'lat' => null, 'lng' => null],
        'market_report_comp_rows' => ['text' => 'suburb_normalised', 'province' => null,       'lat' => null, 'lng' => null],
    ];

    private const COLUMN = 'p24_suburb_id';

    /**
     * @param  list<string>  $tables  keys of TARGETS
     * @return array{run_id: string, apply: bool, tables: array<string, array<string, mixed>>}
     */
    public function run(array $tables, ?int $agencyId, bool $apply): array
    {
        $runId = (string) Str::uuid();
        $report = ['run_id' => $runId, 'apply' => $apply, 'tables' => []];
        $memo = [];

        foreach ($tables as $table) {
            $cfg = self::TARGETS[$table];
            $report['tables'][$table] = $this->runTable($table, $cfg, $agencyId, $apply, $runId, $memo);
        }

        return $report;
    }

    /** @return array<string, mixed> */
    private function runTable(string $table, array $cfg, ?int $agencyId, bool $apply, string $runId, array &$memo): array
    {
        $text = $cfg['text'];
        $prov = $cfg['province'];

        $base = DB::table($table)->whereNull(self::COLUMN)->whereNotNull($text)->where($text, '!=', '');
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at')) {
            $base->whereNull('deleted_at');
        }
        if ($agencyId !== null) {
            $base->where('agency_id', $agencyId);
        }

        $groups = (clone $base)
            ->selectRaw('agency_id, ' . $text . ' as t' . ($prov ? ', ' . $prov . ' as p' : ', NULL as p') . ', COUNT(*) as n')
            ->groupBy('agency_id', $text)
            ->when($prov, fn ($q) => $q->groupBy($prov))
            ->get();

        $before = [
            'null_rows'  => (int) $groups->sum('n'),
            'distinct_texts' => $groups->count(),
        ];
        $filled = 0;
        $unresolved = [];
        $byMethod = [];
        $byTarget = [];

        foreach ($groups as $g) {
            $key = mb_strtolower((string) $g->t) . '|' . mb_strtolower((string) $g->p);
            $memo[$key] ??= SuburbResolver::resolve((string) $g->t, $g->p !== null ? (string) $g->p : null);
            $hit = $memo[$key];

            if ($hit['p24_suburb_id'] === null) {
                $unresolved[] = ['agency_id' => (int) $g->agency_id, 'text' => (string) $g->t, 'rows' => (int) $g->n];
                continue;
            }

            $byMethod[$hit['method']] = ($byMethod[$hit['method']] ?? 0) + (int) $g->n;
            $label = $hit['name'] . ' (#' . $hit['p24_suburb_id'] . ')';
            $byTarget[$label] = ($byTarget[$label] ?? 0) + (int) $g->n;
            $filled += (int) $g->n;

            if ($apply) {
                $this->fillGroup($table, $text, $prov, $g, (int) $hit['p24_suburb_id'], $runId);
            }
        }

        usort($unresolved, fn ($a, $b) => $b['rows'] <=> $a['rows']);
        arsort($byTarget);

        $after = null;
        if ($apply) {
            $afterQ = DB::table($table)->whereNull(self::COLUMN)->whereNotNull($text)->where($text, '!=', '');
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at')) {
                $afterQ->whereNull('deleted_at');
            }
            if ($agencyId !== null) {
                $afterQ->where('agency_id', $agencyId);
            }
            $after = ['null_rows' => $afterQ->count()];
        }

        return [
            'before'            => $before,
            'would_fill'        => $filled,
            'unresolved_rows'   => array_sum(array_column($unresolved, 'rows')),
            'unresolved_texts'  => count($unresolved),
            'unresolved_top'    => array_slice($unresolved, 0, 15),
            'by_method'         => $byMethod,
            'top_targets'       => array_slice($byTarget, 0, 12, true),
            'after'             => $after,
        ];
    }

    private function fillGroup(string $table, string $text, ?string $prov, object $g, int $suburbId, string $runId): void
    {
        $q = DB::table($table)->whereNull(self::COLUMN)->where('agency_id', $g->agency_id)->where($text, $g->t);
        if ($prov) {
            $g->p === null ? $q->whereNull($prov) : $q->where($prov, $g->p);
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        $q->orderBy('id')->select('id')->chunkById(1000, function ($rows) use ($table, $g, $suburbId, $runId) {
            $ids = $rows->pluck('id')->all();
            DB::table('mic_data_journals')->insert(array_map(fn ($id) => [
                'run_id' => $runId, 'step' => '5.1', 'agency_id' => $g->agency_id, 'table_name' => $table, 'row_id' => $id,
                'column_name' => self::COLUMN, 'old_value' => null, 'new_value' => (string) $suburbId, 'created_at' => now(),
            ], $ids));
            DB::table($table)->whereIn('id', $ids)->whereNull(self::COLUMN)->update([self::COLUMN => $suburbId]);
        });
    }

    /** Put back every value a run filled (only where the column still holds what the run wrote). */
    public function rollback(string $runId): int
    {
        $restored = 0;
        DB::table('mic_data_journals')->where('run_id', $runId)->where('step', '5.1')->orderBy('id')
            ->chunkById(2000, function ($rows) use (&$restored) {
                foreach ($rows->groupBy('table_name') as $table => $set) {
                    foreach ($set->groupBy('new_value') as $new => $sub) {
                        $restored += DB::table($table)->whereIn('id', $sub->pluck('row_id')->all())
                            ->where(self::COLUMN, (int) $new)->update([self::COLUMN => null]);
                    }
                }
            });

        return $restored;
    }
}
