<?php

namespace App\Services\Performance;

use App\Services\Performance\Providers\AppointmentsProvider;
use App\Services\Performance\Providers\BuyersAddedProvider;
use App\Services\Performance\Providers\CommissionGrossProvider;
use App\Services\Performance\Providers\ContactsCreatedProvider;
use App\Services\Performance\Providers\DealsCreatedProvider;
use App\Services\Performance\Providers\DealsRegisteredProvider;
use App\Services\Performance\Providers\FicaSubmissionsProvider;
use App\Services\Performance\Providers\MetricProvider;
use App\Services\Performance\Providers\PortalViewsProvider;
use App\Services\Performance\Providers\PresentationsCreatedProvider;
use App\Services\Performance\Providers\PropertiesCreatedProvider;
use App\Services\Performance\Providers\ProspectingClaimsProvider;
use App\Services\Performance\Providers\SellerOutreachProvider;
use App\Services\Performance\Providers\ViewingsProvider;

/**
 * AT-366 — the ordered set of metric providers the report runs, arranged as the
 * agent journey: prospect → capture → list → present → advertise → work buyers →
 * meet → outreach → comply → close.
 */
class MetricProviderRegistry
{
    /** @return MetricProvider[] */
    public function all(): array
    {
        return [
            app(ProspectingClaimsProvider::class),
            app(ContactsCreatedProvider::class),
            app(PropertiesCreatedProvider::class),
            app(PresentationsCreatedProvider::class),
            app(PortalViewsProvider::class),
            app(BuyersAddedProvider::class),
            app(ViewingsProvider::class),
            app(AppointmentsProvider::class),
            app(SellerOutreachProvider::class),
            app(FicaSubmissionsProvider::class),
            app(DealsCreatedProvider::class),
            app(DealsRegisteredProvider::class),
            app(CommissionGrossProvider::class),
        ];
    }
}
