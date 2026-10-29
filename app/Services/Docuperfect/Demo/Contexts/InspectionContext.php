<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** Inspection / inventory reports and lists: the agent and the tenant (and landlord) sign what was found. */
final class InspectionContext extends AbstractDemoContext
{
    public function key(): string { return 'inspection'; }

    public function label(): string { return 'Inspection or inventory report'; }

    public function documentContext(Template $t): string { return 'rental'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return self::slugIn($slug, ['inspection_report', 'inventory_list', 'inventory_exclusion_list']) || self::nameHas($t, ['inspection', 'inventory']);
    }

    public function parties(): array
    {
        return ['tenant' => ['label' => 'Tenant (lessee)', 'name' => self::person('tenant')], 'landlord' => ['label' => 'Landlord (lessor)', 'name' => self::person('landlord')], 'agent' => ['label' => 'Agent', 'name' => self::person('agent')]];
    }

    public function vars(): array
    {
        return [
            'inspection_date' => date('j F Y'),
            'inspection_type' => 'Move-in inspection',
            'room_condition' => 'Good, no marks',
            'item_description' => 'Three-seater couch, grey fabric',
            'item_condition' => 'Good',
            'meter_reading_electricity' => '12 345',
            'meter_reading_water' => '678',
        ];
    }

    public function patterns(): array
    {
        return ['/(reading)/i' => '12 345', '/(condition)/i' => 'Good, no marks', '/(item|room|description)/i' => 'Three-seater couch, grey fabric'];
    }
}
