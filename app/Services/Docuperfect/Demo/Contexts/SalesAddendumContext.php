<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** An addendum to a sale (conditions, finance, occupation, furniture, certificates of compliance): seller, buyer, agent. */
final class SalesAddendumContext extends AbstractDemoContext
{
    public function key(): string { return 'sales_addendum'; }

    public function label(): string { return 'Addendum to a sale'; }

    public function documentContext(Template $t): string { return 'sales'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return $txn !== 'rentals' && (self::slugIn($slug, ['addendum', 'coc', 'coc_request']) || self::nameHas($t, ['addendum']));
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
        $when = date('j F Y', strtotime('+21 days'));

        return [
            'coc_electrical_when' => 'Seller, before transfer, by ' . $when,
            'coc_plumbing_when' => 'Seller, before transfer, by ' . $when,
            'coc_gas_when' => 'Seller, before transfer, by ' . $when,
            'coc_entomology_when' => 'Seller, before transfer, by ' . $when,
            'coc_electric_fence_when' => 'Seller, before transfer, by ' . $when,
            'coc_beetle_when' => 'Seller, before transfer, by ' . $when,
        ];
    }

    public function patterns(): array
    {
        return ['/^coc_.*_when$/i' => 'Seller, before transfer', '/(furniture|movables|items)/i' => 'Stove, fitted wardrobes and curtain rails'];
    }
}
