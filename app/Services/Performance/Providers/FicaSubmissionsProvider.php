<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\AgentActivityFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** AT-366-B — FICA submissions per agent (category 10; Q2 = creating/requesting agent). */
class FicaSubmissionsProvider extends AbstractCountMetricProvider
{
    public function key(): string { return 'fica_submissions'; }
    public function label(): string { return 'FICA submissions'; }
    protected function table(): string { return 'fica_submissions'; }
    protected function userColumn(): string { return 'requested_by'; }
    protected function periodColumn(): string { return 'created_at'; }

    // AT-366 correctness: only FICA for a genuine (native-or-worked) contact counts — the
    // 2026-06 import bulk-created ~7,400 pre-approved FICA stubs against imported contacts.
    protected function baseQuery(): Builder
    {
        return AgentActivityFilter::ficaViaContact(DB::table('fica_submissions'));
    }
}
