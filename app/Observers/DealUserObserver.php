<?php

namespace App\Observers;

use App\Models\DealUser;
use Illuminate\Support\Facades\Artisan;

class DealUserObserver
{
    public function saved(DealUser $du): void
    {
        Artisan::call('recalc:deal-money-lines', [
            'deal_id' => $du->deal_id,
        ]);
    }
}
