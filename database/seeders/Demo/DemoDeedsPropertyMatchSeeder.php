<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Demo "similar/conflicting property" scenario for the Deeds Capture screen.
 *
 * Johan, after using the screen himself: "I need... one where there are
 * similar properties so we show the flags that loads to sort out
 * conflicting properties." That flag is `$stockStatusByTp` in
 * DeedsCaptureController::index() — computed LIVE, every render, by
 * TrackedPropertyMatchOrCreateService::previewPropertyMatch(), the exact
 * same erf+suburb / scheme+section / normalised-address rules
 * promoteToStock() itself uses (read-only preview). It is NOT a stored
 * flag — there is no table row to seed for it.
 *
 * The sectional strategy (resolvePropertyMatch(), TrackedPropertyMatch
 * OrCreateService.php ~1518-1544) matches on LOWER(complex_name) +
 * unit_number (leading-zero normalised) against the `properties` table.
 * This seeder makes ONE existing deeds-capture tracked_property's
 * scheme_name/section_number genuinely equal an existing agency-1
 * property's complex_name/unit_number — a real collision, not a synthetic
 * one — so previewPropertyMatch() finds it and the screen's own detection
 * fires the "We think this is the same as..." banner + comparison panel +
 * Confirm/Reject resolution controls, exactly as it would for a real
 * scrape that happened to land on a property already on the books.
 *
 * IDEMPOTENT BY CONSTRUCTION: the target property and target tracked_property
 * are both selected via a deterministic, order-stable query; the update is
 * skipped entirely once the tracked_property's scheme_name/section_number
 * already equal the target property's complex_name/unit_number.
 */
class DemoDeedsPropertyMatchSeeder
{
    /** @return array{updated:int, note:string} */
    public function run(int $agencyId = 1): array
    {
        // Deterministic target: first ACTIVE (on-market) sectional property
        // with a unique complex_name+unit_number pair — gives the "currently
        // on the market with {agent}" live-state banner, the most compelling
        // version of this flag to demo.
        $targetProperty = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->where('status', 'active')
            ->whereNotNull('complex_name')
            ->whereNotNull('unit_number')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first(['id', 'complex_name', 'unit_number', 'address', 'suburb']);

        if (!$targetProperty) {
            return ['updated' => 0, 'note' => 'Skipped — no active sectional property with complex_name/unit_number found.'];
        }

        // Refuse a target that isn't uniquely identified by complex+unit —
        // that would make previewPropertyMatch() return null (ambiguous,
        // count()>1) instead of a confident single match. Pick the next one
        // deterministically if so.
        $dupeCount = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->where('complex_name', $targetProperty->complex_name)
            ->where('unit_number', $targetProperty->unit_number)
            ->whereNull('deleted_at')
            ->count();
        if ($dupeCount !== 1) {
            return ['updated' => 0, 'note' => "Skipped — target property #{$targetProperty->id} complex+unit is not unique ({$dupeCount} matches)."];
        }

        // 4th deeds-captured tracked_property by id — the first 3 are already
        // used by DemoTvaCapturesSeeder for the owner-linking story; keeping
        // this on a distinct row avoids cluttering one row with two stories.
        $targetTp = DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->where('capture_kind', 'deeds_capture')
            ->whereNull('promoted_to_property_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->skip(3)
            ->first(['id', 'scheme_name', 'section_number']);

        if (!$targetTp) {
            return ['updated' => 0, 'note' => 'Skipped — fewer than 4 deeds-captured tracked_properties present.'];
        }

        if ($targetTp->scheme_name === $targetProperty->complex_name && $targetTp->section_number === $targetProperty->unit_number) {
            return ['updated' => 0, 'note' => "Already set — tracked_property #{$targetTp->id} already collides with property #{$targetProperty->id}."];
        }

        DB::table('tracked_properties')->where('id', $targetTp->id)->update([
            'scheme_name'    => $targetProperty->complex_name,
            'section_number' => $targetProperty->unit_number,
            // Bump enrichment markers so this row sorts near the top of the
            // suspense list (index orders by last_enriched_at DESC), same
            // convention DemoDeedsSeeder uses for its own rows.
            'last_enriched_at'       => now(),
            'last_enrichment_source' => 'demo_seed_property_match_demo',
            'updated_at'             => now(),
        ]);

        $note = "Property-match demo: tracked_property #{$targetTp->id} now collides with property #{$targetProperty->id} "
            . "({$targetProperty->address}, {$targetProperty->suburb}) — previewPropertyMatch() will detect it live.";

        return ['updated' => 1, 'note' => $note, 'tracked_property_id' => $targetTp->id, 'property_id' => $targetProperty->id];
    }
}
