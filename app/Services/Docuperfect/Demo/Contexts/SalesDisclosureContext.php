<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** The seller's mandatory disclosure / property condition report: the seller discloses, the buyer acknowledges, the agent witnesses. */
final class SalesDisclosureContext extends AbstractDemoContext
{
    public function key(): string { return 'sales_disclosure'; }

    public function label(): string { return 'Seller disclosure or property condition report'; }

    public function documentContext(Template $t): string { return 'sales'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return $txn !== 'rentals' && (self::slugIn($slug, ['disclosure', 'condition_report']) || self::nameHas($t, ['disclosure', 'condition report']));
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
        return self::salesNames() + [
            'property_full_address' => '12 Sample Street, Table View, Cape Town, 7441',
            'property_address_suburb' => 'Table View',
            'seller_full' => self::person('seller'),
            'buyer_full' => self::person('buyer'),
            'defects_details' => 'None known to the seller.',
            'roof_condition' => 'Good, no known leaks.',
            'structural_defects' => 'None known.',
        ];
    }

    public function patterns(): array
    {
        return ['/(defect|leak|damp|crack|repair)/i' => 'None known to the seller.', '/(condition)/i' => 'Good'];
    }
}
