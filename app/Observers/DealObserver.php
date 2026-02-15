<?php

namespace App\Observers;

use App\Models\Deal;
use Illuminate\Support\Facades\Artisan;

class DealObserver
{
    public function saved(Deal $deal): void
    {
        Artisan::call('deals:recalc-money-lines');
    }
}
