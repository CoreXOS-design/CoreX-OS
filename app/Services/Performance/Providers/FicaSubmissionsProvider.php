<?php

namespace App\Services\Performance\Providers;

/** AT-366-B — FICA submissions per agent (category 10; Q2 = creating/requesting agent). */
class FicaSubmissionsProvider extends AbstractCountMetricProvider
{
    public function key(): string { return 'fica_submissions'; }
    public function label(): string { return 'FICA submissions'; }
    protected function table(): string { return 'fica_submissions'; }
    protected function userColumn(): string { return 'requested_by'; }
    protected function periodColumn(): string { return 'created_at'; }
}
