<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\BuyerStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The nightly (04:00) buyer pipeline job. AT-500 (Johan, 22 Oct 2026): it follows the full Won / Lost rule set
 * through BuyerStateService and nothing else, so it can never "stuff up" the pipeline again.
 *
 *   1. SELF-HEAL (same selectors as buyers:backfill-won):
 *        - a buyer on a live/registered DR2 deal who is not Won                      -> Won
 *        - a Won buyer on no live deal and not moved there by hand, whose deal was
 *          DECLINED                                                                  -> Lost  (deal_fell_through)
 *        - the same, but nothing fell through (old link rule, archived/removed)       -> back to the board
 *                                                                                      (won_without_live_deal)
 *      Every change is audited with its reason in buyer_state_transitions.
 *   2. RECOMPUTE everyone else from their activity (New/Warm/Cold, decaying to Lost) - but NEVER a Won buyer,
 *      a Lost buyer, or a buyer an agent placed by hand recently (BuyerStateService::nextAutoState()).
 *   3. One summary line to the console and the log.
 *
 * Idempotent: a second run changes nothing.
 */
class RecomputeBuyerStates extends Command
{
    protected $signature = 'buyers:recompute-states {--dry-run : Show transitions without applying}';
    protected $description = 'Nightly: self-heal Won/Lost against live DR2 deals, then recompute buyer lifecycle states from last_activity_at (never touches Won or Lost buyers)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $service = app(BuyerStateService::class);

        // ── 1. SELF-HEAL ──
        // Both sets are chosen before anything is written, so they can never overlap.
        $toWon     = $service->liveDealBuyersNotWon();
        $noBasis   = $service->wonBuyersWithoutBasis();
        $healedIds = $toWon->pluck('id')->merge($noBasis->pluck('id'))->all();

        $movedToWon = $movedToLost = $backToBoard = 0;

        if ($dryRun) {
            foreach ($toWon as $c) {
                $this->line("  {$c->full_name}: " . ($c->buyer_state ?? 'none') . ' → won (on a live deal)');
            }
            foreach ($noBasis as $c) {
                $dest = $service->wonWithoutBasisDestination($c);
                $this->line("  {$c->full_name}: won → " . ($dest === 'lost' ? 'lost (deal fell through)' : 'back to the board'));
            }
            $movedToWon  = $toWon->count();
            $movedToLost = $noBasis->filter(fn ($c) => $service->wonWithoutBasisDestination($c) === 'lost')->count();
            $backToBoard = $noBasis->count() - $movedToLost;
        } else {
            foreach ($toWon as $c) {
                if ($service->markWon($c, null)) {
                    $movedToWon++;
                }
            }
            foreach ($noBasis as $c) {
                $where = $service->healWonWithoutBasis($c, null);
                if ($where === 'lost') {
                    $movedToLost++;
                } elseif ($where === 'board') {
                    $backToBoard++;
                }
            }
        }

        // ── 2. RECOMPUTE the rest ──
        $buyers = Contact::withoutGlobalScopes()
            ->where('is_buyer', true)
            ->whereNull('deleted_at')
            ->whereNull('purged_at')
            ->get();

        $this->info("Processing {$buyers->count()} buyers...");
        $recomputed = 0;
        $untouched = 0;

        foreach ($buyers as $contact) {
            // In a dry run a buyer the self-heal above WOULD change is not also listed as a recompute.
            if (in_array($contact->id, $healedIds, true)) {
                continue;
            }

            // nextAutoState(): Won and Lost are terminal for the nightly; a recent manual placement is protected (AT-74).
            $newState = $service->nextAutoState($contact);
            if ($newState === null) {
                $untouched++;
                continue;
            }

            if ($dryRun) {
                $this->line("  {$contact->full_name}: {$contact->buyer_state} → {$newState}");
            } else {
                $service->recomputeState($contact);
            }
            $recomputed++;
        }

        $summary = sprintf(
            'buyers:recompute-states %ssummary: moved_to_won=%d moved_to_lost=%d back_to_board=%d recomputed=%d untouched=%d',
            $dryRun ? '(DRY RUN) ' : '', $movedToWon, $movedToLost, $backToBoard, $recomputed, $untouched
        );
        $this->info($summary);
        Log::info($summary);

        if ($dryRun) {
            $this->warn('DRY RUN — no changes made.');
        }

        return 0;
    }
}
