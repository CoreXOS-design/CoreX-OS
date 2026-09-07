<?php

namespace App\Console\Commands;

use App\Models\Property;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair `properties.town` for records where it holds a value that CONFLICTS
 * with the property's own resolved P24 city — the same class of bug fixed
 * prospectively in AppliesP24Location::applyP24Location() (`town` now travels
 * with `suburb`/`city`/`province` on every save that picks a P24 suburb).
 *
 * Before that fix, picking a suburb via the property edit screen updated
 * suburb/city/province and all three P24 ids but never touched `town`, so a
 * corrected address could leave `town` pointing at the suburb's PREVIOUS
 * (possibly wrong) city while everything else was already right — the exact
 * shape of the property #21014 "Melville" investigation this command exists
 * to clean up after.
 *
 * Scope is deliberately narrow: only a `town` that is NOT NULL and disagrees
 * with the resolved city is treated as "in conflict" and touched. On QA1's
 * data, 4,849 properties simply have `town IS NULL` (never populated at all,
 * predating the deeds-capture flow that started setting it) — that is a
 * different, much larger, unrequested change (backfilling an always-empty
 * column for thousands of unrelated rows), not the reported defect (a field
 * holding a WRONG value). It is left alone here; the Intelligence tab display
 * fix already handles a null/blank town gracefully by falling back to city.
 *
 * Only touches properties with a resolved `p24_city_id` — that FK is the
 * authoritative source of truth this command corrects TOWARDS. A property
 * that was never linked to a P24 suburb (free-text address only) has no
 * authoritative value to derive `town` from, so it is left untouched rather
 * than guessed at.
 *
 * Dry-run by default. Pass --apply to persist. Safe to run more than once —
 * a second run finds nothing left to fix.
 */
class RepairPropertyTownFromP24City extends Command
{
    protected $signature = 'properties:repair-town
                            {--apply : Persist the changes (default is a dry-run report)}
                            {--dry-run : Explicitly preview only — same as omitting --apply}';

    protected $description = 'Repair properties.town where it CONFLICTS with the property\'s own resolved P24 city (p24_city_id). Does not touch a town that was never populated.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        // INNER JOIN already guarantees p24_city_id is set and resolves to a
        // real p24_cities row. `town` must be present AND disagree — a NULL
        // town is out of scope (see class docblock), never treated as a match.
        $mismatches = DB::table('properties as p')
            ->join('p24_cities as c', 'c.id', '=', 'p.p24_city_id')
            ->whereNull('p.deleted_at')
            ->whereNotNull('p.town')
            ->where('p.town', '!=', '')
            ->whereColumn('p.town', '!=', 'c.name')
            ->select('p.id', 'p.suburb', 'p.city', 'p.town as current_town', 'p.province', 'c.name as correct_town')
            ->orderBy('p.id')
            ->get();

        $this->info(($apply ? 'APPLY' : 'DRY-RUN') . ' — ' . $mismatches->count() . ' propert' . ($mismatches->count() === 1 ? 'y' : 'ies') . ' with town conflicting with their P24 city.');

        if ($mismatches->isEmpty()) {
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'suburb', 'city', 'province', 'current town', 'correct town'],
            $mismatches->map(fn ($r) => [
                $r->id,
                $r->suburb,
                $r->city,
                $r->province,
                $r->current_town ?? '(null)',
                $r->correct_town,
            ])->all()
        );

        if (!$apply) {
            $this->info('Dry-run only — no rows changed. Re-run with --apply to persist.');

            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($mismatches->chunk(500) as $chunk) {
            foreach ($chunk as $row) {
                Property::whereKey($row->id)->update(['town' => $row->correct_town]);
                $fixed++;
            }
        }

        $this->info("Repaired {$fixed} propert" . ($fixed === 1 ? 'y' : 'ies') . '.');

        return self::SUCCESS;
    }
}
