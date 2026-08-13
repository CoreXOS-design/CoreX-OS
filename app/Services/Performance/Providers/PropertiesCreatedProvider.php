<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\AgentActivityFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** AT-366-B — properties created per agent (category 5). */
class PropertiesCreatedProvider extends AbstractCountMetricProvider
{
    public function key(): string { return 'properties_created'; }
    public function label(): string { return 'Properties created'; }
    protected function table(): string { return 'properties'; }
    protected function userColumn(): string { return 'agent_id'; }
    protected function periodColumn(): string { return 'created_at'; }

    // AT-366 correctness: exclude untouched historical imports (count only CoreX-engaged listings).
    protected function baseQuery(): Builder
    {
        return AgentActivityFilter::properties(DB::table('properties')->whereNull('deleted_at'));
    }
}
