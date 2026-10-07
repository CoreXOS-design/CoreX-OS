<?php

declare(strict_types=1);

namespace App\Console\Commands\Address;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Services\Address\AddressStructurer;
use App\Services\Address\LpiCode;
use App\Services\Address\SuburbResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structured address matching, step 7 (.ai/specs/structured-address-matching.md §5, §8).
 *
 *   php artisan address:backfill-structured --dry-run     # ALWAYS first: counts only, nothing written
 *   php artisan address:backfill-structured                # then the real run
 *
 * Parses every properties / tracked_properties / tracked_property_addresses row from the text the row
 * ALREADY holds and fills the structured layer:
 *   - DERIVED columns (street_core, street_type, township, lpi_code, address_parse_status/note, the tracked
 *     tables' p24 suburb/city ids, address_raw once) are set;
 *   - EXISTING columns are filled ONLY WHILE EMPTY (street_number, unit_number, complex_name, scheme_number,
 *     erf_portion, erf_number, properties.p24_suburb_id). Nothing that holds a value is ever overwritten — the raw
 *     street_name ("19 Grindewald Drive", "… Cadastral Extent 1 375 M²") included;
 *   - LPI codes already on file inside tracked_property_external_refs.source_ref ("cmainfo:<lpi>") are read out;
 *   - a tracked record whose address history names SEVERAL different street addresses (the 373 pattern) is marked
 *     `review` — flagged, never split, never merged, nothing deleted;
 *   - rows an admin already settled (`manual` / `dismissed`) are skipped.
 *
 * Writes go straight to the tables (no model events, no updated_at bump): this is data repair, not an edit.
 * Chunked by id and resumable (--after). Refuses to run anywhere but QA / local / testing until Johan orders
 * otherwise — and the dry run prints exactly what the real run would do.
 */
final class BackfillStructuredAddresses extends Command
{
    protected $signature = 'address:backfill-structured
                            {--dry-run : Count what would change; write nothing}
                            {--agency= : Limit to one agency_id}
                            {--model=all : properties | tracked | history | all}
                            {--chunk=500 : Rows per batch}
                            {--after=0 : Resume after this id (per model)}
                            {--limit=0 : Stop after this many rows per model (0 = no limit)}
                            {--report= : Also write the summary as JSON to this path}';

    protected $description = 'Fill the structured address layer (street core/type, P24 suburb, LPI, parse status) from text already held. Fills only empty columns; overwrites nothing. QA/local only.';

    private const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'qa'];

    /** @var array<string, array<string, int>> */
    private array $stats = [];

    /** @var array<int, array<string, mixed>> */
    private array $reviewSample = [];

    public function handle(AddressStructurer $structurer): int
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->error('This backfill only runs on QA / local / testing until Johan orders it elsewhere (APP_ENV is "' . app()->environment() . '").');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $agency = $this->option('agency') !== null && $this->option('agency') !== '' ? (int) $this->option('agency') : null;
        $which = (string) $this->option('model');
        if (! in_array($which, ['all', 'properties', 'tracked', 'history'], true)) {
            $this->error('--model must be properties, tracked, history or all.');

            return self::FAILURE;
        }
        $chunk = max(50, (int) $this->option('chunk'));
        $after = max(0, (int) $this->option('after'));
        $limit = max(0, (int) $this->option('limit'));

        SuburbResolver::memoise(true);
        try {
            $this->line(($dry ? '[DRY RUN — nothing is written] ' : '') . 'Structured address backfill on ' . config('app.url'));
            if ($which === 'all' || $which === 'properties') {
                $this->runModel('properties', Property::withoutGlobalScopes(), $structurer, $dry, $agency, $chunk, $after, $limit);
            }
            if ($which === 'all' || $which === 'tracked') {
                $this->runModel('tracked_properties', TrackedProperty::queryWithoutAgencyScope(), $structurer, $dry, $agency, $chunk, $after, $limit);
                $this->flagSeveralAddresses($dry, $agency);
            }
            if ($which === 'all' || $which === 'history') {
                $this->runModel('tracked_property_addresses', TrackedPropertyAddress::withoutGlobalScopes(), $structurer, $dry, $agency, $chunk, $after, $limit);
            }
        } finally {
            SuburbResolver::memoise(false);
        }

        $this->report($dry);

        return self::SUCCESS;
    }

    // ───────────────────────────────────────────────────────────────────────────────────────

    /** @param \Illuminate\Database\Eloquent\Builder $query */
    private function runModel(string $table, $query, AddressStructurer $structurer, bool $dry, ?int $agency, int $chunk, int $after, int $limit): void
    {
        $this->stats[$table] = ['scanned' => 0, 'would_change' => 0, 'unchanged' => 0, 'skipped_manual' => 0,
            'status_parsed' => 0, 'status_review' => 0, 'status_unparseable' => 0];
        $hasStatus = Schema::hasColumn($table, 'address_parse_status');

        $q = $query->whereNull($table . '.deleted_at')->when($agency !== null, fn ($w) => $w->where($table . '.agency_id', $agency));
        // Resumable by id; rows already structured (a status, or for the history table a street_core) are not redone.
        if ($hasStatus) {
            $q->whereNull($table . '.address_parse_status');
        } else {
            $q->where(fn ($w) => $w->whereNull($table . '.street_core')->orWhereNull($table . '.p24_suburb_id'));
        }
        $q->where($table . '.id', '>', $after);

        $seen = 0;
        $q->chunkById($chunk, function ($rows) use ($table, $structurer, $dry, $hasStatus, $limit, &$seen) {
            $lpiByTracked = $table === 'tracked_properties' ? $this->lpiFromRefs($rows->pluck('id')->all()) : [];
            foreach ($rows as $m) {
                if ($limit > 0 && $seen >= $limit) {
                    return false;
                }
                $seen++;
                $this->stats[$table]['scanned']++;

                if ($hasStatus && in_array($m->getAttribute('address_parse_status'), ['manual', 'dismissed'], true)) {
                    $this->stats[$table]['skipped_manual']++;
                    continue;
                }
                $refLpi = null;
                if ($table === 'tracked_properties' && empty($m->lpi_code) && isset($lpiByTracked[$m->id])) {
                    $refLpi = $lpiByTracked[$m->id];
                    $m->setAttribute('lpi_code', $refLpi); // in memory so the parse reads erf / portion / township from it
                }

                $updates = $structurer->updatesFor($m);
                if ($refLpi !== null) {
                    $updates['lpi_code'] = $refLpi; // the column itself is filled (it was empty)
                }
                $status = $updates['address_parse_status'] ?? null;
                if ($status !== null) {
                    $this->stats[$table]['status_' . $status] = ($this->stats[$table]['status_' . $status] ?? 0) + 1;
                    if ($status !== 'parsed' && count($this->reviewSample) < 12) {
                        $this->reviewSample[] = ['table' => $table, 'id' => $m->getKey(), 'status' => $status, 'note' => $updates['address_parse_note'] ?? '',
                            'street' => trim((string) $m->getAttribute('street_number') . ' ' . (string) $m->getAttribute('street_name')), 'suburb' => $m->getAttribute('suburb')];
                    }
                }
                foreach (array_keys($updates) as $col) {
                    $this->stats[$table]['fills_' . $col] = ($this->stats[$table]['fills_' . $col] ?? 0) + 1;
                }

                // only what actually differs from what is stored
                $changed = array_filter($updates, fn ($v, $col) => (string) ($m->getRawOriginal($col) ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);
                if ($changed === []) {
                    $this->stats[$table]['unchanged']++;
                    continue;
                }
                $this->stats[$table]['would_change']++;
                if (! $dry) {
                    DB::table($table)->where('id', $m->getKey())->update($changed);
                }
            }

            return true;
        });
    }

    /**
     * LPI codes already held in external refs: "cmainfo:n0et0363…" is the CMA capture's source_ref.
     *
     * @param  array<int, int>  $trackedIds
     * @return array<int, string>  tracked_property_id => lpi code
     */
    private function lpiFromRefs(array $trackedIds): array
    {
        if ($trackedIds === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('tracked_property_external_refs')
            ->whereIn('tracked_property_id', $trackedIds)
            ->whereNull('deleted_at')
            ->where('source_ref', 'like', 'cmainfo:%')
            ->orderBy('id')
            ->get(['tracked_property_id', 'source_ref']);
        foreach ($rows as $r) {
            $lpi = LpiCode::parse((string) $r->source_ref);
            if ($lpi !== null) {
                $out[(int) $r->tracked_property_id] ??= $lpi['code'];
            }
        }

        return $out;
    }

    /**
     * The 373 pattern: ONE tracked record whose address history names several different street addresses.
     * Flagged `review` (never split or merged). Skips rows an admin settled. Runs after the per-row pass so a
     * row already `review` for another reason keeps both reasons in its note.
     */
    private function flagSeveralAddresses(bool $dry, ?int $agency): void
    {
        $q = DB::table('tracked_property_addresses as a')
            ->join('tracked_properties as t', 't.id', '=', 'a.tracked_property_id')
            ->whereNull('a.deleted_at')->whereNull('t.deleted_at')
            ->whereNotNull('a.street_name')
            ->when($agency !== null, fn ($w) => $w->where('t.agency_id', $agency))
            ->select('a.tracked_property_id', 'a.street_number', 'a.street_name', 'a.street_core');
        $parser = new \App\Services\Address\AddressParser();
        $distinct = [];
        foreach ($q->orderBy('a.id')->cursor() as $r) {
            [$num, $core] = $parser->numberAndCore($r->street_number, $r->street_name, $r->street_core);
            if ($core === null) {
                continue;
            }
            $distinct[(int) $r->tracked_property_id][($num ?? '') . '|' . $core] = true;
        }
        $flagged = 0;
        foreach ($distinct as $trackedId => $addresses) {
            // different streets, or different numbers on one street — a single property has ONE address
            if (count($addresses) < 2) {
                continue;
            }
            $row = DB::table('tracked_properties')->where('id', $trackedId)->first(['id', 'address_parse_status', 'address_parse_note']);
            if ($row === null || in_array($row->address_parse_status, ['manual', 'dismissed'], true)) {
                continue;
            }
            $flagged++;
            $note = trim(($row->address_parse_note ? $row->address_parse_note . ' ' : '') . 'The address history names ' . count($addresses) . ' different addresses — this record may be several properties merged.');
            if (! $dry) {
                DB::table('tracked_properties')->where('id', $trackedId)->update([
                    'address_parse_status' => 'review',
                    'address_parse_note'   => mb_substr($note, 0, 255),
                ]);
            }
        }
        $this->stats['tracked_properties']['flagged_several_addresses'] = $flagged;
    }

    private function report(bool $dry): void
    {
        $this->newLine();
        foreach ($this->stats as $table => $s) {
            $this->info(($dry ? '[dry run] ' : '') . $table);
            $rows = [];
            foreach ($s as $k => $v) {
                $rows[] = [$k, $v];
            }
            $this->table(['count', 'rows'], $rows);
        }
        if ($this->reviewSample !== []) {
            $this->line('Sample of rows that need a look:');
            $this->table(['table', 'id', 'status', 'street', 'suburb', 'note'], array_map(fn ($r) => [$r['table'], $r['id'], $r['status'], $r['street'], $r['suburb'], mb_substr((string) $r['note'], 0, 70)], $this->reviewSample));
        }
        if ($this->option('report')) {
            file_put_contents((string) $this->option('report'), json_encode(['dry_run' => $dry, 'stats' => $this->stats, 'sample' => $this->reviewSample], JSON_PRETTY_PRINT));
            $this->line('Summary written to ' . $this->option('report'));
        }
    }
}
