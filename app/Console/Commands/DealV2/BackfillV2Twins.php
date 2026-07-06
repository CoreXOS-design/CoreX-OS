<?php

namespace App\Console\Commands\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Services\DealV2\DealSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * DR1 → DR2 twin backfill (Johan-ruled 2026-07-06; .ai/specs/dr2-twin-backfill.md).
 *
 * Pairs each DR1 deal (`deals`) with a linked DR2 twin (`deals_v2`) so the DR2
 * register shows the complete book. Twins carry NO pipeline — they are marked
 * `backfilled_at` and presented as "captured pre-pipeline". Pipeline attachment
 * stays exclusively on the new-deal capture path.
 *
 * DR1-untouchable: the only DR1 write is its additive `deal_v2_id` pointer, done
 * via a raw update (no observer/event). Twins are created with saveQuietly() so
 * DealV2Observer → DealSyncService::syncFromV2 cannot write back to DR1.
 *
 * Idempotent + resumable: only deals without a twin are processed, so a re-run
 * pairs newly-captured DR1 deals and never double-creates. This re-runnability
 * IS the transition-sync answer (no DR1 insert hook); once a twin exists the
 * built DealSyncService observers keep shared fields synced automatically.
 */
class BackfillV2Twins extends Command
{
    protected $signature = 'deals:backfill-v2-twins {--agency= : limit to one agency_id} {--dry-run : report only, write nothing}';

    protected $description = 'Backfill DR1 deals into linked DR2 twins (no pipeline; pre-pipeline marker).';

    public function handle(DealSyncService $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $agency = $this->option('agency');

        // DR1 read-only invariant — capture the exact live-row count up front.
        $dr1Before = Deal::query()->when($agency, fn ($q) => $q->where('agency_id', $agency))->count();

        $candidates = Deal::query()
            ->when($agency, fn ($q) => $q->where('agency_id', $agency))
            ->where(fn ($q) => $q->whereNull('deal_v2_id')->orWhere('deal_v2_id', 0))
            ->orderBy('id')
            ->get();

        $this->info(($dry ? '[dry-run] ' : '') . "DR1 deals without a twin: {$candidates->count()} (of {$dr1Before} total)");

        $created = 0;
        $skipped = 0;

        foreach ($candidates as $deal) {
            // Resumability guard: a twin may already exist (prior partial run) with
            // the pointer not yet set — never create a duplicate.
            $existing = DealV2::withoutGlobalScopes()->where('legacy_deal_id', $deal->id)->first();
            if ($existing) {
                if (! $dry && (int) $deal->deal_v2_id !== (int) $existing->id) {
                    DB::table('deals')->where('id', $deal->id)->update(['deal_v2_id' => $existing->id]);
                }
                $skipped++;
                continue;
            }

            $listingAgentId = $this->resolveAgent($deal->id, 'listing') ?? $this->resolveAgent($deal->id, null);
            if (! $listingAgentId) {
                $this->warn("  skip DR1 #{$deal->id}: no agent on deal_user (cannot satisfy listing_agent_id).");
                $skipped++;
                continue;
            }

            $incl = (float) ($deal->total_commission ?? 0);
            $commissionAmount = round($incl / 1.15, 2);
            $commissionVat = round($incl - $commissionAmount, 2);
            $price = $deal->sale_price ?: ($deal->property_value ? (int) round((float) $deal->property_value) : 0);
            $offer = $deal->deal_date ?: ($deal->sale_date ?: $deal->created_at);
            $status = $sync->v1StateToV2Status($deal);

            if ($dry) {
                $created++;
                continue;
            }

            DB::transaction(function () use ($deal, $listingAgentId, $status, $price, $commissionAmount, $commissionVat, $offer, &$created) {
                $twin = new DealV2();
                $twin->forceFill([
                    'agency_id'            => $deal->agency_id,
                    'branch_id'            => $deal->branch_id,
                    'legacy_deal_id'       => $deal->id,
                    'reference'            => 'DR1-' . $deal->id,
                    'deal_type'            => 'cash', // DR1 never captured type — neutral default (spec)
                    'status'               => $status,
                    'property_id'          => null,   // DR1 has free-text address only
                    'listing_agent_id'     => $listingAgentId,
                    'selling_agent_id'     => $this->resolveAgent($deal->id, 'selling'),
                    'pipeline_template_id' => null,    // NO pipeline (Johan ruling)
                    'purchase_price'       => $price,
                    'commission_amount'    => $commissionAmount,
                    'commission_vat'       => $commissionVat,
                    'commission_status'    => $deal->commission_status ?: 'Not Paid',
                    'offer_date'           => $offer,
                    'actual_registration'  => $status === 'completed' ? $deal->registration_date : null,
                    'overall_rag'          => 'grey',  // no pipeline → no RAG
                    'backfilled_at'        => now(),
                    'created_by_id'        => $listingAgentId,
                ]);
                // saveQuietly: DealV2Observer (→ syncFromV2 write-back to DR1) must NOT fire.
                $twin->saveQuietly();

                // DR1 additive pointer only — raw update, no Eloquent event/observer.
                DB::table('deals')->where('id', $deal->id)->update(['deal_v2_id' => $twin->id]);
                $created++;
            });
        }

        // Verify DR1 was not mutated in count (no inserts/deletes).
        $dr1After = Deal::query()->when($agency, fn ($q) => $q->where('agency_id', $agency))->count();
        if ($dr1After !== $dr1Before) {
            $this->error("DR1 INVARIANT VIOLATED: before={$dr1Before} after={$dr1After}. Investigate.");
            return self::FAILURE;
        }

        $this->info(($dry ? '[dry-run] ' : '') . "Twins created: {$created}   skipped(existing/no-agent): {$skipped}   DR1 count unchanged: {$dr1After}");
        return self::SUCCESS;
    }

    /** Resolve a user_id from the deal_user pivot for a side (null = any side). */
    private function resolveAgent(int $dealId, ?string $side): ?int
    {
        $q = DB::table('deal_user')->where('deal_id', $dealId);
        if ($side !== null) {
            $q->where('side', $side);
        }
        $row = $q->orderBy('user_id')->first();

        return $row ? (int) $row->user_id : null;
    }
}
