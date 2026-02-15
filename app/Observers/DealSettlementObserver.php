<?php

namespace App\Observers;

use App\Models\DealSettlement;
use Illuminate\Support\Facades\Artisan;

class DealSettlementObserver
{
    public function saved(DealSettlement $settlement): void
    {
        Artisan::call('deals:recalc-money-lines');
    }
}
