<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Dedup foundation (Q4 Phase B Step 4) — presentation_* ↔ MRCR proper FK.
 *
 * Today the link from presentation_sold_comps / presentation_active_listings
 * to market_report_comp_rows is encoded as `mic_comp_row_id` INSIDE the
 * `raw_row_json` text column. MapPinService::soldComps / activeListings
 * parse the JSON at read time and skip rows whose JSON lacks the key.
 *
 * This migration adds a real nullable FK column on both presentation
 * tables, backfills it from the JSON, and leaves `raw_row_json` in place
 * (per the prompt — backwards-compat for the read path during the
 * transition; future prompt can drop the JSON dependency once readers
 * are migrated to the FK).
 *
 * Backfill runs inside a transaction. Unfillable rows (no parseable key
 * in the JSON, non-numeric value, etc.) stay NULL and are reported in
 * the migration output + the application log. They continue to flow
 * through the JSON read path until a future cleanup.
 *
 * FK is nullOnDelete — a MRCR row being soft-deleted should not cascade
 * into the presentation row (the presentation may need to surface the
 * comp's data regardless via raw_row_json). Mirrors the cascade choice
 * for market_data_points + market_report_comp_rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('presentation_sold_comps', function (Blueprint $table) {
            $table->foreignId('mic_comp_row_id')
                ->nullable()
                ->after('id')
                ->constrained('market_report_comp_rows')
                ->nullOnDelete();
        });

        Schema::table('presentation_active_listings', function (Blueprint $table) {
            $table->foreignId('mic_comp_row_id')
                ->nullable()
                ->after('id')
                ->constrained('market_report_comp_rows')
                ->nullOnDelete();
        });

        DB::transaction(function () {
            $counts = ['psc' => ['scanned' => 0, 'filled' => 0, 'unfillable' => 0],
                       'pal' => ['scanned' => 0, 'filled' => 0, 'unfillable' => 0]];

            // presentation_sold_comps
            DB::table('presentation_sold_comps')
                ->whereNull('mic_comp_row_id')
                ->orderBy('id')
                ->select(['id', 'raw_row_json'])
                ->chunkById(500, function ($rows) use (&$counts) {
                    foreach ($rows as $r) {
                        $counts['psc']['scanned']++;
                        $compRowId = self::extractCompRowId($r->raw_row_json);
                        if ($compRowId === null) {
                            $counts['psc']['unfillable']++;
                            continue;
                        }
                        // Confirm the MRCR row exists before linking — orphan
                        // FKs would violate the constraint.
                        $exists = DB::table('market_report_comp_rows')
                            ->where('id', $compRowId)->exists();
                        if (!$exists) {
                            $counts['psc']['unfillable']++;
                            continue;
                        }
                        DB::table('presentation_sold_comps')
                            ->where('id', $r->id)
                            ->update(['mic_comp_row_id' => $compRowId]);
                        $counts['psc']['filled']++;
                    }
                });

            // presentation_active_listings
            DB::table('presentation_active_listings')
                ->whereNull('mic_comp_row_id')
                ->orderBy('id')
                ->select(['id', 'raw_row_json'])
                ->chunkById(500, function ($rows) use (&$counts) {
                    foreach ($rows as $r) {
                        $counts['pal']['scanned']++;
                        $compRowId = self::extractCompRowId($r->raw_row_json);
                        if ($compRowId === null) {
                            $counts['pal']['unfillable']++;
                            continue;
                        }
                        $exists = DB::table('market_report_comp_rows')
                            ->where('id', $compRowId)->exists();
                        if (!$exists) {
                            $counts['pal']['unfillable']++;
                            continue;
                        }
                        DB::table('presentation_active_listings')
                            ->where('id', $r->id)
                            ->update(['mic_comp_row_id' => $compRowId]);
                        $counts['pal']['filled']++;
                    }
                });

            $msg = sprintf(
                '    → presentation FK backfill: psc {scanned=%d filled=%d unfillable=%d} ; pal {scanned=%d filled=%d unfillable=%d}',
                $counts['psc']['scanned'], $counts['psc']['filled'], $counts['psc']['unfillable'],
                $counts['pal']['scanned'], $counts['pal']['filled'], $counts['pal']['unfillable'],
            );
            if (PHP_SAPI === 'cli') {
                fwrite(STDOUT, $msg . PHP_EOL);
            }
            Log::info('presentation_* mic_comp_row_id backfill complete', $counts);
        });
    }

    public function down(): void
    {
        Schema::table('presentation_active_listings', function (Blueprint $table) {
            $table->dropForeign(['mic_comp_row_id']);
            $table->dropColumn('mic_comp_row_id');
        });
        Schema::table('presentation_sold_comps', function (Blueprint $table) {
            $table->dropForeign(['mic_comp_row_id']);
            $table->dropColumn('mic_comp_row_id');
        });
    }

    /**
     * Extract `mic_comp_row_id` from a raw_row_json text blob. Returns
     * null when the JSON is unparseable, the key is missing, or the
     * value isn't a positive integer. Defensive about every shape we
     * could plausibly see in legacy data.
     */
    private static function extractCompRowId($raw): ?int
    {
        if ($raw === null || $raw === '') return null;
        $decoded = is_string($raw) ? json_decode($raw, true) : ((array) $raw);
        if (!is_array($decoded)) return null;
        $id = $decoded['mic_comp_row_id'] ?? null;
        if ($id === null) return null;
        if (!is_numeric($id)) return null;
        $int = (int) $id;
        return $int > 0 ? $int : null;
    }
};
