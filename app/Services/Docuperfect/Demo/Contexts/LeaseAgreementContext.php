<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** A residential or commercial lease agreement, and a renewal of one: landlord, tenant, agent. */
final class LeaseAgreementContext extends AbstractDemoContext
{
    public function key(): string { return 'lease_agreement'; }

    public function label(): string { return 'Lease agreement or lease renewal'; }

    public function documentContext(Template $t): string { return 'rental'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return self::slugIn($slug, ['lease_agreement', 'rental_agreement'])
            || ($txn === 'rentals' && self::nameHas($t, ['lease', 'renewal', 'addendum']))
            || self::nameHas($t, ['lease agreement', 'lease renewal']);
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
        return self::rentalNames() + self::leaseSource() + [
            'rent_due_date' => '1st of every month',
            'annual_escalation' => '8%',
            'escalation' => '8%',
            'deposit_held_by' => 'The managing agent, in trust',
            'rental_managed_by' => 'Sample Agency',
            'agency_collection_fee' => 'R 1 500.00',
            'lease_fee' => 'R 1 500.00',
            'basic_charge_and_vat' => 'R 350.00 including VAT',
            'electricity' => 'Prepaid, in the tenant\'s name',
            'water' => 'Per municipal reading',
            'security' => 'Shared access control',
            'garden' => 'Tenant maintains',
            'wifi' => 'Not included',
            'pets' => 'Not allowed without written consent',
            'notice_period' => '20 business days',
        ];
    }

    public function patterns(): array
    {
        return ['/(electricity|water|refuse|sewer)/i' => 'Per reading', '/(garden|pool|wifi|pets?|security|parking|furnished)/i' => 'Not included'];
    }
}
