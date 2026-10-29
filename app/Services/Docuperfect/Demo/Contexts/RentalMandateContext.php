<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** A mandate to let / rental marketing permission: landlord and agent sign. */
final class RentalMandateContext extends AbstractDemoContext
{
    public function key(): string { return 'rental_mandate'; }

    public function label(): string { return 'Mandate to let or rental marketing permission'; }

    public function documentContext(Template $t): string { return 'rental'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return $txn === 'rentals' && (self::slugIn($slug, ['mandate', 'marketing_permission', 'mandate_extension']) || self::nameHas($t, ['mandate', 'marketing', 'letting']));
    }

    public function parties(): array
    {
        return ['landlord' => ['label' => 'Landlord (lessor)', 'name' => self::person('landlord')], 'agent' => ['label' => 'Agent', 'name' => self::person('agent')]];
    }

    public function vars(): array
    {
        return self::rentalNames() + [
            'lessor_full' => self::person('landlord'),
            'property_town' => 'Cape Town',
            'property_address_suburb' => 'Table View',
            'mandate_start_date' => date('j F Y'),
            'property_commission_percent' => '8%',
            'agents_fee_including_vat' => 'R 1 150.00',
            'nett_amount_to_lessor' => 'R 11 350.00',
            'account_holder_name' => self::person('landlord'),
            'account_holder_bank' => 'Sample Bank',
            'account_number' => '1234567890',
            'account_branch_and_code' => 'Table View, 470010',
            'deal_amount' => 'R 12 500.00',
        ];
    }

    public function patterns(): array
    {
        return ['/(account_holder|banking\.account_holder)/i' => self::person('landlord'), '/(bank_name|account_holder_bank)/i' => 'Sample Bank', '/(commission|fee)/i' => 'R 1 000.00'];
    }
}
