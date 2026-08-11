<?php

namespace App\Services\Performance\Providers;

/** AT-366-B — presentations created per agent (category 3; Q3 = creating agent). */
class PresentationsCreatedProvider extends AbstractCountMetricProvider
{
    public function key(): string { return 'presentations_created'; }
    public function label(): string { return 'Presentations created'; }
    protected function table(): string { return 'presentations'; }
    protected function userColumn(): string { return 'created_by_user_id'; }
    protected function periodColumn(): string { return 'created_at'; }
}
