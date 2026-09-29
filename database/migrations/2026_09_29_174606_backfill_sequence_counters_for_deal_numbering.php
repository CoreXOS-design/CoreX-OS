<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed sequence_counters from whatever deal_no / reference values already
 * exist, so the new AtomicSequenceService-based allocation continues exactly
 * where the old read-max-then-increment logic left off — no deal_no or
 * reference is ever reused, including ones that belong to a soft-deleted
 * deal (the old runtime MAX() query on `deals` did NOT include trashed rows;
 * this backfill deliberately does, via DB::table(), to close that latent
 * reuse gap while we're already replacing the mechanism it lived in).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ── Deal::deal_no — per agency, "D-####" or plain numeric strings (DR1 parity,
        // see Dr2DealRegisterController::store()'s own numbering comment). ──
        $deals = DB::table('deals')->select('agency_id', 'deal_no')->whereNotNull('deal_no')->get();

        $maxByAgency = [];
        foreach ($deals as $row) {
            $agencyId = (int) $row->agency_id;
            $raw = (string) $row->deal_no;

            if (str_starts_with($raw, 'D-')) {
                $numeric = (int) substr($raw, 2);
            } elseif (preg_match('/^\d+$/', $raw)) {
                $numeric = (int) $raw;
            } else {
                continue; // non-numeric legacy value — not part of the numbering sequence
            }

            $maxByAgency[$agencyId] = max($maxByAgency[$agencyId] ?? 0, $numeric);
        }

        foreach ($maxByAgency as $agencyId => $max) {
            DB::table('sequence_counters')->insertOrIgnore([
                'scope' => "deal_no:agency:{$agencyId}",
                // Fresh/wiped-agency parity with the old fallback: start at 1001.
                'next_value' => max($max, 1000) + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── DealV2::reference — per agency PER YEAR, "DL-{year}-{seq}" (see
        // DealV2::generateReference()). Twin references ("DR1-{legacy_deal_id}")
        // don't match this prefix and are correctly skipped. ──
        $v2 = DB::table('deals_v2')->select('agency_id', 'reference')
            ->where('reference', 'like', 'DL-%')->get();

        $maxByAgencyYear = [];
        foreach ($v2 as $row) {
            if (! preg_match('/^DL-(\d{4})-(\d+)$/', (string) $row->reference, $m)) {
                continue; // doesn't match the generated shape — not part of the sequence
            }
            $agencyId = (int) $row->agency_id;
            $year = $m[1];
            $seq = (int) $m[2];
            $key = $agencyId . ':' . $year;
            $maxByAgencyYear[$key] = max($maxByAgencyYear[$key] ?? 0, $seq);
        }

        foreach ($maxByAgencyYear as $key => $max) {
            [$agencyId, $year] = explode(':', $key);
            DB::table('sequence_counters')->insertOrIgnore([
                'scope' => "deal_v2_reference:agency:{$agencyId}:year:{$year}",
                'next_value' => $max + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('sequence_counters')
            ->where('scope', 'like', 'deal_no:agency:%')
            ->orWhere('scope', 'like', 'deal_v2_reference:agency:%')
            ->delete();
    }
};
