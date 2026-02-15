<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DealMoneyLineRebuilder;

class RecalcDealMoneyLines extends Command
{
    protected $signature = 'deals:recalc-money-lines {--period=} {--deal_id=} {--dry-run}';
    protected $description = 'Rebuild deal_money_lines from deals + deal_user (+ deal_settlements if present).';

    public function handle(): int
    {
        $period = $this->option('period');
        $dealId = $this->option('deal_id');
        $dry = (bool)$this->option('dry-run');

        $count = DealMoneyLineRebuilder::rebuild(
            $period ? (string)$period : null,
            $dealId ? (int)$dealId : null,
            $dry
        );

        if ($count < 1) {
            $this->warn("No deals found for given filter.");
            return self::SUCCESS;
        }

        $this->info(($dry ? "DRY " : "") . "Rebuilt deal_money_lines for {$count} deal(s).");
        return self::SUCCESS;
    }
}
