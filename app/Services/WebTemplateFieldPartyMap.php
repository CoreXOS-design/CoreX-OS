<?php

namespace App\Services;

class WebTemplateFieldPartyMap
{
    /**
     * Maps blade variable names to the party responsible for filling them.
     *
     * Party roles use the wizard/signature-request convention:
     *   'landlord' (maps from lessor), 'tenant' (maps from lessee),
     *   'agent', 'buyer', 'seller'.
     *
     * 'system' = auto-filled by the system, not editable by any signer.
     */
    const PARTY_MAP = [
        'landlord' => [
            // Lessor identity
            'lessor_name',
            'lessor_id_number',
            'lessor_email',
            'lessor_cell',
            'lessor_address',
            // Lessor banking
            'lessor_bank_name',
            'lessor_bank_account_name',
            'lessor_bank_account_number',
            'lessor_bank_branch_name',
            // Template-specific aliases (letting-mandate-v5)
            'account_holder',
            'bank_name',
            'account_number',
            'branch_name',
            'owner_contact',
            'owner_email',
            // Aliases used across other templates
            'owner_names',
            'lessor_name_2',
            'lessor_id',
            'lessor1_address',
            'lessor1_tel',
            'lessor1_email',
            'lessor2_address',
            'lessor2_tel',
            'lessor2_email',
            // Mandatory disclosure — compliance certs
            'electrical_cert_date',
            'fence_cert_date',
            'gas_cert_date',
            'entomology_cert_date',
            // Marketing permission notes
            'other_notes_1',
            'other_notes_2',
        ],

        'tenant' => [
            'lessee_name',
            'lessee_id_number',
            'lessee_email',
            'lessee_cell',
            'lessee_address',
            // Rental application aliases
            'full_name',
            'id_number',
            'lessee_id',
            'lessee_name_2',
            'marital_status',
            'spouse_name',
            'spouse_id',
            'citizenship',
            'current_address_1',
            'current_address_2',
            'email_address',
            'cell_number',
            'work_number',
            'contact_person_name',
            'contact_person_cell',
            'contact_person_work',
            'current_landlord_name',
            'current_landlord_tel',
            'current_rental',
            'rental_from',
            'rental_to',
            'employer_name',
            'position',
            'employer_address',
            'employer_tel',
            'monthly_salary',
            'occupation_date',
            'rental_terms',
            'special_conditions_1',
            'special_conditions_2',
            'special_conditions_3',
            'adults',
            'children',
            'other_persons',
            // Commercial lease
            'business_type',
            // Pets
            'pets_1',
            'pets_2',
        ],

        'agent' => [
            'agent_name',
            'agent_email',
            'agent_cell',
            'commission_percent',
            'commission_amount',
            'marketing_fee',
            'marketing_agent',
            'rental_amount',
            'deposit_amount',
            'price',
            'lease_start',
            'lease_end',
            'property_address',
            'property_suburb',
            'erf_no',
            'unit_no',
            'complex_name',
            'district',
            'property_type',
            // Property aliases
            'street_address',
            'street',
            'township',
            'erf_unit_no',
            // Mandate dates
            'mandate_day',
            'mandate_month',
            'mandate_year',
            // Lease terms
            'escalation_percent',
            'escalation_in_words',
            'escalation_month',
            'min_term_day',
            'min_term_month',
            'min_term_year',
            'renewal_months',
            // Utilities
            'electricity_settlement',
            'electricity_deposit',
        ],

        'buyer' => [
            'buyer_name',
            'buyer_id_number',
            'buyer_address',
        ],

        'seller' => [
            'seller_name',
            'seller_id_number',
            'seller_address',
        ],

        'system' => [
            // Computed / derived values — not editable
            'rental_amount_words',
            'rental_in_words',
            'deposit_amount_words',
            'price_in_words',
            'property_full_address',
            // Lease date components (derived from lease_start / lease_end)
            'lease_start_day',
            'lease_start_month',
            'lease_start_year',
            'lease_end_day',
            'lease_end_month',
            'lease_end_year',
            // Signing context (auto-set at signing time)
            'signed_at_location',
            'signed_day',
            'signed_month',
            'signed_year',
            'signed_time',
            'signed_ampm',
            // Per-party signing fields (auto-filled at signing time)
            'lessor_signed_at',
            'lessor_signed_day',
            'lessor_signed_month',
            'lessor_signed_year',
            'lessor_signed_time',
            'lessor_signed_date',
            'lessor_signature',
            'lessee_signed_at',
            'lessee_signed_day',
            'lessee_signed_month',
            'lessee_signed_year',
            'lessee_signed_time',
            'lessee_signed_date',
            'lessee_signature',
            'agent_signed_at',
            'agent_signed_day',
            'agent_signed_month',
            'agent_signed_year',
            'agent_signed_time',
            'agent_signed_date',
            'tenant_signed_at',
            'tenant_signed_date',
            'tenant_signature',
            'practitioner_signed_at',
            'practitioner_signed_date',
            'practitioner_signature',
            'co_signature',
            'cancellation_signature',
            'signature_date_1',
            'signature_date_2',
            // Addendum dates
            'addendum_lessor_date',
            'addendum_tenant_date',
            'addendum_agent_date',
            'addendum_lessee_date',
            // Financial summaries (computed)
            'total_rental',
            'service_fee',
            'lets_assist',
            'net_to_lessor',
            'net_to_owner',
            'agent_commission',
            // Signature block names (clean name only, no ID)
            'lessor_signature_name',
            'lessor_signature_name_2',
            'lessee_signature_name',
            'lessee_signature_name_2',
            'seller_signature_name',
            'buyer_signature_name',
            'agent_signature_name',
        ],
    ];

    /**
     * Get the party responsible for a given field name.
     */
    public static function getPartyForField(string $fieldName): string
    {
        foreach (self::PARTY_MAP as $party => $fields) {
            if (in_array($fieldName, $fields, true)) {
                return $party;
            }
        }

        return 'any';
    }

    /**
     * Get all field names assigned to a given party role.
     *
     * Normalizes roles: 'lessor' → 'landlord', 'lessee' → 'tenant'.
     */
    public static function getFieldsForParty(string $partyRole): array
    {
        $aliases = ['lessor' => 'landlord', 'lessee' => 'tenant'];
        $normalized = $aliases[$partyRole] ?? $partyRole;

        return self::PARTY_MAP[$normalized] ?? [];
    }

    /**
     * Get editable field names for a signer's party role.
     * Includes the party's own fields only (not system or other parties').
     */
    public static function getEditableFields(string $partyRole): array
    {
        return self::getFieldsForParty($partyRole);
    }
}
