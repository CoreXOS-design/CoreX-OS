<?php

namespace App\Services\Performance\Providers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** AT-366-B — buyers added to an agent's pipeline in the period (category 7). */
class BuyersAddedProvider extends AbstractCountMetricProvider
{
    public function key(): string { return 'buyers_added'; }
    public function label(): string { return 'Buyers added'; }
    protected function table(): string { return 'contacts'; }
    protected function userColumn(): string { return 'agent_id'; }
    protected function periodColumn(): string { return 'buyer_pipeline_entered_at'; }
    protected function baseQuery(): Builder
    {
        return DB::table('contacts')->whereNull('deleted_at')->where('is_buyer', 1);
    }
}
