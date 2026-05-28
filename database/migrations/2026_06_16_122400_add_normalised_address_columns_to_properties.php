<?php

declare(strict_types=1);

use App\Models\Prospecting\TrackedPropertyAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Dedup foundation (Q4 Phase B Step 1) — H-side normalised address columns.
 *
 * Adds the same shape the matcher's Strategy 4 already uses on TPs:
 *   - suburb_normalised        (mirrors tracked_properties.suburb_normalised)
 *   - street_name_normalised   (TPs store the normalised value in `street_name`
 *                               itself via TrackedPropertyAddress::normaliseStreet
 *                               on save; properties keep `street_name` raw and
 *                               cache the normalised form in this new column)
 *
 * `street_number` and `unit_number` stay raw as they are — the dedup key
 * uses them as-is (trimmed). Composite index orders the columns from
 * most-selective (agency_id) outward so the matcher's same-shape query
 * can use it left-prefix.
 *
 * Unit-number IS in the composite index — by deliberate choice of Phase
 * B Decision 2: the map achieves unit-granularity via the GROUPER (two
 * flats same building different unit = TWO pins) WITHOUT extending the
 * matcher's Strategy 4 (which stays unit-blind — a TP represents the
 * property record, not the flat record). The H-side index supports BOTH
 * patterns: unit-aware exact queries, and unit-blind queries that ignore
 * the trailing index column.
 *
 * Backfill runs inside a transaction. Failures roll back cleanly.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('suburb_normalised', 100)->nullable()->after('suburb');
            $table->string('street_name_normalised', 200)->nullable()->after('street_name');
            $table->index(
                ['agency_id', 'suburb_normalised', 'street_name_normalised', 'street_number', 'unit_number'],
                'idx_properties_address_key',
            );
        });

        // Backfill the two new columns for every existing row that has
        // a source value. One transaction wraps the whole sweep so a
        // mid-sweep failure leaves the DB in its pre-migration state.
        DB::transaction(function () {
            $rows = 0;
            $updated = 0;
            DB::table('properties')
                ->whereNotNull('deleted_at')      // include trashed — admin restore should land with the cache populated
                ->orWhereNull('deleted_at')
                ->orderBy('id')
                ->select(['id', 'suburb', 'street_name'])
                ->chunkById(500, function ($props) use (&$rows, &$updated) {
                    foreach ($props as $p) {
                        $rows++;
                        $patch = [];
                        if (!empty($p->suburb)) {
                            $sub = TrackedPropertyAddress::normaliseSuburb($p->suburb);
                            if ($sub !== null) $patch['suburb_normalised'] = $sub;
                        }
                        if (!empty($p->street_name)) {
                            $sn = TrackedPropertyAddress::normaliseStreet($p->street_name);
                            if ($sn !== null) $patch['street_name_normalised'] = $sn;
                        }
                        if (!empty($patch)) {
                            DB::table('properties')->where('id', $p->id)->update($patch);
                            $updated++;
                        }
                    }
                });

            if (PHP_SAPI === 'cli') {
                fwrite(STDOUT, "    → properties normalised-address backfill: scanned {$rows}, updated {$updated}" . PHP_EOL);
            }
            Log::info('properties normalised-address backfill complete', [
                'scanned' => $rows,
                'updated' => $updated,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('idx_properties_address_key');
            $table->dropColumn(['suburb_normalised', 'street_name_normalised']);
        });
    }
};
