<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** A sole, open or dual mandate to sell (and its extension / price-reduction forms): the seller and the agent sign. */
final class SalesMandateContext extends AbstractDemoContext
{
    public function key(): string { return 'sales_mandate'; }

    public function label(): string { return 'Mandate to sell (sole, open or dual)'; }

    public function documentContext(Template $t): string { return 'sales'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return self::slugIn($slug, ['mandate', 'mandate_extension', 'mandate_price_reduction', 'marketing_permission'])
            || self::nameHas($t, ['authority to sell', 'mandate', 'oats', 'eats']);
    }

    public function parties(): array
    {
        return ['seller' => ['label' => 'Seller', 'name' => self::person('seller')], 'agent' => ['label' => 'Agent', 'name' => self::person('agent')]];
    }

    public function vars(): array
    {
        return self::salesNames() + [
            'seller_full' => self::person('seller'),
            'seller_address' => '12 Sample Street, Table View, Cape Town, 7441',
            'property_town' => 'Cape Town',
            'property_street' => '12 Sample Street',
            'property_address_suburb' => 'Table View',
            'property_full_address' => '12 Sample Street, Table View, Cape Town, 7441',
            'property_erf_number' => 'Erf 12345',
            'property_commission_percent' => '5%',
            'price' => 'R 1 850 000.00',
            'price_in_words' => 'One million eight hundred and fifty thousand rand',
            'mandate_expiry' => '31 December ' . date('Y', strtotime('+1 year')),
            'mandate_start_date' => date('j F Y'),
            'mandate_period_months' => '3',
        ];
    }

    public function patterns(): array
    {
        return ['/(commission|agents?_fee)/i' => '5% plus VAT', '/(in_words|amount_words)/i' => 'One million eight hundred and fifty thousand rand'];
    }
}
