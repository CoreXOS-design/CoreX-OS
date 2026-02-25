<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Deal;
use App\Models\DealSettlement;
use App\Models\Property;
use App\Observers\DealObserver;
use App\Observers\DealSettlementObserver;
use App\Observers\PropertyObserver;

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
        Property::observe(PropertyObserver::class);
    }
}
