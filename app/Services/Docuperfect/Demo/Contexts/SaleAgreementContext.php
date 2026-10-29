<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** An offer to purchase, a sale agreement, a deed of sale or alienation: seller, buyer and agent. */
final class SaleAgreementContext extends AbstractDemoContext
{
    public function key(): string { return 'sale_agreement'; }

    public function label(): string { return 'Offer to purchase or sale agreement'; }

    public function documentContext(Template $t): string { return 'sales'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return self::slugIn($slug, ['otp', 'sale_agreement', 'deed_of_alienation', 'deed_of_sale'])
            || self::nameHas($t, ['offer to purchase', 'otp', 'contract of sale', 'deed of sale', 'sale agreement']);
    }

    public function parties(): array
    {
        return [
            'seller' => ['label' => 'Seller', 'name' => self::person('seller')],
            'buyer' => ['label' => 'Buyer', 'name' => self::person('buyer')],
            'agent' => ['label' => 'Agent', 'name' => self::person('agent')],
        ];
    }

    public function vars(): array
    {
        return [
            'purchase_price' => 'R 1 850 000.00',
            'price' => 'R 1 850 000.00',
            'deposit_amount' => 'R 185 000.00',
            'bond_amount' => 'R 1 665 000.00',
            'occupation_date' => date('j F Y', strtotime('+2 months')),
            'transfer_date' => date('j F Y', strtotime('+3 months')),
            'bond_grant_date' => date('j F Y', strtotime('+30 days')),
            'offer_expiry' => date('j F Y', strtotime('+7 days')) . ' at 17:00',
            'conveyancer' => 'Sample & Partners Attorneys',
            'seller_full' => self::person('seller'),
            'buyer_full' => self::person('buyer'),
            'purchaser_name' => self::person('buyer'),
            'property_erf_number' => 'Erf 12345',
        ];
    }

    public function patterns(): array
    {
        return ['/(purchaser|buyer)/i' => self::person('buyer'), '/(conveyancer|attorney)/i' => 'Sample & Partners Attorneys', '/(bond|loan)/i' => 'R 1 665 000.00'];
    }
}
