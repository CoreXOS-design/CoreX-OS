<?php

namespace App\Console\Commands\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Services\DealV2\DealSyncService;
use App\Services\DealV2\DealTwinIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * WS1 (AT-158 / DR2, spec §13.3) — the DR1↔DR2 parity harness.
 *
 * For every LINKED pair (deals.deal_v2_id set → its deals_v2 twin) compares the
 * shared core fields the DealSyncService mirrors and reports mismatches.
 * Read-only by default (the safety net that proves the mirror holds across the
 * 131 real backfilled deals during the parallel run); `--fix` re-runs the mirror
 * from DR1 to converge a drifted pair. Exit code is non-zero on any mismatch so
 * it can gate a promotion.
 *
 * 2026-10-08 — also the MONEY guard (one source for a deal's money): for every
 * linked pair it compares, in whole cents, the real deal's money against what the v2
 * screens show, against the saved money lines the dashboards sum, and against the
 * twin's own dormant copy, plus the link structure (one twin per deal). See
 * DealTwinIntegrityService. FAIL findings fail the command; WARN findings (a stale
 * dormant copy nothing displays) are listed and fail only with --strict. It never
 * repairs money — `--fix` still only re-mirrors the status/price fields above.
 * Scheduled daily (routes/console.php) so a divergence cannot go unnoticed.
 */
class DealParityCheck extends Command
{
    protected $signature = 'deals:parity-check
        {--fix : Converge mismatched status/price pairs by re-mirroring DR1→DR2 (default: report only; never touches money)}
        {--strict : Also fail on WARN findings (a stale saved copy on the v2 row that nothing displays)}
        {--agency= : Limit the money/link check to one agency id}';

    protected $description = 'Compare shared core fields for every linked DR1↔DR2 deal pair; report mismatches (read-only by default).';

    public function handle(DealSyncService $sync, DealTwinIntegrityService $integrity): int
    {
        $pairs = Deal::withoutGlobalScopes()->whereNotNull('deal_v2_id')->get();

        if ($pairs->isEmpty()) {
            $this->info('deals:parity-check — no linked pairs.');
            return $this->reportMoney($integrity, 0);
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

        $this->info("deals:parity-check — {$pairs->count()} pair(s), {$mismatch} field mismatch(es).");

        return $this->reportMoney($integrity, $mismatch);
    }

    /** Money + link-structure guard; returns the command's exit code. */
    private function reportMoney(DealTwinIntegrityService $integrity, int $fieldMismatches): int
    {
        $agency = $this->option('agency') ? (int) $this->option('agency') : null;
        $result = $integrity->audit($agency);
        $screens = $integrity->auditScreens($agency);
        $result['findings'] = array_merge($result['findings'], $screens['findings']);

        $fails = array_values(array_filter($result['findings'], fn ($f) => $f['severity'] === DealTwinIntegrityService::FAIL));
        $warns = array_values(array_filter($result['findings'], fn ($f) => $f['severity'] === DealTwinIntegrityService::WARN));

        foreach ($fails as $f) {
            $this->line("FAIL deal " . ($f['deal_no'] ?? $f['deal_id']) . " (id {$f['deal_id']}" . ($f['v2_id'] ? ", v2 {$f['v2_id']}" : '') . "): {$f['message']}");
        }
        foreach ($warns as $f) {
            $this->line("WARN deal " . ($f['deal_no'] ?? $f['deal_id']) . " (id {$f['deal_id']}" . ($f['v2_id'] ? ", v2 {$f['v2_id']}" : '') . "): {$f['message']}");
        }
        $this->info("deals:parity-check money — {$result['pairs']} linked deal(s) compared in cents, {$screens['deals']} deal(s) screen-vs-saved: " . count($fails) . ' FAIL, ' . count($warns) . ' WARN.');

        if ($fails) {
            Log::critical('deals:parity-check — a v2 deal no longer matches its real deal', ['fail_count' => count($fails), 'first' => array_slice($fails, 0, 20)]);
        }
        if ($warns) {
            Log::warning('deals:parity-check — stale saved copies on v2 rows (not displayed)', ['warn_count' => count($warns), 'first' => array_slice($warns, 0, 20)]);
        }

        $bad = $fieldMismatches > 0 || $fails || ($this->option('strict') && $warns);

        return $bad ? self::FAILURE : self::SUCCESS;
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
