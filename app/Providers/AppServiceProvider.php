<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Deal;
use App\Models\DealSettlement;
use App\Observers\DealObserver;
use App\Observers\DealSettlementObserver;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Deal::observe(DealObserver::class);
        DealSettlement::observe(DealSettlementObserver::class);
    }
}
