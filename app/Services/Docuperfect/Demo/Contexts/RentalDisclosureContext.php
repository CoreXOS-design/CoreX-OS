<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** The landlord's defect disclosure given to a tenant: landlord discloses, tenant acknowledges, agent witnesses. */
final class RentalDisclosureContext extends AbstractDemoContext
{
    public function key(): string { return 'rental_disclosure'; }

    public function label(): string { return 'Landlord defect disclosure'; }

    public function documentContext(Template $t): string { return 'rental'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return $txn === 'rentals' && (self::slugIn($slug, ['disclosure', 'condition_report']) || self::nameHas($t, ['disclosure', 'defect']));
    }

    public function parties(): array
    {
        return [
            'landlord' => ['label' => 'Landlord (lessor)', 'name' => self::person('landlord')],
            'tenant' => ['label' => 'Tenant (lessee)', 'name' => self::person('tenant')],
            'agent' => ['label' => 'Agent', 'name' => self::person('agent')],
        ];
    }

    public function vars(): array
    {
        return self::rentalNames() + [
            'erf_size_sqm' => '650',
            'building_size_sqm' => '180',
            'garage_size_sqm' => '36',
            'monthly_rates' => 'R 1 450.00',
            'monthly_levies' => 'R 1 900.00',
            'specific_defects_and_owner_rectification' => 'Hairline crack in the lounge wall; the owner will repair it before the lease starts.',
            'fixtures_details_and_condition' => 'Stove, extractor fan and built-in cupboards, all in good working order.',
            'fixtures_specifically_included' => 'Stove, extractor fan, curtain rails.',
        ];
    }

    public function patterns(): array
    {
        return ['/(defect|rectif)/i' => 'None known to the landlord.', '/(fixture|included|condition)/i' => 'In good working order.', '/(sqm|size)/i' => '650'];
    }
}
