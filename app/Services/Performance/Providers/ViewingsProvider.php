<?php

namespace App\Services\Performance\Providers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** AT-366-B — buyer viewings booked per agent (calendar category = viewing). */
class ViewingsProvider extends AbstractCountMetricProvider
{
    public function key(): string { return 'viewings'; }
    public function label(): string { return 'Viewings'; }
    protected function table(): string { return 'calendar_events'; }
    protected function userColumn(): string { return 'user_id'; }
    protected function periodColumn(): string { return 'event_date'; }
    protected function baseQuery(): Builder { return DB::table('calendar_events')->where('category', 'viewing'); }
}
