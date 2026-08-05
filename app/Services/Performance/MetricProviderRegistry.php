<?php

namespace App\Services\Performance;

use App\Services\Performance\Providers\ContactsCreatedProvider;
use App\Services\Performance\Providers\MetricProvider;

/**
 * AT-366 — the ordered set of metric providers the report runs.
 *
 * AT-366-A ships one reference provider (contacts created) that proves the
 * pipeline. AT-366-B appends the remaining per-category providers here — this
 * is the single place the report's coverage grows.
 */
class MetricProviderRegistry
{
    /** @return MetricProvider[] */
    public function all(): array
    {
        return [
            app(ContactsCreatedProvider::class),
        ];
    }
}
