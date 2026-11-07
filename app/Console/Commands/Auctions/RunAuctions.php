<?php

namespace App\Console\Commands\Auctions;

use App\Services\Auctions\AuctionStartNotifier;
use App\Services\Auctions\LotResultService;
use Illuminate\Console\Command;

/** Every minute: open lots whose bidding window has started, close (and settle) the ones that have ended, and tell signed-up buyers an auction has started. */
class RunAuctions extends Command
{
    protected $signature = 'auctions:run';
    protected $description = 'Open and close timed auction lots on schedule';

    public function handle(LotResultService $results, AuctionStartNotifier $starts): int
    {
        $opened = $results->openDueLots();
        $closed = $results->closeDueLots();
        $emailed = $starts->run();
        $this->info("Opened {$opened} lot(s), closed {$closed} lot(s), queued {$emailed} start email(s).");

        return self::SUCCESS;
    }
}
