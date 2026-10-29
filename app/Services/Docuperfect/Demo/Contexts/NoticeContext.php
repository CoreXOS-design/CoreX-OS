<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** A rental notice (breach, non-renewal, termination): sent by the agent or landlord to the tenant. */
final class NoticeContext extends AbstractDemoContext
{
    public function key(): string { return 'notice'; }

    public function label(): string { return 'Rental notice'; }

    public function documentContext(Template $t): string { return 'rental'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return self::slugIn($slug, ['notice']) || self::nameHas($t, ['notice']);
    }

    public function parties(): array
    {
        return ['landlord' => ['label' => 'Landlord (lessor)', 'name' => self::person('landlord')], 'tenant' => ['label' => 'Tenant (lessee)', 'name' => self::person('tenant')], 'agent' => ['label' => 'Agent', 'name' => self::person('agent')]];
    }

    public function vars(): array
    {
        return [
            'notice_date' => date('j F Y'),
            'vacate_date' => date('j F Y', strtotime('+20 days')),
            'breach_description' => 'Rent for the current month has not been received by the due date.',
            'remedy_days' => '10',
            'arrears_amount' => 'R 12 500.00',
        ];
    }

    public function patterns(): array
    {
        return ['/(breach|remed)/i' => 'Rent for the current month has not been received by the due date.', '/(days)/i' => '10'];
    }
}
