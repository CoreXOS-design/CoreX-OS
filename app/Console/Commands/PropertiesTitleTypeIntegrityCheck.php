<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TitleTypeClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Code-gate hardening (item 5, Uvonique bug, 2026-08) — the STANDING integrity
 * check for properties.title_type drift.
 *
 * Why this exists: title_type is a CACHE (PropertyObserver::saving() derives
 * it from property_type/category, but only re-derives on insert, on
 * property_type/category actually changing, or when the column is NULL). A
 * property whose title_type was stamped wrong — by an older classifier
 * revision, a raw-write import path that bypassed the observer, or any other
 * cause — stays wrong FOREVER with no self-correction. That silent,
 * permanent drift is exactly how 96% of the live properties table ended up
 * with a stored title_type disagreeing with the current classifier (an
 * apartment presentation pulling house comparables). Every prior fix
 * improved the LIVE classification logic without ever re-validating already-
 * stored values — so the bug kept "coming back" on whichever property nobody
 * had happened to re-save since the last fix.
 *
 * This command is the standing guard against that ever happening silently
 * again: run it (on a schedule, or ad hoc) to find out immediately whenever
 * stored data disagrees with the current classifier, rather than waiting for
 * an agent to notice wrong comps on a client presentation.
 *
 * Read-only by default. --fix re-derives and persists drifted rows via a raw
 * query builder update (bypassing PropertyObserver's side effects — same
 * discipline as the original 2026_06_17_150000_add_title_type_to_properties
 * migration backfill), ONLY touching title_type — never category.
 */
class PropertiesTitleTypeIntegrityCheck extends Command
{
    protected $signature = 'properties:title-type-integrity
        {--fix : Persist corrected title_type for every drifted row (title_type ONLY, never category)}
        {--sample=25 : How many drifted rows to print}';

    protected $description = 'Read-only by default: report properties.title_type drift against the live classifier. --fix corrects it.';

    public function handle(): int
    {
        $classifier = app(TitleTypeClassifier::class);
        $sampleCap  = max(0, (int) $this->option('sample'));

        $rows = DB::table('properties')
            ->whereNull('deleted_at')
            ->select(['id', 'agency_id', 'property_type', 'category', 'title_type'])
            ->orderBy('id')
            ->get();

        $drifted = [];
        foreach ($rows as $r) {
            $correct = $classifier->fromPropertyType($r->property_type)
                ?? ($r->agency_id ? $classifier->fromCategory((int) $r->agency_id, $r->category) : null);
            if ($correct !== null && $correct !== $r->title_type) {
                $drifted[] = ['id' => $r->id, 'agency_id' => $r->agency_id, 'property_type' => $r->property_type, 'old' => $r->title_type, 'new' => $correct];
            }
        }

        $this->info('Scanned ' . $rows->count() . ' properties (not deleted).');
        $this->info('Drifted (stored title_type != freshly-classified): ' . count($drifted));

        if (count($drifted) > 0) {
            $tally = [];
            foreach ($drifted as $d) {
                $key = ($d['old'] ?? 'NULL') . ' -> ' . $d['new'];
                $tally[$key] = ($tally[$key] ?? 0) + 1;
            }
            arsort($tally);
            foreach ($tally as $k => $c) {
                $this->line("  {$k}: {$c}");
            }
            foreach (array_slice($drifted, 0, $sampleCap) as $d) {
                $this->line("    id={$d['id']} agency={$d['agency_id']} type='{$d['property_type']}' {$d['old']} -> {$d['new']}");
            }
        }

        if (!$this->option('fix')) {
            if (count($drifted) > 0) {
                $this->warn('Read-only report — re-run with --fix to persist these corrections (title_type ONLY).');
            }
            return count($drifted) > 0 ? self::FAILURE : self::SUCCESS;
        }

        if (count($drifted) === 0) {
            $this->info('Nothing to fix.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($drifted) {
            foreach ($drifted as $d) {
                DB::table('properties')->where('id', $d['id'])->update(['title_type' => $d['new']]);
            }
        });
        $this->info('Applied ' . count($drifted) . ' corrections.');

        return self::SUCCESS;
    }
}
