<?php

namespace App\Services\Performance\Providers;

/** AT-366-B — MIC prospecting claims per agent (category 1). */
class ProspectingClaimsProvider extends AbstractCountMetricProvider
{
    public function key(): string { return 'mic_claims'; }
    public function label(): string { return 'MIC claims'; }
    protected function table(): string { return 'prospecting_claims'; }
    protected function userColumn(): string { return 'user_id'; }
    protected function periodColumn(): string { return 'claimed_at'; }
}
