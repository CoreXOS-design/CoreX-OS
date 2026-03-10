<?php

namespace App\Services;

use App\Models\Docuperfect\Template;
use App\Models\User;

class WebTemplateDataService
{
    /**
     * Check if a template's fields_json has a field with the given field_name.
     */
    public function hasField(int $templateId, string $fieldName): bool
    {
        $template = Template::find($templateId);
        if (!$template) return false;
        foreach ($template->fields_json ?? [] as $f) {
            if (($f['field_name'] ?? '') === $fieldName) return true;
        }
        return false;
    }

    /**
     * Build a contact name, optionally appending ID number if the template
     * does not have a dedicated id_number field for this party.
     */
    private function buildNameWithId(array $contact, bool $hasDedicatedIdField): string
    {
        $name = $contact['name'] ?? '';
        if (!$hasDedicatedIdField && !empty($contact['id_number'])) {
            $name = trim($name . ' ' . $contact['id_number']);
        }
        return $name;
    }

    /**
     * Resolve blade variables for a web template from wizard step data.
     *
     * Mirrors the data resolution logic in ESignWizardController::autoFillFields
     * but outputs a flat associative array for blade templates instead of
     * populating fields_json entries.
     */
    public function resolve(int $templateId, array $stepData, ?User $agent = null): array
    {
        $property   = $stepData['property'] ?? [];
        $recipients = $stepData['recipients']['recipients'] ?? [];
        $details    = $stepData['details'] ?? [];
        $agent      = $agent ?? auth()->user();

        // Build contact lookup by wizard role (first + second per role for co-owners)
        $contactsByRole = [];
        $secondContactByRole = [];
        foreach ($recipients as $r) {
            $role = strtolower($r['role'] ?? '');
            if (!$role) continue;
            if (!isset($contactsByRole[$role])) {
                $contactsByRole[$role] = $r;
            } elseif (!isset($secondContactByRole[$role])) {
                $secondContactByRole[$role] = $r;
            }
        }

        // Resolve lessor (wizard role: landlord)
        $lessor = $contactsByRole['landlord'] ?? $contactsByRole['lessor'] ?? [];
        $lessor2 = $secondContactByRole['landlord'] ?? $secondContactByRole['lessor'] ?? [];

        // Resolve lessee (wizard role: tenant)
        $lessee = $contactsByRole['tenant'] ?? $contactsByRole['lessee'] ?? [];
        $lessee2 = $secondContactByRole['tenant'] ?? $secondContactByRole['lessee'] ?? [];

        // Resolve seller/buyer
        $seller = $contactsByRole['seller'] ?? [];
        $buyer  = $contactsByRole['buyer'] ?? [];

        // Check which templates have dedicated ID fields
        $hasLessorId = $this->hasField($templateId, 'lessor_id_number')
                    || $this->hasField($templateId, 'lessor_id');
        $hasLesseeId = $this->hasField($templateId, 'lessee_id_number')
                    || $this->hasField($templateId, 'lessee_id')
                    || $this->hasField($templateId, 'id_number');
        $hasSellerId = $this->hasField($templateId, 'seller_id_number');
        $hasBuyerId  = $this->hasField($templateId, 'buyer_id_number');

        // Build smart names (include ID inline if no dedicated ID field)
        $lessorName  = $this->buildNameWithId($lessor, $hasLessorId);
        $lessor2Name = $this->buildNameWithId($lessor2, $hasLessorId);
        $lesseeName  = $this->buildNameWithId($lessee, $hasLesseeId);
        $lessee2Name = $this->buildNameWithId($lessee2, $hasLesseeId);
        $sellerName  = $this->buildNameWithId($seller, $hasSellerId);
        $buyerName   = $this->buildNameWithId($buyer, $hasBuyerId);

        // Clean names for signature blocks (never include ID number)
        $lessorSigName  = $lessor['name'] ?? '';
        $lessor2SigName = $lessor2['name'] ?? '';
        $lesseeSigName  = $lessee['name'] ?? '';
        $lessee2SigName = $lessee2['name'] ?? '';
        $sellerSigName  = $seller['name'] ?? '';
        $buyerSigName   = $buyer['name'] ?? '';

        // Property values
        $address    = $property['address'] ?? $property['title'] ?? '';
        $suburb     = $property['suburb'] ?? '';
        $leaseStart = $details['lease_start'] ?? '';
        $leaseEnd   = $details['lease_end'] ?? '';
        $rental     = $details['monthly_rental'] ?? $property['rental_amount'] ?? '';
        $deposit    = $details['deposit'] ?? $property['deposit_amount'] ?? '';

        // Compute derived financial values
        $commission = $details['commission'] ?? $details['commission_percent'] ?? '';
        $commissionAmount = ($rental && $commission) ? round((float) $rental * (float) $commission / 100, 2) : '';
        $vatAmount = $commissionAmount ? round((float) $commissionAmount * 0.15, 2) : '';
        $serviceFee = $commissionAmount ? round((float) $commissionAmount + $vatAmount, 2) : '';
        $letsAssist = $details['lets_assist'] ?? '';
        $netToOwner = $rental ? round((float) $rental - ($serviceFee ?: 0) - ($letsAssist ?: 0), 2) : '';
        $grossRental = $rental;

        // Formatted lease dates
        $leaseStartFormatted = $leaseStart ? date('j F Y', strtotime($leaseStart)) : '';
        $leaseEndFormatted = $leaseEnd ? date('j F Y', strtotime($leaseEnd)) : '';

        // Build initials parties based on which contacts are present
        $initialsParties = [];
        if (!empty($lessor['name'] ?? '')) $initialsParties[] = 'Owner';
        if (!empty($lessee['name'] ?? '')) $initialsParties[] = 'Tenant';
        if ($agent) $initialsParties[] = 'Agent';

        return [
            // Lessor / Landlord
            'lessor_name'           => $lessorName,
            'lessor_id_number'      => $lessor['id_number'] ?? '',
            'lessor_email'          => $lessor['email'] ?? '',
            'lessor_cell'           => $lessor['cell'] ?? $lessor['phone'] ?? '',
            'lessor_tel'            => $lessor['cell'] ?? $lessor['phone'] ?? '',
            'lessor_address'        => $lessor['address'] ?? '',
            'lessor_bank_name'      => $lessor['bank_name'] ?? '',
            'lessor_bank_account_name'   => $lessor['bank_account_name'] ?? '',
            'lessor_bank_account_number' => $lessor['bank_account_number'] ?? '',
            'lessor_bank_branch_name'    => $lessor['bank_branch_name'] ?? '',
            // Lessor aliases
            'owner_names'           => $lessorName,
            'lessor_name_2'         => $lessor2Name,
            'lessor_id'             => $lessor['id_number'] ?? '',
            'lessor1_address'       => $lessor['address'] ?? '',
            'lessor1_tel'           => $lessor['cell'] ?? $lessor['phone'] ?? '',
            'lessor1_email'         => $lessor['email'] ?? '',
            'lessor2_address'       => $lessor2['address'] ?? '',
            'lessor2_tel'           => $lessor2['cell'] ?? $lessor2['phone'] ?? '',
            'lessor2_email'         => $lessor2['email'] ?? '',

            // Lessee / Tenant
            'lessee_name'           => $lesseeName,
            'lessee_id_number'      => $lessee['id_number'] ?? '',
            'lessee_email'          => $lessee['email'] ?? '',
            'lessee_cell'           => $lessee['cell'] ?? $lessee['phone'] ?? '',
            'lessee_tel'            => $lessee['cell'] ?? $lessee['phone'] ?? '',
            'lessee_address'        => $lessee['address'] ?? '',
            // Lessee / Tenant aliases (rental application)
            'full_name'             => $lesseeName,
            'id_number'             => $lessee['id_number'] ?? '',
            'lessee_id'             => $lessee['id_number'] ?? '',
            'lessee_name_2'         => $lessee2Name,
            'marital_status'        => '',
            'spouse_name'           => '',
            'spouse_id'             => '',
            'citizenship'           => '',
            'current_address_1'     => $lessee['address'] ?? '',
            'current_address_2'     => '',
            'email_address'         => $lessee['email'] ?? '',
            'cell_number'           => $lessee['cell'] ?? $lessee['phone'] ?? '',
            'work_number'           => '',
            'contact_person_name'   => '',
            'contact_person_cell'   => '',
            'contact_person_work'   => '',
            'current_landlord_name' => '',
            'current_landlord_tel'  => '',
            'current_rental'        => '',
            'rental_from'           => '',
            'rental_to'             => '',
            'employer_name'         => '',
            'position'              => '',
            'employer_address'      => '',
            'employer_tel'          => '',
            'monthly_salary'        => '',
            'occupation_date'       => $leaseStart,
            'rental_terms'          => '',
            'special_conditions_1'  => '',
            'special_conditions_2'  => '',
            'special_conditions_3'  => '',
            'adults'                => '',
            'children'              => '',
            'other_persons'         => '',
            'business_type'         => '',
            'pets_1'                => '',
            'pets_2'                => '',

            // Seller
            'seller_name'           => $sellerName,
            'seller_id_number'      => $seller['id_number'] ?? '',
            'seller_address'        => $seller['address'] ?? '',

            // Buyer
            'buyer_name'            => $buyerName,
            'buyer_id_number'       => $buyer['id_number'] ?? '',
            'buyer_address'         => $buyer['address'] ?? '',

            // Property — address always includes suburb when available
            'property_address'      => trim("{$address}, {$suburb}", ', '),
            'property_suburb'       => $suburb,
            'property_full_address' => trim("{$address}, {$suburb}", ', '),
            'erf_no'                => $property['erf'] ?? $property['erf_number'] ?? $property['property_number'] ?? '',
            'unit_no'               => $property['unit_number'] ?? '',
            'complex_name'          => $property['complex_name'] ?? '',
            'district'              => $property['district'] ?? 'Ray Nkonyeni',
            'property_type'         => $property['property_type'] ?? '',
            'property_description'  => $property['description'] ?? '',
            // Property aliases
            'street_address'        => trim("{$address}, {$suburb}", ', '),
            'street'                => $property['street'] ?? $address,
            'township'              => $suburb,
            'erf_unit_no'           => $property['erf'] ?? $property['erf_number'] ?? '',

            // Financial
            'monthly_rental'        => $rental,
            'rental_amount'         => $rental,
            'rental_amount_words'   => $rental ? $this->numberToWords((int) $rental) : '',
            'rental_in_words'       => $rental ? $this->numberToWords((int) $rental) : '',
            'deposit_amount'        => $deposit,
            'deposit_amount_words'  => $deposit ? $this->numberToWords((int) $deposit) : '',
            'commission_percent'    => $commission,
            'commission_amount'     => $commissionAmount,
            'marketing_fee'         => $details['marketing_fee'] ?? '',
            'marketing_agent'       => $agent->name ?? '',
            'price'                 => $details['price'] ?? $property['price'] ?? '',
            'price_in_words'        => !empty($details['price']) ? $this->numberToWords((int) $details['price']) : '',
            // Lease escalation
            'escalation_percent'    => $details['escalation_percent'] ?? $details['escalation'] ?? '',
            'escalation_in_words'   => '',
            'escalation_month'      => '',
            // Lease minimum term
            'min_term_day'          => '',
            'min_term_month'        => '',
            'min_term_year'         => '',
            'renewal_months'        => '',
            // Utilities
            'electricity_settlement'=> '',
            'electricity_deposit'   => '',
            // Financial summaries
            'total_rental'          => $rental,
            'gross_rental'          => $grossRental,
            'vat_amount'            => $vatAmount,
            'service_fee'           => $serviceFee,
            'lets_assist'           => $letsAssist,
            'net_to_lessor'         => $netToOwner,
            'net_to_owner'          => $netToOwner,
            'agent_commission'      => $commissionAmount,

            // Lease dates
            'lease_start'           => $leaseStart,
            'lease_end'             => $leaseEnd,
            'lease_start_formatted' => $leaseStartFormatted,
            'lease_end_formatted'   => $leaseEndFormatted,
            'lease_start_day'       => $leaseStart ? (int) date('d', strtotime($leaseStart)) : '',
            'lease_start_month'     => $leaseStart ? date('F', strtotime($leaseStart)) : '',
            'lease_start_year'      => $leaseStart ? date('Y', strtotime($leaseStart)) : '',
            'lease_end_day'         => $leaseEnd ? (int) date('d', strtotime($leaseEnd)) : '',
            'lease_end_month'       => $leaseEnd ? date('F', strtotime($leaseEnd)) : '',
            'lease_end_year'        => $leaseEnd ? date('Y', strtotime($leaseEnd)) : '',

            // Signing context — left empty; populated at signing time by SigningController
            'signed_at_location'    => '',
            'signed_day'            => '',
            'signed_month'          => '',
            'signed_year'           => '',
            'signed_time'           => '',
            'signed_ampm'           => '',

            // Per-party signing fields (empty defaults, filled at signing time)
            'lessor_signed_at'      => $suburb ?: $address,
            'lessor_signed_day'     => '',
            'lessor_signed_month'   => '',
            'lessor_signed_year'    => '',
            'lessor_signed_time'    => '',
            'lessor_signed_date'    => '',
            'lessor_signature'      => '',
            'lessee_signed_at'      => '',
            'lessee_signed_day'     => '',
            'lessee_signed_month'   => '',
            'lessee_signed_year'    => '',
            'lessee_signed_time'    => '',
            'lessee_signed_date'    => '',
            'lessee_signature'      => '',
            'agent_signed_at'       => $suburb ?: $address,
            'agent_signed_day'      => '',
            'agent_signed_month'    => '',
            'agent_signed_year'     => '',
            'agent_signed_time'     => '',
            'agent_signed_date'     => '',
            'tenant_signed_at'      => '',
            'tenant_signed_date'    => '',
            'tenant_signature'      => '',
            'practitioner_signed_at'   => $suburb ?: $address,
            'practitioner_signed_date' => '',
            'practitioner_signature'   => '',
            'co_signature'          => '',
            'cancellation_signature'=> '',
            'signature_date_1'      => '',
            'signature_date_2'      => '',
            // Addendum dates
            'addendum_lessor_date'  => '',
            'addendum_tenant_date'  => '',
            'addendum_agent_date'   => '',
            'addendum_lessee_date'  => '',

            // Agent
            'agent_name'            => $agent->name ?? '',
            'agent_email'           => $agent->email ?? '',
            'agent_cell'            => $agent->cell ?? $agent->phone ?? '',

            // Signature block names (clean — no ID number appended)
            'lessor_signature_name'   => $lessorSigName,
            'lessor_signature_name_2' => $lessor2SigName,
            'lessee_signature_name'   => $lesseeSigName,
            'lessee_signature_name_2' => $lessee2SigName,
            'seller_signature_name'   => $sellerSigName,
            'buyer_signature_name'    => $buyerSigName,
            'agent_signature_name'    => $agent->name ?? '',

            // Mandate-specific aliases (letting-mandate-v5)
            'account_holder'        => $lessor['bank_account_name'] ?? '',
            'bank_name'             => $lessor['bank_name'] ?? '',
            'account_number'        => $lessor['bank_account_number'] ?? '',
            'branch_name'           => $lessor['bank_branch_name'] ?? '',
            'branch_code'           => $lessor['bank_branch_code'] ?? '',
            'account_type'          => $lessor['bank_account_type'] ?? '',
            'owner_contact'         => $lessor['cell'] ?? $lessor['phone'] ?? '',
            'owner_email'           => $lessor['email'] ?? '',
            'mandate_day'           => $leaseEnd ? (int) date('d', strtotime($leaseEnd)) : '',
            'mandate_month'         => $leaseEnd ? date('F', strtotime($leaseEnd)) : '',
            'mandate_year'          => $leaseEnd ? date('y', strtotime($leaseEnd)) : '',

            // Mandatory disclosure — compliance certs
            'electrical_cert_date'  => '',
            'fence_cert_date'       => '',
            'gas_cert_date'         => '',
            'entomology_cert_date'  => '',
            // Marketing permission notes
            'other_notes_1'         => '',
            'other_notes_2'         => '',

            // Initials parties — used for initials on every non-signature page
            'initialsParties'       => $initialsParties,
        ];
    }

    private function numberToWords(int $number): string
    {
        if ($number === 0) return 'zero';

        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
                 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
                 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        $convert = function (int $n) use (&$convert, $ones, $tens): string {
            if ($n < 20) return $ones[$n];
            if ($n < 100) return $tens[(int)($n / 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
            if ($n < 1000) return $ones[(int)($n / 100)] . ' hundred' . ($n % 100 ? ' and ' . $convert($n % 100) : '');
            if ($n < 1000000) return $convert((int)($n / 1000)) . ' thousand' . ($n % 1000 ? ' ' . $convert($n % 1000) : '');
            return $convert((int)($n / 1000000)) . ' million' . ($n % 1000000 ? ' ' . $convert($n % 1000000) : '');
        };

        return ucfirst($convert($number));
    }
}
