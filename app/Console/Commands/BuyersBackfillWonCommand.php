<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\BranchScope;
use App\Services\BuyerStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Buyer WON backfill (Johan 2026-08-13) — one-time move of EXISTING already-converted buyers into
 * the Won/Success section, applying the exact same rule the live listener applies going forward.
 *
 * The live rule (MarkBuyerWonOnPropertyLink) fires on ContactLinkedToProperty(role:buyer) and marks
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

        // Candidate contact ids — a BUYER party on a DR2 deal that is live (AT-500): not archived and,
        // unless --any-deal, not declined. BuyerStateService::isOnLiveDeal() is the same definition in SQL.
        $dealBuyers = DB::table('deal_contacts')
            ->join('deals', 'deals.id', '=', 'deal_contacts.deal_id')
            ->where('deal_contacts.role', 'buyer')
            ->whereNotNull('deal_contacts.contact_id')
            ->whereNull('deals.deleted_at')
            ->when(! $anyDeal, fn ($q) => $q->whereRaw("COALESCE(deals.accepted_status, '') <> 'D'"))
            ->distinct()->pluck('deal_contacts.contact_id')->map(fn ($v) => (int) $v)->all();

        $candidateIds = array_values(array_unique($dealBuyers));
        if (empty($candidateIds)) {
            $this->info('No buyers on a ' . ($anyDeal ? 'non-archived' : 'live') . ' deal found — nothing to backfill.');
            return self::SUCCESS;
        }

        // Targets: candidates not already 'won' (idempotent). Keep SoftDeletes (skip trashed contacts).
        $targets = Contact::withoutGlobalScopes([AgencyScope::class, BranchScope::class])
            ->whereIn('id', $candidateIds)
            ->when($agencyFilter !== null, fn ($q) => $q->where('agency_id', $agencyFilter))
            ->where(function ($q) {
                $q->where('buyer_state', '!=', BuyerStateService::WON)->orWhereNull('buyer_state');
            })
            ->get();

        $wonAlready = Contact::withoutGlobalScopes([AgencyScope::class, BranchScope::class])
            ->whereIn('id', $candidateIds)
            ->when($agencyFilter !== null, fn ($q) => $q->where('agency_id', $agencyFilter))
            ->where('buyer_state', BuyerStateService::WON)->count();

        $byState = $targets->groupBy(fn ($c) => $c->buyer_state ?? 'NULL')->map->count()->toArray();

        $this->line(sprintf('  candidates (buyer on a %s deal): %d  (already won, skipped: %d)', $anyDeal ? 'non-archived' : 'live', count($candidateIds), $wonAlready));
        $this->line('  targets to move -> won: ' . $targets->count());
        $this->line('  by current state: ' . json_encode($byState));
        $this->line('  sample:');
        foreach ($targets->take(10) as $t) {
            $this->line(sprintf('    #%d  %s  [%s] agency=%s', $t->id, $t->full_name ?: '(no name)', $t->buyer_state ?? 'NULL', $t->agency_id));
        }

        if ($dryRun) {
            $this->warn(sprintf("$tag — %d buyer(s) WOULD move to Won. Re-run without --dry-run to apply.", $targets->count()));
            return self::SUCCESS;
        }

        $moved = 0;
        foreach ($targets as $contact) {
            if ($states->markWon($contact, null)) {
                $moved++;
            }
        }

        $this->info(sprintf('Done. %d buyer(s) moved to Won (of %d targets; %d already won).', $moved, $targets->count(), $wonAlready));
        $this->info('Re-running is safe (idempotent — an already-won buyer is never re-applied).');

        return self::SUCCESS;
    }
}
