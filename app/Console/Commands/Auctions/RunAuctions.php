<?php

namespace App\Console\Commands\Auctions;

use App\Services\Auctions\LotResultService;
use Illuminate\Console\Command;

/** Every minute: open lots whose bidding window has started, and close (and settle) the ones that have ended. */
class RunAuctions extends Command
{
    protected $signature = 'auctions:run';
    protected $description = 'Open and close timed auction lots on schedule';

    public function handle(LotResultService $results): int
    {
        $opened = $results->openDueLots();
        $closed = $results->closeDueLots();
        $this->info("Opened {$opened} lot(s), closed {$closed} lot(s).");

        return self::SUCCESS;
    }
}
