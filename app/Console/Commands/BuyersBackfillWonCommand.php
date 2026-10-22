<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\BuyerStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Buyer WON backfill (Johan 2026-08-13) — one-time move of EXISTING already-converted buyers into
 * the Won/Success section, applying the exact same rule the live listener applies going forward.
 *
 * (Historical — the property-link listener that this describes was removed in AT-500; Won now comes only from a
 * live DR2 deal or a manual move.) The old rule fired on ContactLinkedToProperty(role:buyer) and marked
 * the buyer 'won'. Buyers linked BEFORE that shipped never ran the hook. This backfills any contact
 * that is ALREADY linked as a buyer:
 *   - on the property (contact_property.role IN ('buyer','purchaser')), OR
 *   - in a DR2 deal (deal_contacts.role='buyer' on a non-deleted deal),
 * and is not already 'won'. tenant/lessee/rental links are EXCLUDED (only buyer/purchaser) — exactly
 * as the live rule excludes them. Uses BuyerStateService::markWon() so it writes the SAME audit row
 * (buyer_state_transitions reason='property_linked') as the live path — WITHOUT re-firing the link
 * event (the link happened long ago; re-firing would write false "just linked" log entries).
 *
 * Idempotent: a contact already 'won' is skipped (the query excludes it AND markWon no-ops), so
 * re-running never double-applies. Active/in-progress buyers with NO buyer-property link are never
 * touched. Cross-agency by default (markWon stamps agency_id from each contact); --agency=N restricts.
 *
 * AT-500 (Johan, 22 Oct 2026) — Won = linked to a LIVE deal only. By default only buyers on a live DR2 deal
 * (pending / granted / registered, not declined, not archived) are candidates; a buyer whose only deals fell
 * through is NOT moved. --any-deal widens that to every non-archived deal (declined included) and exists to
 * COMPARE counts in a dry-run, not for a real run. Property-page-only buyer links are no longer candidates
 * (they are Won going forward by the live listener; there is no deal to have fallen through).
 *
 * PART 2 (AT-500, Johan's hard rule: Won = on a live deal, or moved there by hand): buyers who are Won TODAY with
 * NO live deal AND whose last move into Won was not 'manual_override' go back on the board — recomputed to
 * New / Warm / Cold (never Lost), audited as 'won_without_live_deal'. --dry-run reports both counts.
 *
 * Manual operation only — NOT invoked by scripts/deploy.sh.
 *
 * Usage:
 *   php artisan buyers:backfill-won --dry-run          # report counts + sample, write nothing
 *   php artisan buyers:backfill-won                     # apply, all agencies
 *   php artisan buyers:backfill-won --agency=1 --dry-run
 *   php artisan buyers:backfill-won --any-deal --dry-run   # comparison only: declined deals count too
 */
class BuyersBackfillWonCommand extends Command
{
    protected $signature = 'buyers:backfill-won
                            {--agency= : Restrict to a single agency_id}
                            {--any-deal : Comparison only — count buyers on ANY non-archived deal, declined included (default: live deals only)}
                            {--dry-run : Report counts without writing}';

    protected $description = 'One-time — move existing already-converted buyers (linked as buyer to a property/DR2 deal) into the Won section (idempotent).';

    public function handle(BuyerStateService $states): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $anyDeal = (bool) $this->option('any-deal');
        $agencyOpt = $this->option('agency');
        $agencyFilter = $agencyOpt !== null ? (int) $agencyOpt : null;

        $tag = $dryRun ? '[DRY-RUN]' : '[WRITE]';
        $scope = $agencyFilter ? "agency_id=$agencyFilter" : 'ALL agencies';
        $this->info("$tag buyers:backfill-won — scope: $scope; deals: " . ($anyDeal ? 'ANY non-archived deal (declined included)' : 'LIVE deals only'));

        if ($agencyFilter !== null && ! DB::table('agencies')->where('id', $agencyFilter)->exists()) {
            $this->error("Agency id=$agencyFilter not found.");
            return self::INVALID;
        }

        // PART 1 — buyers on a live deal who are not Won. Part 2 — Won buyers with no basis. Both come from
        // BuyerStateService (the same selectors the nightly self-heal uses), and both are chosen BEFORE anything
        // is written so the two sets can never overlap (a part-1 target is on a live deal; a part-2 one is not).
        $targets = $states->liveDealBuyersNotWon($agencyFilter, $anyDeal);
        $returns = $states->wonBuyersWithoutBasis($agencyFilter);
        $toLost  = $returns->filter(fn (Contact $c) => $states->wonWithoutBasisDestination($c) === 'lost');

        $this->line('  PART 1 — on a ' . ($anyDeal ? 'non-archived' : 'live') . ' deal and not Won -> Won: ' . $targets->count());
        $this->line('  by current state: ' . json_encode($targets->groupBy(fn ($c) => $c->buyer_state ?? 'NULL')->map->count()->toArray()));
        foreach ($targets->take(10) as $t) {
            $this->line(sprintf('    #%d  %s  [%s] agency=%s', $t->id, $t->full_name ?: '(no name)', $t->buyer_state ?? 'NULL', $t->agency_id));
        }
        $this->line(sprintf('  PART 2 — Won with no live deal and not moved by hand: %d  (-> Lost, deal fell through: %d; -> back to the board: %d)',
            $returns->count(), $toLost->count(), $returns->count() - $toLost->count()));
        foreach ($returns->take(10) as $r) {
            $this->line(sprintf('    #%d  %s  [won -> %s] agency=%s', $r->id, $r->full_name ?: '(no name)', $states->wonWithoutBasisDestination($r), $r->agency_id));
        }

        if ($dryRun) {
            $this->warn(sprintf("$tag — %d buyer(s) WOULD move to Won; %d Won buyer(s) WOULD go back to the board; %d WOULD go to Lost (deal fell through). Re-run without --dry-run to apply.",
                $targets->count(), $returns->count() - $toLost->count(), $toLost->count()));
            return self::SUCCESS;
        }

        $moved = 0;
        foreach ($targets as $contact) {
            if ($states->markWon($contact, null)) {
                $moved++;
            }
        }

        $board = 0;
        $lost = 0;
        foreach ($returns as $contact) {
            $where = $states->healWonWithoutBasis($contact, null);
            $where === 'lost' ? $lost++ : ($where === 'board' ? $board++ : null);
        }

        $this->info(sprintf('Done. %d buyer(s) moved to Won; %d Won buyer(s) returned to the board; %d moved to Lost (deal fell through).', $moved, $board, $lost));
        $this->info('Re-running is safe (idempotent — a second run changes nothing).');

        return self::SUCCESS;
    }
}
