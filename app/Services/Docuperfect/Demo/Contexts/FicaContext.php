<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** FICA / know-your-client forms for a natural person, company, trust or partnership, and the identity-type documents. */
final class FicaContext extends AbstractDemoContext
{
    public function key(): string { return 'fica'; }

    public function label(): string { return 'FICA (know your client)'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return self::slugIn($slug, ['fica', 'ids', 'por', 'tax_clearance', 'company_registration', 'trust_deed']) || self::nameHas($t, ['fica']);
    }

    public function parties(): array
    {
        return ['client' => ['label' => 'Client', 'name' => self::person('seller')], 'agent' => ['label' => 'Agent', 'name' => self::person('agent')]];
    }

    public function vars(): array
    {
        return [
            'company_name' => 'Sample Holdings (Pty) Ltd',
            'company_registration_number' => '2015/123456/07',
            'trust_name' => 'The Sample Family Trust',
            'trust_number' => 'IT 1234/2015',
            'vat_number' => '4123456789',
            'income_tax_number' => '1234567890',
            'source_of_funds' => 'Salary and savings',
        ];
    }

    public function patterns(): array
    {
        return ['/(company|trust|partnership)_?(name)?$/i' => 'Sample Holdings (Pty) Ltd', '/(registration|reg_no)/i' => '2015/123456/07', '/(tax|vat)/i' => '4123456789', '/(source|funds|occupation|employer)/i' => 'Salary and savings'];
    }
}
