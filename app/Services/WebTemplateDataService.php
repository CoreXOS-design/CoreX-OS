<?php

namespace App\Services;

use App\Models\Docuperfect\Template;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
        $template = Template::find($templateId);

        // CDS templates: resolve fields from field_mappings, then merge with base data
        if ($template && $template->template_type === 'cds') {
            return $this->resolveCdsTemplate($template, $stepData, $agent);
        }

        $property   = $stepData['property'] ?? [];
        $recipients = $stepData['recipients']['recipients'] ?? [];
        $details    = $stepData['details'] ?? [];
        $agent      = $agent ?? auth()->user();

        // Build contact lookup by wizard role (first + second per role for co-owners)
        $contactsByRole = [];
        $secondContactByRole = [];
        $allContactsByRole = []; // all contacts per role, indexed from 0
        foreach ($recipients as $r) {
            $role = strtolower($r['role'] ?? '');
            if (!$role) continue;
            $allContactsByRole[$role][] = $r;
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

        // Resolve seller/buyer (first + second per role for co-owners)
        $seller = $contactsByRole['seller'] ?? [];
        $seller2 = $secondContactByRole['seller'] ?? [];
        $buyer  = $contactsByRole['buyer'] ?? [];
        $buyer2 = $secondContactByRole['buyer'] ?? [];

        // Check which templates have dedicated ID fields
        $hasLessorId = $this->hasField($templateId, 'lessor_id_number')
                    || $this->hasField($templateId, 'lessor_id');
        $hasLesseeId = $this->hasField($templateId, 'lessee_id_number')
                    || $this->hasField($templateId, 'lessee_id')
                    || $this->hasField($templateId, 'id_number');
        $hasSellerId = $this->hasField($templateId, 'seller_id_number');
        $hasBuyerId  = $this->hasField($templateId, 'buyer_id_number');

        // Clean names — never append ID number to name fields
        $lessorName  = trim(($lessor['first_name'] ?? '') . ' ' . ($lessor['last_name'] ?? '')) ?: ($lessor['name'] ?? '');
        $lessor2Name = trim(($lessor2['first_name'] ?? '') . ' ' . ($lessor2['last_name'] ?? '')) ?: ($lessor2['name'] ?? '');
        $lesseeName  = trim(($lessee['first_name'] ?? '') . ' ' . ($lessee['last_name'] ?? '')) ?: ($lessee['name'] ?? '');
        $lessee2Name = trim(($lessee2['first_name'] ?? '') . ' ' . ($lessee2['last_name'] ?? '')) ?: ($lessee2['name'] ?? '');
        $sellerName  = trim(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? '')) ?: ($seller['name'] ?? '');
        $seller2Name = trim(($seller2['first_name'] ?? '') . ' ' . ($seller2['last_name'] ?? '')) ?: ($seller2['name'] ?? '');
        $buyerName   = trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')) ?: ($buyer['name'] ?? '');
        $buyer2Name  = trim(($buyer2['first_name'] ?? '') . ' ' . ($buyer2['last_name'] ?? '')) ?: ($buyer2['name'] ?? '');

        // Property values
        $address    = $property['address'] ?? $property['title'] ?? '';
        $suburb     = $property['suburb'] ?? $property['town'] ?? $property['city'] ?? ''; // B1 — township falls back to town/city
        $leaseStart = $details['lease_start'] ?? '';
        $leaseEnd   = $details['lease_end'] ?? '';
        $rental     = $details['monthly_rental'] ?? $property['rental_amount'] ?? '';
        $deposit    = $details['deposit'] ?? $property['deposit_amount'] ?? '';

        // Sales fields
        $price = $details['price'] ?? $property['price'] ?? '';
        $mandateStart = $details['mandate_start'] ?? '';
        $mandateExpiry = $details['mandate_expiry'] ?? '';

        // Compute derived financial values
        $commission = $details['commission'] ?? $details['commission_percent'] ?? '';
        // Commission calculated from rental (rental context) or price (sales context)
        $commissionBase = ($rental && (float) $rental > 0) ? (float) $rental : (($price && (float) $price > 0) ? (float) $price : 0);
        $commissionAmount = ($commissionBase > 0 && $commission) ? round($commissionBase * (float) $commission / 100, 2) : '';
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
            'owner_names'           => $lessor2Name ? ($lessorName . ' & ' . $lessor2Name) : $lessorName,
            'lessor_name_2'         => $lessor2Name,
            'lessor_id'             => $lessor['id_number'] ?? '',
            'lessor1_address'       => $lessor['address'] ?? '',
            'lessor1_tel'           => $lessor['cell'] ?? $lessor['phone'] ?? '',
            'lessor1_email'         => $lessor['email'] ?? '',
            'lessor2_address'       => $lessor2['address'] ?? '',
            'lessor2_tel'           => $lessor2['cell'] ?? $lessor2['phone'] ?? '',
            'lessor2_email'         => $lessor2['email'] ?? '',
            'lessor2_id_number'     => $lessor2['id_number'] ?? '',
            'lessor2_name'          => $lessor2Name,
            'lessor_id_number_2'    => $lessor2['id_number'] ?? '',

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

            // Seller (first)
            'seller_name'           => $sellerName,
            'seller_first_name'     => $seller['first_name'] ?? '',
            'seller_last_name'      => $seller['last_name'] ?? '',
            'seller_id_number'      => $seller['id_number'] ?? '',
            'seller_address'        => $seller['address'] ?? '',
            'seller_email'          => $seller['email'] ?? '',
            'seller_cell'           => $seller['cell'] ?? $seller['phone'] ?? '',
            // Seller indexed (seller 1 = first seller, seller 2 = second seller)
            'seller_address_1'      => $seller['address'] ?? '',
            'seller_1_phone'        => $seller['cell'] ?? $seller['phone'] ?? '',
            'seller_1_email'        => $seller['email'] ?? '',
            'seller_address_2'      => $seller2['address'] ?? '',
            'seller_2_phone'        => $seller2['cell'] ?? $seller2['phone'] ?? '',
            'seller_2_email'        => $seller2['email'] ?? '',
            // Seller 2 (co-seller)
            'seller_name_2'         => $seller2Name,
            'seller_2_first_name'   => $seller2['first_name'] ?? '',
            'seller_2_last_name'    => $seller2['last_name'] ?? '',
            'seller_2_id_number'    => $seller2['id_number'] ?? '',
            // Seller 3 & 4 (from additional recipients if present)
            'seller_address_3'      => ($allContactsByRole['seller'][2] ?? [])['address'] ?? '',
            'seller_3_phone'        => ($allContactsByRole['seller'][2] ?? [])['cell'] ?? ($allContactsByRole['seller'][2] ?? [])['phone'] ?? '',
            'seller_3_email'        => ($allContactsByRole['seller'][2] ?? [])['email'] ?? '',
            'seller_address_4'      => ($allContactsByRole['seller'][3] ?? [])['address'] ?? '',
            'seller_4_phone'        => ($allContactsByRole['seller'][3] ?? [])['cell'] ?? ($allContactsByRole['seller'][3] ?? [])['phone'] ?? '',
            'seller_4_email'        => ($allContactsByRole['seller'][3] ?? [])['email'] ?? '',

            // Buyer (first)
            'buyer_name'            => $buyerName,
            'buyer_first_name'      => $buyer['first_name'] ?? '',
            'buyer_last_name'       => $buyer['last_name'] ?? '',
            'buyer_id_number'       => $buyer['id_number'] ?? '',
            'buyer_address'         => $buyer['address'] ?? '',

            // Property — address always includes suburb when available
            'property_address'      => trim("{$address}, {$suburb}", ', '),
            'property_suburb'       => $suburb,
            'property_full_address' => trim("{$address}, {$suburb}", ', '),
            'property_full'         => trim("{$address}, {$suburb}", ', '),
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
            // CDS property aliases
            'property_street'       => $address,
            'property_township'     => $suburb,
            'property_district'     => $property['district'] ?? 'Ray Nkonyeni',
            'property_erf_number'   => $property['erf'] ?? $property['erf_number'] ?? $property['property_number'] ?? '',
            'property_complex_name' => $property['complex_name'] ?? '',
            // CDS generic contact aliases (first non-agent: seller for sales, lessor for rental)
            'contact_full_names'    => $sellerName ?: $lessorName,
            'contact_address'       => ($seller['address'] ?? '') ?: ($lessor['address'] ?? ''),
            'contact_phone'         => ($seller['cell'] ?? $seller['phone'] ?? '') ?: ($lessor['cell'] ?? $lessor['phone'] ?? ''),
            'contact_email'         => ($seller['email'] ?? '') ?: ($lessor['email'] ?? ''),
            // CDS deal alias
            'deal_amount'           => $price,

            // Financial
            'monthly_rental'        => $rental,
            'rental_amount'         => $rental,
            'rental_amount_words'   => $rental ? $this->numberToWords($rental) : '',
            'rental_in_words'       => $rental ? $this->numberToWords($rental) : '',
            'deposit_amount'        => $deposit,
            'deposit_amount_words'  => $deposit ? $this->numberToWords($deposit) : '',
            'commission_percent'    => $commission,
            'commission_amount'     => $commissionAmount,
            'marketing_fee'         => $details['marketing_fee'] ?? '',
            'marketing_agent'       => $agent->name ?? '',
            'price'                 => $price,
            'price_in_words'        => $price ? $this->numberToWords($price) : '',
            'commission_incl_vat'   => $serviceFee,
            // Mandate dates (sales)
            'mandate_start'         => $mandateStart,
            'mandate_expiry'        => $mandateExpiry,
            'mandate_start_formatted' => $mandateStart ? date('j F Y', strtotime($mandateStart)) : '',
            'mandate_expiry_formatted' => $mandateExpiry ? date('j F Y', strtotime($mandateExpiry)) : '',
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
            'agent_email'           => $agent->outward_email ?? '', // AT-79 outward override
            'agent_cell'            => $agent->cell ?? $agent->phone ?? '',

            // Signature block names (clean — no ID number appended)
            'lessor_signature_name'   => $lessorName,
            'lessor_signature_name_2' => $lessor2Name,
            'lessee_signature_name'   => $lesseeName,
            'lessee_signature_name_2' => $lessee2Name,
            'seller_signature_name'   => $sellerName,
            'buyer_signature_name'    => $buyerName,
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

    /**
     * Resolve CDS template fields from field_mappings + named field keys.
     *
     * Each field_mapping entry has a namedFieldId linking to docuperfect_named_fields.
     * The named field's KEY (e.g. "contact.surname", "property.address", "deal.price_in_words")
     * tells the resolver exactly which data to pull. This mirrors how PDF templates resolve
     * via autoFillFields/resolveFieldValue in ESignWizardController.
     */
    private function resolveCdsTemplate(Template $template, array $stepData, ?User $agent): array
    {
        $agent = $agent ?? auth()->user();
        $property = $stepData['property'] ?? [];
        $recipients = $stepData['recipients']['recipients'] ?? [];
        $details = $stepData['details'] ?? [];

        // Build contact lookup by role (first contact per role)
        $contactsByRole = [];
        foreach ($recipients as $r) {
            $role = strtolower($r['role'] ?? '');
            if ($role && !isset($contactsByRole[$role])) {
                $contactsByRole[$role] = $r;
            }
        }

        // Role aliases: wizard uses landlord/tenant, named fields use lessor/lessee/seller/buyer
        $roleAliases = [
            'landlord' => 'lessor', 'tenant' => 'lessee',
            'lessor' => 'lessor', 'lessee' => 'lessee',
            'seller' => 'seller', 'buyer' => 'buyer',
        ];
        foreach ($roleAliases as $from => $to) {
            if (isset($contactsByRole[$from]) && !isset($contactsByRole[$to])) {
                $contactsByRole[$to] = $contactsByRole[$from];
            }
        }

        $lessor = $contactsByRole['landlord'] ?? $contactsByRole['lessor'] ?? [];
        $lessee = $contactsByRole['tenant'] ?? $contactsByRole['lessee'] ?? [];
        $seller = $contactsByRole['seller'] ?? [];
        $buyer = $contactsByRole['buyer'] ?? [];

        // Load all named fields referenced by this template's field_mappings
        $namedFieldIds = collect($template->field_mappings ?? [])
            ->pluck('namedFieldId')
            ->filter()
            ->unique()
            ->values();

        $namedFields = [];
        if ($namedFieldIds->isNotEmpty()) {
            $namedFields = DB::table('docuperfect_named_fields')
                ->whereIn('id', $namedFieldIds)
                ->get()
                ->keyBy('id');
        }

        $data = [];

        // Resolve each field from field_mappings using named field keys
        foreach ($template->field_mappings ?? [] as $field) {
            $fieldName = $field['field_name'] ?? '';

            $namedFieldId = $field['namedFieldId'] ?? null;
            $namedField = $namedFieldId ? ($namedFields[$namedFieldId] ?? null) : null;
            $source = $field['source'] ?? $field['sourceType'] ?? 'manual';

            // Derive the blade variable name:
            // 1. From field_name if present (and not a tag ID)
            // 2. From named field source properties (matches blade data-field attributes)
            // 3. From label as last resort
            $varName = '';
            if (!empty($fieldName) && !str_starts_with($fieldName, 'tag-')) {
                $varName = str_replace('.', '_', $fieldName);
                $varName = preg_replace('/[^a-zA-Z0-9_]/', '_', $varName);
            }

            // Derive from named field source properties to match blade data-field names
            if (empty($varName) && $namedField) {
                $varName = $this->deriveBladeName(
                    $namedField->source_type ?? $source,
                    $namedField->source_column ?? '',
                    $namedField->source_contact_type ?? $field['sourceContactType'] ?? ''
                );
            }

            // Fallback: derive from label
            if (empty($varName)) {
                $label = $field['label'] ?? $field['manualLabel'] ?? '';
                if (!empty($label)) {
                    $varName = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
                }
            }

            if (empty($varName)) continue;

            // Field groups: resolve member columns from ALL matching recipients,
            // formatted as "FirstName LastName (ID: xxx) and FirstName LastName (ID: xxx)"
            $mappingType = $field['mappingType'] ?? $field['mapping_type'] ?? '';
            if ($mappingType === 'field_group') {
                $fgId = $field['fieldGroupId'] ?? $field['field_group_id'] ?? null;
                if ($fgId) {
                    $data[$varName] = $this->resolveFieldGroupValue($fgId, $recipients);
                }
                continue;
            }

            // Manual/unlinked fields: leave empty for agent to fill
            if ($source === 'manual' && !$namedField) {
                $data[$varName] = '';
                continue;
            }

            $value = '';

            // If we have a named field, resolve it to its ACTUAL attribute.
            if ($namedField) {
                // AT-177 B2 (root fix, host-diagnosed 2026-07-17) — key off the FIELD-LEVEL binding
                // signals the CDS import writes reliably: typeKey (sf:contact_<role> / sf:property),
                // sourceType, sourceContactType — NOT the named field's source_type. The old code
                // preferred `$namedField->source_type`, which for these imports is not 'contact', so
                // the contact branch never fired; the empty result was then stripped by array_filter
                // below and the seller NAME bled through from base data. That is why three deploys
                // changed nothing — there were no labels for the label-keyword guard to match. The
                // ATTRIBUTE comes from the named field's source_column (the import stores it there,
                // parsed from the blade data-field "source_type.source_column"); label is last resort.
                $typeKey = (string) ($field['typeKey'] ?? '');
                $fSource = strtolower((string) ($field['sourceType'] ?? $namedField->source_type ?? $source));
                $fRole   = (string) ($field['sourceContactType'] ?? $namedField->source_contact_type ?? '');
                if ($fRole === '' && str_starts_with($typeKey, 'sf:contact_')) {
                    $fRole = ucfirst(substr($typeKey, strlen('sf:contact_')));
                }

                $isContact  = $fSource === 'contact'  || str_starts_with($typeKey, 'sf:contact_');
                $isProperty = $fSource === 'property' || $typeKey === 'sf:property';

                if ($isContact && $fRole !== '') {
                    $attr  = $this->attributeForNamedField($namedField, $field);
                    $value = $this->resolveContactColumnAllRecipients($fRole, $attr, $recipients);
                } elseif ($isProperty) {
                    // Property component fields (Complex / Number / Town / Street / District — the
                    // sf:property split) resolve their column here; B1 township fallback lives in
                    // resolvePropertyFromKey.
                    $attr  = $this->attributeForNamedField($namedField, $field);
                    $value = $this->resolvePropertyFromKey($attr, $property, $details);
                } else {
                    $value = $this->resolveByNamedFieldKey(
                        $namedField->key ?? '', $namedField->source_type ?? $source, $namedField->source_column ?? '', $fRole,
                        $property, $contactsByRole, $details, $agent
                    );
                }
            }

            // Fallback: source-based resolution (backward compat for mappings without named fields)
            if (($value === '' || $value === null) && !$namedField) {
                $value = $this->resolveBySource($source, $fieldName ?: $varName, $property, $contactsByRole, $details, $agent);
            }

            // B3 — a "… in words" spot renders the amount in WORDS, not the figure. The price/amount
            // resolves to a number in both spots; when the label says "in words" (Johan's num/alpha
            // rule) it runs through the rand converter. Same keyword-resolve-now pattern as B2.
            if ($this->labelWantsWords($field['label'] ?? $field['manualLabel'] ?? '')) {
                $numeric = preg_replace('/[^0-9.\-]/', '', (string) $value);
                if ($numeric !== '' && is_numeric($numeric)) {
                    $value = \App\Support\AmountInWords::rands($numeric);
                }
            }

            $data[$varName] = (string) ($value ?? '');
        }

        // Apply smart defaults based on property type
        $this->applySmartDefaults($data, (object) $property);

        // Also include the full standard data set for maximum blade compatibility
        // (signature blocks, initials, etc. reference standard variable names)
        $baseData = $this->resolveBase($stepData, $agent);

        // Add computed fields to base data (price_in_words, commission_amount, etc.)
        $price = $details['price'] ?? $property['price'] ?? '';
        $commission = $details['commission'] ?? $details['commission_percent'] ?? '';
        $rental = $details['monthly_rental'] ?? $property['rental_amount'] ?? '';
        $commissionBase = ($price && (float) $price > 0) ? (float) $price : (($rental && (float) $rental > 0) ? (float) $rental : 0);
        $commissionAmount = ($commissionBase > 0 && $commission) ? round($commissionBase * (float) $commission / 100, 2) : '';

        $baseData['price_in_words'] = $price ? $this->numberToWords($price) : '';
        $baseData['deal_price_in_words'] = $price ? $this->numberToWords($price) : '';
        $baseData['commission_amount'] = $commissionAmount;
        $baseData['deal_commission_amount'] = $commissionAmount;

        // CDS-specific fields override base where they exist — but only if non-empty
        $data = array_filter($data, fn($v) => $v !== '' && $v !== null);
        return array_merge($baseData, $data);
    }

    /**
     * Resolve a field value using the named field's key (e.g. "contact.surname", "property.address").
     * The key format is "source.attribute" where source is property|contact|deal|agent|computed.
     */
    private function resolveByNamedFieldKey(
        string $key, string $sourceType, ?string $sourceColumn, ?string $contactType,
        array $property, array $contactsByRole, array $details, $agent
    ) {
        // If key has a dot, split into source.attribute
        $parts = explode('.', $key, 2);
        $keySource = count($parts) === 2 ? $parts[0] : $sourceType;
        $keyAttr = count($parts) === 2 ? $parts[1] : ($sourceColumn ?: $key);

        switch ($keySource) {
            case 'property':
                return $this->resolvePropertyFromKey($keyAttr, $property, $details);

            case 'contact':
                $contact = $this->resolveContactByType($contactType, $contactsByRole);
                if (!$contact) return '';
                return $this->resolveContactFromKey($keyAttr, $contact);

            case 'deal':
                return $this->resolveDealFromKey($keyAttr, $details, $property);

            case 'agent':
                return $this->resolveAgentFromKey($keyAttr, $agent);

            case 'computed':
                return $this->resolveComputedFromKey($keyAttr, $details, $property);

            case 'manual':
                return ''; // Manual fields are filled by the user

            default:
                // Try sourceType + sourceColumn as fallback
                if ($sourceType === 'property') return $this->resolvePropertyFromKey($sourceColumn ?: $keyAttr, $property, $details);
                if ($sourceType === 'contact') {
                    $contact = $this->resolveContactByType($contactType, $contactsByRole);
                    return $contact ? $this->resolveContactFromKey($sourceColumn ?: $keyAttr, $contact) : '';
                }
                if ($sourceType === 'deal') return $this->resolveDealFromKey($sourceColumn ?: $keyAttr, $details, $property);
                if ($sourceType === 'agent') return $this->resolveAgentFromKey($sourceColumn ?: $keyAttr, $agent);
                if ($sourceType === 'computed') return $this->resolveComputedFromKey($sourceColumn ?: $keyAttr, $details, $property);
                return '';
        }
    }

    /**
     * Resolve a contact array from the contacts-by-role lookup using the named field's contact type.
     */
    private function resolveContactByType(?string $contactType, array $contactsByRole): array
    {
        if (!$contactType) {
            // Default: first available non-agent contact
            return $contactsByRole['seller'] ?? $contactsByRole['landlord'] ?? $contactsByRole['lessor'] ??
                   $contactsByRole['buyer'] ?? $contactsByRole['tenant'] ?? $contactsByRole['lessee'] ?? [];
        }

        $ct = strtolower(trim(preg_replace('/\s+\d+$/', '', $contactType)));
        return $contactsByRole[$ct] ?? $contactsByRole[$contactType] ?? [];
    }

    /**
     * Bug 1: resolve a single contact column across EVERY recipient of a role,
     * joined with ' and ' — the exact join resolveFieldGroupValue uses (so a
     * plain "Seller ID" field renders "3112 and 6789", consistent with the
     * field-grouped name field). One recipient → single value, no separator.
     * Zero matching recipients → '' (caller falls back as before).
     */
    private function resolveContactColumnAllRecipients(?string $contactType, string $column, array $recipients): string
    {
        $aliases = ['landlord' => 'lessor', 'tenant' => 'lessee'];
        $norm = fn (string $r) => $aliases[$r] ?? $r;

        $role = strtolower(trim(preg_replace('/\s+\d+$/', '', (string) $contactType)));
        $role = $norm($role);

        $parts = [];
        foreach ($recipients as $r) {
            if ($norm(strtolower($r['role'] ?? '')) !== $role) {
                continue;
            }
            $val = trim((string) $this->resolveContactFromKey($column, $r));
            if ($val !== '') {
                $parts[] = $val;
            }
        }

        return implode(' and ', $parts);
    }

    /**
     * Resolve a property value from a key attribute.
     */
    private function resolvePropertyFromKey(string $attr, array $property, array $details)
    {
        return match ($attr) {
            'address', 'street'  => $property['address'] ?? $property['title'] ?? '',
            'address+suburb', 'full_address', 'property_full_address'
                => trim(($property['address'] ?? '') . ', ' . ($property['suburb'] ?? ''), ', '),
            // B1 — TOWNSHIP falls back to town then city when suburb is blank, so a property whose
            // area was captured in the town/city selector no longer renders an empty township.
            'suburb', 'township' => $property['suburb'] ?? $property['town'] ?? $property['city'] ?? '',
            'erf', 'erf_number', 'property_number' => $property['erf'] ?? $property['erf_number'] ?? $property['property_number'] ?? '',
            'complex_name'       => $property['complex_name'] ?? '',
            'unit_number', 'number' => $property['unit_number'] ?? '',
            // AT-177 — the sf:property address components (split at import): Town / Street / District.
            'town'               => $property['town'] ?? $property['city'] ?? $property['suburb'] ?? '',
            'street_name', 'street_address' => $property['street_name'] ?? $property['address'] ?? $property['title'] ?? '',
            'district'           => $property['district'] ?? $property['town'] ?? $property['city'] ?? '',
            'property_type'      => $property['property_type'] ?? '',
            'price'              => $details['price'] ?? $property['price'] ?? '',
            // B3 — a price-in-words component resolves to the WORDS (whole rands, "and", no cents),
            // keyed off its source_column since these imports carry no descriptive label.
            'price_in_words', 'amount_in_words', 'price_words', 'asking_price_in_words'
                => ($p = ($details['price'] ?? $property['price'] ?? '')) !== '' ? \App\Support\AmountInWords::rands($p) : '',
            // Bug 2: the "Commission Percent" (property/commission_percent)
            // source must pull the Step-4 Document Details value first, then
            // fall back to the property record. Without this arm it hit the
            // match default and silently read only $property['commission_percent'].
            'commission_percent', 'commission'
                => $details['commission'] ?? $details['commission_percent'] ?? $property['commission_percent'] ?? '',
            'rental_amount'      => $details['monthly_rental'] ?? $property['rental_amount'] ?? '',
            'deposit_amount'     => $details['deposit'] ?? $property['deposit_amount'] ?? '',
            'lease_start_date'   => $details['lease_start'] ?? '',
            'lease_end_date'     => $details['lease_end'] ?? '',
            'expiry_date'        => $details['expiry_date'] ?? $details['mandate_expiry'] ?? '',
            default              => $property[$attr] ?? '',
        };
    }

    /**
     * Resolve a contact value from a key attribute.
     * Handles specific attributes like "surname", "first_name", "full_name", "id_number".
     */
    /**
     * AT-177 B2 — the ATTRIBUTE a named field resolves to, from its own definition.
     *
     * Priority: the named field's `source_column` (the import parses this from the blade
     * data-field "source_type.source_column", so it is the authoritative attribute) → a dotted
     * "source.attribute" key → the field label keyword (last resort, for hand-tagged fields that
     * carry a descriptive label but no column).
     */
    private function attributeForNamedField($namedField, array $field): string
    {
        $col = trim((string) ($namedField->source_column ?? ''));
        if ($col !== '') {
            return $col;
        }

        $key = (string) ($namedField->key ?? $field['field_name'] ?? '');
        if (str_contains($key, '.')) {
            return explode('.', $key, 2)[1];
        }

        return $this->contactAttributeFromLabel($field['label'] ?? $field['manualLabel'] ?? '') ?? '';
    }

    /** B2 — the contact attributes resolveContactFromKey knows how to resolve. */
    private function isKnownContactAttribute(?string $attr): bool
    {
        return in_array(strtolower(trim((string) $attr)), [
            'surname', 'last_name', 'first_name', 'full_name', 'name', 'full_names', 'first_name+last_name',
            'id_number', 'email', 'phone', 'cell', 'tel', 'address',
            'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch_name', 'bank_branch_code', 'bank_account_type',
        ], true);
    }

    /**
     * B2 — derive a contact ATTRIBUTE from a field's human label (Johan's keyword map).
     *
     * Order matters: the most specific keyword wins, so "Physical address" resolves to `address`
     * and not to `name` on the word "name" appearing elsewhere. Surname is tested before the
     * generic name. Returns null when the label names no known attribute (leave resolution as-is).
     */
    private function contactAttributeFromLabel(string $label): ?string
    {
        $l = strtolower($label);

        return match (true) {
            (bool) preg_match('/\b(id|identity)\b|id\s*number|identity\s*number/', $l) => 'id_number',
            (bool) preg_match('/\baddress\b|physical\s*address|residential/', $l)      => 'address',
            (bool) preg_match('/\b(tel|telephone|phone|cell|mobile|contact\s*number)\b/', $l) => 'phone',
            (bool) preg_match('/\be-?mail\b/', $l)                                     => 'email',
            // "Full name and surname" is ONE field for the whole name — it must beat the surname
            // keyword that also appears in it. Composite full-name is checked before surname.
            (bool) preg_match('/\bfull\s*names?\b/', $l)                               => 'name',
            (bool) preg_match('/\b(surname|last\s*name)\b/', $l)                       => 'last_name',
            (bool) preg_match('/\bfirst\s*name\b/', $l)                                => 'first_name',
            (bool) preg_match('/\bname\b/', $l)                                        => 'name',
            default                                                                    => null,
        };
    }

    /** B3 — does this label ask for the amount IN WORDS (alpha), not the figure? */
    private function labelWantsWords(string $label): bool
    {
        return (bool) preg_match('/\b(in\s*words|words|amount\s*in\s*words|alpha)\b/i', $label);
    }

    private function resolveContactFromKey(string $attr, array $contact)
    {
        return match ($attr) {
            'surname', 'last_name'    => $contact['last_name'] ?? '',
            'first_name'              => $contact['first_name'] ?? '',
            'full_name', 'name', 'full_names', 'first_name+last_name'
                => trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: ($contact['name'] ?? ''),
            'id_number'               => $contact['id_number'] ?? '',
            'email'                   => $contact['email'] ?? '',
            'phone', 'cell', 'tel'    => $contact['cell'] ?? $contact['phone'] ?? '',
            'address'                 => $contact['address'] ?? '',
            'bank_name'               => $contact['bank_name'] ?? '',
            'bank_account_name'       => $contact['bank_account_name'] ?? '',
            'bank_account_number'     => $contact['bank_account_number'] ?? '',
            'bank_branch_name'        => $contact['bank_branch_name'] ?? '',
            'bank_branch_code'        => $contact['bank_branch_code'] ?? '',
            'bank_account_type'       => $contact['bank_account_type'] ?? '',
            default                   => $contact[$attr] ?? '',
        };
    }

    /**
     * Resolve a deal/details value from a key attribute.
     */
    private function resolveDealFromKey(string $attr, array $details, array $property)
    {
        return match ($attr) {
            'price', 'purchase_price', 'amount', 'deal_amount'
                => $details['price'] ?? $property['price'] ?? '',
            'commission', 'commission_percent'
                => $details['commission'] ?? $details['commission_percent'] ?? '',
            'monthly_rental', 'rental', 'rental_amount'
                => $details['monthly_rental'] ?? $property['rental_amount'] ?? '',
            'deposit', 'deposit_amount'
                => $details['deposit'] ?? $property['deposit_amount'] ?? '',
            'mandate_start'    => $details['mandate_start'] ?? '',
            'mandate_expiry'   => $details['mandate_expiry'] ?? '',
            'lease_start'      => $details['lease_start'] ?? '',
            'lease_end'        => $details['lease_end'] ?? '',
            'marketing_fee'    => $details['marketing_fee'] ?? '',
            'price_in_words'   => ($details['price'] ?? '') ? $this->numberToWords(($details['price'] ?? 0)) : '',
            'commission_amount' => $this->computeCommissionAmount($details, $property),
            default            => $details[$attr] ?? '',
        };
    }

    /**
     * Resolve an agent value from a key attribute.
     */
    private function resolveAgentFromKey(string $attr, $agent)
    {
        if (!$agent) return '';
        return match ($attr) {
            'name', 'full_name' => $agent->name ?? '',
            'email'             => $agent->email ?? '',
            'cell', 'phone'     => $agent->cell ?? $agent->phone ?? '',
            default             => '',
        };
    }

    /**
     * Resolve computed field values.
     */
    private function resolveComputedFromKey(string $attr, array $details, array $property)
    {
        $price = $details['price'] ?? $details['monthly_rental'] ?? $property['price'] ?? '';
        $leaseStart = $details['lease_start'] ?? '';

        return match ($attr) {
            'price_in_words'    => $price ? $this->numberToWords($price) : '',
            'commission_amount' => $this->computeCommissionAmount($details, $property),
            'lease_start_day'   => $leaseStart ? (int) date('d', strtotime($leaseStart)) : '',
            'lease_start_month' => $leaseStart ? date('F', strtotime($leaseStart)) : '',
            'lease_start_year'  => $leaseStart ? date('Y', strtotime($leaseStart)) : '',
            default             => '',
        };
    }

    /**
     * Compute commission amount from details.
     */
    private function computeCommissionAmount(array $details, array $property): string
    {
        $commission = $details['commission'] ?? $details['commission_percent'] ?? '';
        $price = $details['price'] ?? $property['price'] ?? '';
        $rental = $details['monthly_rental'] ?? $property['rental_amount'] ?? '';
        $base = ($price && (float) $price > 0) ? (float) $price : (($rental && (float) $rental > 0) ? (float) $rental : 0);
        if ($base > 0 && $commission) {
            return (string) round($base * (float) $commission / 100, 2);
        }
        return '';
    }

    /**
     * Fallback source-based resolution for field_mappings without named fields.
     */
    private function resolveBySource(string $source, string $fieldName, array $property, array $contactsByRole, array $details, $agent): string
    {
        $key = last(explode('.', $fieldName));

        if ($source === 'property') {
            return (string) $this->resolvePropertyFromKey($key, $property, $details);
        }

        if ($source === 'contact') {
            $contact = $this->inferContactFromFieldName($fieldName,
                $contactsByRole['landlord'] ?? $contactsByRole['lessor'] ?? [],
                $contactsByRole['tenant'] ?? $contactsByRole['lessee'] ?? [],
                $contactsByRole['seller'] ?? [],
                $contactsByRole['buyer'] ?? []
            );
            return $contact ? (string) $this->resolveContactFromKey($key, $contact) : '';
        }

        if ($source === 'deal') {
            return (string) $this->resolveDealFromKey($key, $details, $property);
        }

        if ($source === 'agent') {
            return (string) $this->resolveAgentFromKey($key, $agent);
        }

        if ($source === 'computed') {
            return (string) $this->resolveComputedFromKey($key, $details, $property);
        }

        return '';
    }

    /**
     * Apply smart defaults based on the property type.
     * Freehold properties get N/A for complex name; sectional title uses unit number for erf.
     */
    private function applySmartDefaults(array &$data, ?object $property): void
    {
        if (!$property) return;

        $type = strtolower($property->property_type ?? '');

        // Sectional title properties have complex names — leave as-is
        if (!in_array($type, ['sectional title', 'apartment', 'flat', 'townhouse'])) {
            // Freehold — no complex
            if (empty($data['property_complex_name'] ?? '')) {
                $data['property_complex_name'] = 'N/A';
            }
            if (empty($data['complex_name'] ?? '')) {
                $data['complex_name'] = 'N/A';
            }
        }

        // Erf number: sectional title uses unit number
        if (in_array($type, ['sectional title', 'apartment', 'flat'])) {
            if (empty($data['property_erf_number'] ?? '') && !empty($property->unit_number ?? '')) {
                $data['property_erf_number'] = $property->unit_number;
            }
        }
    }

    /**
     * Infer which contact to use based on the field name.
     */
    private function inferContactFromFieldName(string $fieldName, array $lessor, array $lessee, array $seller, array $buyer): array
    {
        $lower = strtolower($fieldName);
        if (str_contains($lower, 'landlord') || str_contains($lower, 'lessor') || str_contains($lower, 'owner')) return $lessor;
        if (str_contains($lower, 'tenant') || str_contains($lower, 'lessee')) return $lessee;
        if (str_contains($lower, 'seller')) return $seller;
        if (str_contains($lower, 'buyer') || str_contains($lower, 'purchaser')) return $buyer;
        // Default: first non-empty contact (lessor for rental, seller for sale)
        return $lessor ?: $seller ?: $lessee ?: $buyer;
    }

    /**
     * Resolve a field group into a formatted string from ALL matching recipients.
     *
     * Looks up the group's member named fields, determines the contact type,
     * then formats each matching recipient as "FirstName LastName (ID: xxx)",
     * joining multiple with " and ".
     *
     * Systemic — works for any role (seller, buyer, landlord, tenant, lessor, lessee).
     */
    private function resolveFieldGroupValue(int $fgId, array $recipients): string
    {
        $fg = \App\Models\Docuperfect\FieldGroup::find($fgId);
        if (!$fg || empty($fg->fields)) return '';

        // Load member named fields
        $memberNfIds = collect($fg->fields)->pluck('named_field_id')->filter()->unique()->values();
        $memberNfs = DB::table('docuperfect_named_fields')->whereIn('id', $memberNfIds)->get()->keyBy('id');

        // Determine contact type and member columns from the named fields
        $contactType = '';
        $memberColumns = [];
        foreach ($fg->fields as $member) {
            $nfId = $member['named_field_id'] ?? null;
            $nf = $nfId ? ($memberNfs[$nfId] ?? null) : null;
            if (!$nf) continue;

            $memberColumns[] = $nf->source_column ?? '';
            if (empty($contactType) && !empty($nf->source_contact_type)) {
                $contactType = preg_replace('/\s+\d+$/', '', $nf->source_contact_type);
            }
        }

        if (empty($contactType) || empty($memberColumns)) return '';

        // Collect ALL recipients matching this role (supports multiple per role)
        $roleLookup = strtolower($contactType);
        $contacts = [];
        foreach ($recipients as $r) {
            if (strtolower($r['role'] ?? '') === $roleLookup) {
                $contacts[] = $r;
            }
        }

        if (empty($contacts)) return '';

        // Format each contact: "FirstName LastName (ID: xxx)"
        $displayParts = [];
        foreach ($contacts as $contact) {
            $nameParts = [];
            $idNumber = '';
            foreach ($memberColumns as $col) {
                $val = $contact[$col] ?? '';
                if (empty($val)) continue;
                if ($col === 'id_number') {
                    $idNumber = $val;
                } else {
                    $nameParts[] = $val;
                }
            }
            $line = implode(' ', $nameParts);
            if (!empty($idNumber)) {
                $line .= ' (ID: ' . $idNumber . ')';
            }
            if (!empty(trim($line))) {
                $displayParts[] = trim($line);
            }
        }

        return implode(' and ', $displayParts);
    }

    /**
     * Base resolve — the standard flat variable set used by all web templates.
     * Extracted from resolve() so CDS templates can merge on top.
     */
    private function resolveBase(array $stepData, ?User $agent): array
    {
        $property   = $stepData['property'] ?? [];
        $recipients = $stepData['recipients']['recipients'] ?? [];
        $details    = $stepData['details'] ?? [];
        $agent      = $agent ?? auth()->user();

        $contactsByRole = [];
        $secondContactByRole = [];
        $allContactsByRole = [];
        foreach ($recipients as $r) {
            $role = strtolower($r['role'] ?? '');
            if (!$role) continue;
            $allContactsByRole[$role][] = $r;
            if (!isset($contactsByRole[$role])) {
                $contactsByRole[$role] = $r;
            } elseif (!isset($secondContactByRole[$role])) {
                $secondContactByRole[$role] = $r;
            }
        }

        $lessor = $contactsByRole['landlord'] ?? $contactsByRole['lessor'] ?? [];
        $lessor2 = $secondContactByRole['landlord'] ?? $secondContactByRole['lessor'] ?? [];
        $lessee = $contactsByRole['tenant'] ?? $contactsByRole['lessee'] ?? [];
        $lessee2 = $secondContactByRole['tenant'] ?? $secondContactByRole['lessee'] ?? [];
        $seller = $contactsByRole['seller'] ?? [];
        $seller2 = $secondContactByRole['seller'] ?? [];
        $buyer  = $contactsByRole['buyer'] ?? [];

        $lessorName  = trim(($lessor['first_name'] ?? '') . ' ' . ($lessor['last_name'] ?? '')) ?: ($lessor['name'] ?? '');
        $lessor2Name = trim(($lessor2['first_name'] ?? '') . ' ' . ($lessor2['last_name'] ?? '')) ?: ($lessor2['name'] ?? '');
        $lesseeName  = trim(($lessee['first_name'] ?? '') . ' ' . ($lessee['last_name'] ?? '')) ?: ($lessee['name'] ?? '');
        $lessee2Name = trim(($lessee2['first_name'] ?? '') . ' ' . ($lessee2['last_name'] ?? '')) ?: ($lessee2['name'] ?? '');
        $sellerName  = trim(($seller['first_name'] ?? '') . ' ' . ($seller['last_name'] ?? '')) ?: ($seller['name'] ?? '');
        $seller2Name = trim(($seller2['first_name'] ?? '') . ' ' . ($seller2['last_name'] ?? '')) ?: ($seller2['name'] ?? '');
        $buyerName   = trim(($buyer['first_name'] ?? '') . ' ' . ($buyer['last_name'] ?? '')) ?: ($buyer['name'] ?? '');
        $suburb     = $property['suburb'] ?? $property['town'] ?? $property['city'] ?? ''; // B1
        $address    = $property['address'] ?? $property['title'] ?? '';
        $suburb     = $property['suburb'] ?? '';
        $leaseStart = $details['lease_start'] ?? '';
        $leaseEnd   = $details['lease_end'] ?? '';
        $rental     = $details['monthly_rental'] ?? $property['rental_amount'] ?? '';
        $deposit    = $details['deposit'] ?? $property['deposit_amount'] ?? '';

        $commission = $details['commission'] ?? $details['commission_percent'] ?? '';
        $commissionAmount = ($rental && $commission) ? round((float) $rental * (float) $commission / 100, 2) : '';
        $vatAmount = $commissionAmount ? round((float) $commissionAmount * 0.15, 2) : '';
        $serviceFee = $commissionAmount ? round((float) $commissionAmount + $vatAmount, 2) : '';
        $letsAssist = $details['lets_assist'] ?? '';
        $netToOwner = $rental ? round((float) $rental - ($serviceFee ?: 0) - ($letsAssist ?: 0), 2) : '';

        $leaseStartFormatted = $leaseStart ? date('j F Y', strtotime($leaseStart)) : '';
        $leaseEndFormatted = $leaseEnd ? date('j F Y', strtotime($leaseEnd)) : '';

        $initialsParties = [];
        if (!empty($lessor['name'] ?? '')) $initialsParties[] = 'Owner';
        if (!empty($lessee['name'] ?? '')) $initialsParties[] = 'Tenant';
        if ($agent) $initialsParties[] = 'Agent';

        return [
            'lessor_name' => $lessorName, 'lessor_first_name' => $lessor['first_name'] ?? '', 'lessor_last_name' => $lessor['last_name'] ?? '',
            'lessor_id_number' => $lessor['id_number'] ?? '',
            'lessor_email' => $lessor['email'] ?? '', 'lessor_cell' => $lessor['cell'] ?? $lessor['phone'] ?? '',
            'lessor_address' => $lessor['address'] ?? '',
            'lessor_bank_name' => $lessor['bank_name'] ?? '',
            'lessor_bank_account_name' => $lessor['bank_account_name'] ?? '',
            'lessor_bank_account_number' => $lessor['bank_account_number'] ?? '',
            'lessor_bank_branch_name' => $lessor['bank_branch_name'] ?? '',
            'owner_names' => $lessor2Name ? ($lessorName . ' & ' . $lessor2Name) : $lessorName,
            'lessee_name' => $lesseeName, 'lessee_first_name' => $lessee['first_name'] ?? '', 'lessee_last_name' => $lessee['last_name'] ?? '',
            'lessee_id_number' => $lessee['id_number'] ?? '',
            'lessee_email' => $lessee['email'] ?? '', 'lessee_cell' => $lessee['cell'] ?? $lessee['phone'] ?? '',
            'lessee_address' => $lessee['address'] ?? '',
            'seller_name' => $sellerName, 'seller_first_name' => $seller['first_name'] ?? '', 'seller_last_name' => $seller['last_name'] ?? '',
            'seller_id_number' => $seller['id_number'] ?? '',
            'seller_address' => $seller['address'] ?? '', 'seller_email' => $seller['email'] ?? '',
            'seller_phone' => $seller['cell'] ?? $seller['phone'] ?? '',
            // Seller indexed (1=first, 2=second, 3/4 from additional)
            'seller_address_1' => $seller['address'] ?? '',
            'seller_1_phone' => $seller['cell'] ?? $seller['phone'] ?? '',
            'seller_1_email' => $seller['email'] ?? '',
            'seller_address_2' => $seller2['address'] ?? '',
            'seller_2_phone' => $seller2['cell'] ?? $seller2['phone'] ?? '',
            'seller_2_email' => $seller2['email'] ?? '',
            'seller_name_2' => $seller2Name,
            'seller_2_first_name' => $seller2['first_name'] ?? '',
            'seller_2_last_name' => $seller2['last_name'] ?? '',
            'seller_2_id_number' => $seller2['id_number'] ?? '',
            'seller_address_3' => ($allContactsByRole['seller'][2] ?? [])['address'] ?? '',
            'seller_3_phone' => ($allContactsByRole['seller'][2] ?? [])['cell'] ?? ($allContactsByRole['seller'][2] ?? [])['phone'] ?? '',
            'seller_3_email' => ($allContactsByRole['seller'][2] ?? [])['email'] ?? '',
            'seller_address_4' => ($allContactsByRole['seller'][3] ?? [])['address'] ?? '',
            'seller_4_phone' => ($allContactsByRole['seller'][3] ?? [])['cell'] ?? ($allContactsByRole['seller'][3] ?? [])['phone'] ?? '',
            'seller_4_email' => ($allContactsByRole['seller'][3] ?? [])['email'] ?? '',
            'buyer_name' => $buyerName, 'buyer_first_name' => $buyer['first_name'] ?? '', 'buyer_last_name' => $buyer['last_name'] ?? '',
            'buyer_id_number' => $buyer['id_number'] ?? '',
            'buyer_address' => $buyer['address'] ?? '', 'buyer_email' => $buyer['email'] ?? '',
            'buyer_phone' => $buyer['cell'] ?? $buyer['phone'] ?? '',
            'property_address' => trim("{$address}, {$suburb}", ', '),
            'property_full_address' => trim("{$address}, {$suburb}", ', '),
            'property_full' => trim("{$address}, {$suburb}", ', '),
            'property_suburb' => $suburb,
            'street_address' => trim("{$address}, {$suburb}", ', '),
            'property_street' => $address,
            'property_township' => $suburb,
            'property_district' => $property['district'] ?? 'Ray Nkonyeni',
            'property_erf_number' => $property['erf'] ?? $property['erf_number'] ?? '',
            'property_complex_name' => $property['complex_name'] ?? '',
            'erf_no' => $property['erf'] ?? $property['erf_number'] ?? '',
            'unit_no' => $property['unit_number'] ?? '',
            'complex_name' => $property['complex_name'] ?? '',
            'district' => $property['district'] ?? 'Ray Nkonyeni',
            // CDS generic contact aliases (first non-agent contact: seller for sales, lessor for rental)
            'contact_full_names' => $sellerName ?: $lessorName,
            'contact_address' => ($seller['address'] ?? '') ?: ($lessor['address'] ?? ''),
            'contact_phone' => ($seller['cell'] ?? $seller['phone'] ?? '') ?: ($lessor['cell'] ?? $lessor['phone'] ?? ''),
            'contact_email' => ($seller['email'] ?? '') ?: ($lessor['email'] ?? ''),
            // CDS deal aliases
            'deal_amount' => $details['price'] ?? '',
            'price' => $details['price'] ?? '',
            'monthly_rental' => $rental, 'deposit_amount' => $deposit,
            'commission_percent' => $commission, 'commission_amount' => $commissionAmount,
            'vat_amount' => $vatAmount, 'service_fee' => $serviceFee,
            'net_to_owner' => $netToOwner, 'net_to_lessor' => $netToOwner,
            'lease_start' => $leaseStart, 'lease_end' => $leaseEnd,
            'lease_start_formatted' => $leaseStartFormatted, 'lease_end_formatted' => $leaseEndFormatted,
            'price_in_words' => ($details['price'] ?? '') ? $this->numberToWords($details['price']) : '',
            'mandate_start' => $details['mandate_start'] ?? '',
            'mandate_expiry' => $details['mandate_expiry'] ?? '',
            'mandate_start_formatted' => !empty($details['mandate_start']) ? date('j F Y', strtotime($details['mandate_start'])) : '',
            'mandate_expiry_formatted' => !empty($details['mandate_expiry']) ? date('j F Y', strtotime($details['mandate_expiry'])) : '',
            'agent_name' => $agent->name ?? '', 'agent_email' => $agent->outward_email ?? '', // AT-79 outward override
            'agent_cell' => $agent->cell ?? $agent->phone ?? '',
            'lessor_signature_name' => $lessorName, 'lessee_signature_name' => $lesseeName,
            'seller_signature_name' => $sellerName, 'buyer_signature_name' => $buyerName,
            'agent_signature_name' => $agent->name ?? '',
            'signed_at_location' => '', 'signed_day' => '', 'signed_month' => '', 'signed_year' => '',
            'initialsParties' => $initialsParties,
        ];
    }

    /**
     * Derive the blade variable name from named field source properties.
     * Maps {source_type, source_column, contact_type} to the standard variable
     * names used in blade templates (matching data-field attributes).
     */
    private function deriveBladeName(string $sourceType, string $sourceColumn, ?string $contactType): ?string
    {
        if (empty($sourceColumn)) return null;

        if ($sourceType === 'contact' && $contactType) {
            $role = strtolower(preg_replace('/\s+\d+$/', '', trim($contactType)));
            $prefixMap = ['landlord' => 'lessor', 'tenant' => 'lessee'];
            $prefix = $prefixMap[$role] ?? $role;

            $attrMap = [
                'first_name+last_name' => 'name', 'full_name' => 'name', 'name' => 'name',
                'last_name' => 'last_name', 'surname' => 'last_name',
                'first_name' => 'first_name',
                'id_number' => 'id_number',
                'address' => 'address',
                'phone' => in_array($prefix, ['seller', 'buyer']) ? 'phone' : 'cell',
                'cell' => in_array($prefix, ['seller', 'buyer']) ? 'phone' : 'cell',
                'email' => 'email',
                'bank_name' => 'bank_name', 'bank_account_name' => 'bank_account_name',
                'bank_account_number' => 'bank_account_number', 'bank_branch_name' => 'bank_branch_name',
            ];
            $suffix = $attrMap[$sourceColumn] ?? $sourceColumn;
            return $prefix . '_' . $suffix;
        }

        if ($sourceType === 'property') {
            $propMap = [
                'property_number' => 'property_erf_number', 'erf_number' => 'property_erf_number',
                'erf' => 'property_erf_number',
                'address' => 'property_street', 'street' => 'property_street',
                'suburb' => 'property_township', 'township' => 'property_township',
                'district' => 'property_district',
                'complex_name' => 'property_complex_name',
                'unit_number' => 'unit_no',
                'price' => 'price', 'rental_amount' => 'monthly_rental',
                'deposit_amount' => 'deposit_amount',
                'expiry_date' => 'mandate_expiry',
            ];
            return $propMap[$sourceColumn] ?? 'property_' . $sourceColumn;
        }

        if ($sourceType === 'deal') {
            return $sourceColumn;
        }

        if ($sourceType === 'computed') {
            return $sourceColumn;
        }

        if ($sourceType === 'agent') {
            return 'agent_' . $sourceColumn;
        }

        return null;
    }

    /**
     * HD-4 — one converter, one rounding rule. Delegates to App\Support\AmountInWords (rounds
     * half-up to whole rands, appends " Rand", no cents — Johan's document house rule). The
     * ESignWizardController carried a byte-identical copy; both now share this one.
     */
    private function numberToWords(int|float|string|null $number): string
    {
        return \App\Support\AmountInWords::rands($number);
    }
}
