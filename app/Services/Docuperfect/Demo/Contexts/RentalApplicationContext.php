<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** A rental application form: the applicant fills it in, the agent receives it. */
final class RentalApplicationContext extends AbstractDemoContext
{
    public function key(): string { return 'rental_application'; }

    public function label(): string { return 'Rental application'; }

    public function documentContext(Template $t): string { return 'rental'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return self::nameHas($t, ['rental application', 'application to rent', 'tenant application']);
    }

    public function parties(): array
    {
        return ['tenant' => ['label' => 'Applicant (prospective tenant)', 'name' => self::person('tenant')], 'agent' => ['label' => 'Agent', 'name' => self::person('agent')]];
    }

    public function vars(): array
    {
        return [
            'employer' => 'Sample Employer (Pty) Ltd',
            'employer_name' => 'Sample Employer (Pty) Ltd',
            'monthly_income' => 'R 38 000.00',
            'gross_income' => 'R 42 000.00',
            'occupants' => '2 adults',
            'current_address' => '5 Example Road, Bloubergstrand, Cape Town, 7441',
            'reason_for_leaving' => 'Moving closer to work',
            'move_in_date' => date('j F Y', strtotime('first day of next month')),
        ];
    }

    public function patterns(): array
    {
        return ['/(employer|company)/i' => 'Sample Employer (Pty) Ltd', '/(income|salary|earn)/i' => 'R 38 000.00', '/(reference|referee)/i' => 'Sample Referee, 082 555 0104'];
    }
}
