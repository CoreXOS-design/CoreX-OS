<?php

namespace App\Services\Rentals\TakeOnImport;

/**
 * .ai/specs/rental-takeon-import.md §3 — the single source of truth for the
 * take-on template's column order. The template generator and the row
 * parser both read this list so the two can never drift apart — the
 * parser reads columns BY POSITION, so this order is load-bearing.
 *
 * Landing 1 only: no saved/user-configurable column mapping (that is
 * Landing 2). This is deliberately the one and only shape the parser
 * understands, the same way importer.md §1 lets the P24 parser be strict
 * because P24's own export shape never changes.
 */
class RentalTakeOnFieldSchema
{
    public const PROPERTY_TYPE_OPTIONS = ['House', 'Apartment/Flat', 'Townhouse', 'Duplex', 'Vacant Land', 'Commercial', 'Other'];
    public const LEASE_TYPE_FIXED = 'Fixed term';
    public const LEASE_TYPE_MONTH_TO_MONTH = 'Month-to-month';
    public const LEASE_TYPE_OPTIONS = [self::LEASE_TYPE_FIXED, self::LEASE_TYPE_MONTH_TO_MONTH];

    /**
     * Ordered field definitions. 'type' drives both validation at parse
     * time and the Excel column formatting/dropdown at template-generation
     * time: string | date | number | dropdown:property_type | dropdown:lease_type | dropdown:branch.
     *
     * @return array<int, array{key: string, label: string, required: bool, type: string}>
     */
    public static function fields(): array
    {
        $fields = [
            ['key' => 'street_number', 'label' => 'Street number', 'required' => false, 'type' => 'string'],
            ['key' => 'street_name', 'label' => 'Street name', 'required' => true, 'type' => 'string'],
            ['key' => 'unit_complex_name', 'label' => 'Unit / complex name', 'required' => false, 'type' => 'string'],
            ['key' => 'suburb', 'label' => 'Suburb', 'required' => true, 'type' => 'string'],
            ['key' => 'erf_number', 'label' => 'Erf number', 'required' => false, 'type' => 'string'],
            ['key' => 'property_type', 'label' => 'Property type', 'required' => false, 'type' => 'dropdown:property_type'],
        ];

        foreach ([1, 2] as $n) {
            $fields[] = ['key' => "landlord{$n}_name", 'label' => "Landlord {$n} — name", 'required' => $n === 1, 'type' => 'string'];
            $fields[] = ['key' => "landlord{$n}_id_or_reg", 'label' => "Landlord {$n} — ID or company reg no", 'required' => false, 'type' => 'string'];
            $fields[] = ['key' => "landlord{$n}_email", 'label' => "Landlord {$n} — email", 'required' => false, 'type' => 'string'];
            $fields[] = ['key' => "landlord{$n}_phone", 'label' => "Landlord {$n} — phone", 'required' => false, 'type' => 'string'];
        }

        foreach ([1, 2, 3, 4] as $n) {
            $fields[] = ['key' => "tenant{$n}_name", 'label' => "Tenant {$n} — name", 'required' => $n === 1, 'type' => 'string'];
            $fields[] = ['key' => "tenant{$n}_id_number", 'label' => "Tenant {$n} — ID number", 'required' => false, 'type' => 'string'];
            $fields[] = ['key' => "tenant{$n}_email", 'label' => "Tenant {$n} — email", 'required' => false, 'type' => 'string'];
            $fields[] = ['key' => "tenant{$n}_phone", 'label' => "Tenant {$n} — phone", 'required' => false, 'type' => 'string'];
        }

        $fields = array_merge($fields, [
            ['key' => 'lease_start_date', 'label' => 'Lease start date', 'required' => true, 'type' => 'date'],
            ['key' => 'lease_end_date', 'label' => 'Lease end date', 'required' => false, 'type' => 'date'],
            ['key' => 'lease_type', 'label' => 'Lease type', 'required' => true, 'type' => 'dropdown:lease_type'],
            ['key' => 'monthly_rental_amount', 'label' => 'Monthly rental amount', 'required' => true, 'type' => 'number'],
            ['key' => 'escalation_percent', 'label' => 'Escalation %', 'required' => false, 'type' => 'number'],
            ['key' => 'next_escalation_date', 'label' => 'Next escalation date', 'required' => false, 'type' => 'date'],
            ['key' => 'deposit_held', 'label' => 'Deposit held', 'required' => false, 'type' => 'number'],
            ['key' => 'arrears_opening_balance', 'label' => 'Arrears / opening balance', 'required' => false, 'type' => 'number'],
            ['key' => 'management_fee_percent', 'label' => 'Management fee %', 'required' => false, 'type' => 'number'],
            ['key' => 'management_fee_amount', 'label' => 'Management fee amount', 'required' => false, 'type' => 'number'],
            ['key' => 'last_inspection_date', 'label' => 'Last inspection date', 'required' => false, 'type' => 'date'],
            ['key' => 'agent_email', 'label' => 'Agent email', 'required' => false, 'type' => 'string'],
            ['key' => 'branch', 'label' => 'Branch', 'required' => false, 'type' => 'dropdown:branch'],
            ['key' => 'notes', 'label' => 'Notes', 'required' => false, 'type' => 'string'],
        ]);

        return $fields;
    }

    public static function keys(): array
    {
        return array_column(self::fields(), 'key');
    }

    public static function requiredKeys(): array
    {
        return array_column(array_filter(self::fields(), fn (array $f) => $f['required']), 'key');
    }

    public static function landlordNumbers(): array
    {
        return [1, 2];
    }

    public static function tenantNumbers(): array
    {
        return [1, 2, 3, 4];
    }
}
