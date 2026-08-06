<?php

namespace App\Jobs;

use App\Services\DealMoneyLineRebuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AT-364 follow-up (perf) — rebuild the derived deal_money_lines for ONE deal, off the web request.
 *
 * DealObserver::saved() used to run `deals:recalc-money-lines` with NO filter, which rebuilds EVERY
 * deal's money lines synchronously inside the request on every single deal save (O(all deals), plus a
 * full Artisan bootstrap). On a cold worker that blew past the FastCGI timeout → 502 on the first
 * click. This job scopes the rebuild to the saved deal and moves it to the queue so save/redirect
 * returns immediately.
 *
 * The MATH is unchanged: this calls the exact same DealMoneyLineRebuilder::rebuildDealId() the DR2
 * register and Admin deal controllers already call inline, which runs the identical per-deal
 * rebuildSingleDeal() the all-deals path ran — only WHICH deals recompute (this one) and WHEN
 * (after the response) change. Idempotent: re-running produces the same rows, so a redundant dispatch
 * (e.g. alongside a controller's inline rebuild) is harmless.
 */
class RebuildDealMoneyLinesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $dealId) {}

    public function handle(): void
    {
        // rebuildDealId → rebuild(null, $dealId): filters `where('id', $dealId)` and runs the same
        // per-deal rebuildSingleDeal() as the legacy all-deals path — byte-identical output for this deal.
        DealMoneyLineRebuilder::rebuildDealId($this->dealId);
    }

    // Note on transactions: on the `database` queue driver used in every environment, a job dispatched
    // inside a DB transaction is inserted into the `jobs` table WITHIN that transaction, so the worker
    // (a separate connection) cannot see it until commit — it never races an open transaction. Hence no
    // $afterCommit is needed here, and the job is exercised inline under the test suite's sync driver.
}
