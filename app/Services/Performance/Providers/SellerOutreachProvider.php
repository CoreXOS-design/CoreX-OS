<?php

namespace App\Services\Performance\Providers;

/** AT-366-B — seller-outreach messages sent per agent (category 12). */
class SellerOutreachProvider extends AbstractCountMetricProvider
{
    public function key(): string { return 'outreach_messages'; }
    public function label(): string { return 'Outreach messages'; }
    protected function table(): string { return 'seller_outreach_sends'; }
    protected function userColumn(): string { return 'agent_id'; }
    protected function periodColumn(): string { return 'sent_at'; }
}
