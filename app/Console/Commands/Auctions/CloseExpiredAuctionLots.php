<?php

namespace App\Console\Commands\Auctions;

use App\Services\Auctions\AuctionLotStatusService;
use Illuminate\Console\Command;

/**
 * AT-432 Phase 4 — .ai/specs/auctions.md §11.2: "the lot closes when the
 * window passes quietly." Scheduled every minute (routes/console.php),
 * same cadence as this codebase's other TTL-expiry sweeps
 * (agency-access:expire, webhooks:retry-due).
 */
class CloseExpiredAuctionLots extends Command
{
    protected $signature = 'auctions:close-expired-lots';
    protected $description = 'Auto-close (sell or pass in) any pure-online auction lot whose bidding window has expired.';

    public function handle(AuctionLotStatusService $service): int
    {
        $closed = $service->closeExpiredOnlineLots();

        $this->info(count($closed).' lot(s) closed.');

        return self::SUCCESS;
    }
}
