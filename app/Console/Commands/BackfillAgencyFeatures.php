<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\AgencyFeature;
use Illuminate\Console\Command;

/**
 * Backfill per-agency feature overrides so a deploy HIDES NOTHING.
 *
 * Spec: .ai/specs/corex-feature-registry.md §4.2 / BUILD_STANDARD §8.
 *
 * Before this registry, every module was visible to an agency iff a role held its
 * permission — there was no feature gate. Once phases 3-4 add feature-gating,
 * "no row => registry default" would HIDE any module whose default is OFF, even
 * from an agency that was using it. To preserve current behaviour exactly, this
 * command writes an explicit `enabled = true` row for every non-core feature whose
 * registry DEFAULT is OFF, for every existing LIVE agency — so nothing a
 * pre-registry agency could reach disappears. New agencies get no rows and so get
 * the curated defaults.
 *
 * It NEVER turns a feature off. Idempotent (updateOrCreate). Demo agencies skipped.
 *
 * The six switchboard-origin capability toggles (marketing, syndication-p24,
 * syndication-pp, core-matches, multi-branch, public-website) are SKIPPED — their
 * real state lives in their existing stores (PerformanceSetting keys / agencies
 * columns) and Phase 2 wires the live adapter; writing agency_features rows for
 * them here would fight that store.
 *
 * Runs on deploy via the backfill migration; safe to re-run.
 */
class BackfillAgencyFeatures extends Command
{
    protected $signature = 'agency:backfill-features {--dry-run : Show what would change without writing}';

    protected $description = 'Set existing live agencies\' default-OFF features ON so deploy hides nothing';

    /** Switchboard-origin keys — owned by their existing store + the Phase 2 adapter. */
    private const SWITCHBOARD_KEYS = [
        'marketing', 'syndication-p24', 'syndication-pp',
        'core-matches', 'multi-branch', 'public-website',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $registry = config('corex-features', []);

        // Non-core, non-switchboard features whose default is OFF — these are the
        // ones that would otherwise be hidden from an existing agency.
        $keysToEnable = [];
        foreach ($registry as $key => $def) {
            if (!empty($def['core'])) {
                continue;
            }
            if (in_array($key, self::SWITCHBOARD_KEYS, true)) {
                continue;
            }
            if (empty($def['default'])) { // default OFF
                $keysToEnable[] = $key;
            }
        }

        $agencies = Agency::query()
            ->where(fn ($q) => $q->where('is_demo', false)->orWhereNull('is_demo'))
            ->orderBy('id')
            ->get();

        $written = 0;
        $skipped = 0;

        foreach ($agencies as $agency) {
            foreach ($keysToEnable as $key) {
                $existing = AgencyFeature::query()
                    ->where('agency_id', $agency->id)
                    ->where('feature_key', $key)
                    ->first();

                // Never override an explicit choice already on record (idempotent
                // + respects any hand-set OFF the agency later made).
                if ($existing) {
                    $skipped++;
                    continue;
                }

                if ($dry) {
                    $this->line("would enable [{$key}] for agency {$agency->id} ({$agency->name})");
                    $written++;
                    continue;
                }

                AgencyFeature::create([
                    'agency_id'   => $agency->id,
                    'feature_key' => $key,
                    'enabled'     => true,
                ]);
                $written++;
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')
            . "Backfill complete: {$written} row(s) " . ($dry ? 'to write' : 'written')
            . ", {$skipped} already present, across {$agencies->count()} live agency(ies).");

        return self::SUCCESS;
    }
}
