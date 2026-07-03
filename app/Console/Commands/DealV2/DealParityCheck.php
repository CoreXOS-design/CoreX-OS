<?php

namespace App\Console\Commands\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Services\DealV2\DealSyncService;
use Illuminate\Console\Command;

/**
 * WS1 (AT-158 / DR2, spec §13.3) — the DR1↔DR2 parity harness.
 *
 * For every LINKED pair (deals.deal_v2_id set → its deals_v2 twin) compares the
 * shared core fields the DealSyncService mirrors and reports mismatches.
 * Read-only by default (the safety net that proves the mirror holds across the
 * 131 real backfilled deals during the parallel run); `--fix` re-runs the mirror
 * from DR1 to converge a drifted pair. Exit code is non-zero on any mismatch so
 * it can gate a promotion.
 */
class DealParityCheck extends Command
{
    protected $signature = 'deals:parity-check {--fix : Converge mismatched pairs by re-mirroring DR1→DR2 (default: report only)}';

    protected $description = 'Compare shared core fields for every linked DR1↔DR2 deal pair; report mismatches (read-only by default).';

    public function handle(DealSyncService $sync): int
    {
        $pairs = Deal::withoutGlobalScopes()->whereNotNull('deal_v2_id')->get();

        if ($pairs->isEmpty()) {
            $this->info('deals:parity-check — no linked pairs.');
            return self::SUCCESS;
        }

        $mismatch = 0;
        foreach ($pairs as $v1) {
            $v2 = DealV2::withoutGlobalScopes()->find($v1->deal_v2_id);
            if (! $v2) {
                $this->warn("deal {$v1->id}: linked deal_v2_id {$v1->deal_v2_id} not found");
                $mismatch++;
                continue;
            }

            $diffs = $this->compare($v1, $v2, $sync);
            if ($diffs) {
                $mismatch++;
                $this->line("MISMATCH deal {$v1->id} ↔ v2 {$v2->id}: " . implode('; ', $diffs));
                if ($this->option('fix')) {
                    $sync->syncFromV1($v1);
                    $this->line("  → re-mirrored DR1→DR2");
                }
            }
        }

        $this->info("deals:parity-check — {$pairs->count()} pair(s), {$mismatch} mismatch(es).");

        return $mismatch === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return string[] human-readable diffs (empty = in parity) */
    private function compare(Deal $v1, DealV2 $v2, DealSyncService $sync): array
    {
        $diffs = [];

        $expectStatus = $sync->v1StateToV2Status($v1);
        if ($v2->status !== $expectStatus) {
            $diffs[] = "status v2={$v2->status} expected={$expectStatus}";
        }

        $v1price = $v1->sale_price ?: ($v1->property_value ? (int) round((float) $v1->property_value) : 0);
        if ($v1price && (int) $v2->purchase_price !== (int) $v1price) {
            $diffs[] = "price v1={$v1price} v2={$v2->purchase_price}";
        }

        $inclV1 = round((float) $v1->total_commission, 2);
        $inclV2 = round((float) $v2->commission_amount + (float) $v2->commission_vat, 2);
        if (abs($inclV1 - $inclV2) > 0.01) {
            $diffs[] = "commission v1={$inclV1} v2={$inclV2}";
        }

        if ($v1->commission_status && $v2->commission_status && $v1->commission_status !== $v2->commission_status) {
            $diffs[] = "comm_status v1={$v1->commission_status} v2={$v2->commission_status}";
        }

        return $diffs;
    }
}
