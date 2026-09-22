<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WebTemplateController extends Controller
{
    /**
     * Preview the Letting Mandate V5 web template with sample data.
     */
    public function lettingMandateV5(Request $request)
    {
        $data = [
            'lessor_name'        => 'Shaun Hartley',
            'agent_name'         => 'Maggie Venter',
            'property_address'   => '14 Marine Drive, Uvongo',
            'rental_amount'      => '8 500',
            'rental_in_words'    => 'Eight thousand five hundred Rand',
            'mandate_day'        => '7th',
            'mandate_month'      => 'March',
            'mandate_year'       => '26',
            'commission_percent' => '7.5',
            'account_holder'     => 'S Hartley',
            'bank_name'          => 'FNB',
            'account_number'     => '62845930127',
            'branch_name'        => 'Shelly Beach (220629)',
            'owner_contact'      => '082 123 4567',
            'owner_email'        => 'shaun@email.com',
            'signed_at_location' => 'Shelly Beach',
            'signed_day'         => '7th',
            'signed_month'       => 'March',
            'signed_time'        => '10:00',
            'signed_ampm'        => 'am',
        ];

        // Allow ?empty=1 to preview blank (unfilled) version
        if ($request->boolean('empty')) {
            $data = [];
        }

        return view('docuperfect.web-templates.letting-mandate-v5', $data);
    }

    /**
     * Preview the Rental Application V8 web template with sample data.
     */
    public function rentalApplicationV8(Request $request)
    {
        $data = [
            'property_address'      => '14 Marine Drive, Uvongo',
            'full_name'             => 'John Smith',
            'id_number'             => '8501015800085',
            'marital_status'        => 'Married',
            'spouse_name'           => 'Jane Smith',
            'spouse_id'             => '8703025800081',
            'citizenship'           => 'South African',
            'current_address_1'     => '22 Beach Road',
            'current_address_2'     => 'Margate, 4275',
            'email_address'         => 'john@email.com',
            'cell_number'           => '082 123 4567',
            'work_number'           => '039 315 1234',
            'contact_person_name'   => 'Peter Smith',
            'contact_person_cell'   => '083 987 6543',
            'contact_person_work'   => '039 315 5678',
            'current_landlord_name' => 'ABC Rentals',
            'current_landlord_tel'  => '039 312 3456',
            'current_rental'        => '7 500',
            'rental_from'           => '01/01/2024',
            'rental_to'             => '31/12/2024',
            'employer_name'         => 'KZN Motors',
            'position'              => 'Sales Manager',
            'employer_address'      => '10 Main Road, Port Shepstone',
            'employer_tel'          => '039 682 1234',
            'monthly_salary'        => '35 000',
            'occupation_date'       => '01/04/2026',
            'rental_terms'          => '12 months',
            'special_conditions_1'  => 'Pet-friendly preferred',
            'special_conditions_2'  => '',
            'special_conditions_3'  => '',
            'adults'                => '2',
            'children'              => '1',
        ];

        if ($request->boolean('empty')) {
            $data = [];
        }

        return view('docuperfect.web-templates.rental-application-v8', $data);
    }

    /**
     * Preview the Letting Mandatory Disclosure V7 web template with sample data.
     */
    public function lettingMandatoryDisclosureV7(Request $request)
    {
        $data = [
            'property_address'          => '14 Marine Drive, Uvongo',
            'electrical_cert_date'      => '15/01/2026',
            'fence_cert_date'           => '',
            'gas_cert_date'             => '',
            'entomology_cert_date'      => '20/11/2025',
            'lessor_signed_at'          => 'Shelly Beach',
            'lessor_signed_date'        => '10 March 2026',
            'tenant_signed_at'          => 'Shelly Beach',
            'tenant_signed_date'        => '10 March 2026',
            'practitioner_signed_at'    => 'Shelly Beach',
            'practitioner_signed_date'  => '10 March 2026',
        ];

        if ($request->boolean('empty')) {
            $data = [];
        }

        return view('docuperfect.web-templates.letting-mandatory-disclosure-v7', $data);
    }

    /**
     * Preview the Letting Marketing Permission V7 web template with sample data.
     */
    public function lettingMarketingPermissionV7(Request $request)
    {
        $data = [
            'owner_names'           => 'Shaun Hartley',
            'erf_unit_no'           => '15',
            'complex_name'          => 'Ocean View Estate',
            'street'                => 'Marine Drive',
            'township'              => 'Uvongo',
            'district'              => 'Ugu',
            'lessor1_address'       => '14 Marine Drive, Uvongo',
            'lessor1_tel'           => '082 123 4567',
            'lessor1_email'         => 'shaun@email.com',
            'lessor2_address'       => '',
            'lessor2_tel'           => '',
            'lessor2_email'         => '',
            'rental_amount'         => '8 500',
            'rental_in_words'       => 'Eight thousand five hundred Rand',
            'commission_amount'     => '637.50',
            'commission_percent'    => '7.5',
            'signed_at_location'    => 'Shelly Beach',
            'signed_day'            => '10th',
            'signed_month'          => 'March',
            'signed_year'           => '26',
            'signed_time'           => '10:00',
            'marketing_agent'       => 'Maggie Venter',
            'total_rental'          => '8 500',
            'service_fee'           => '977.50',
            'lets_assist'           => '0.00',
            'net_to_lessor'         => '7 522.50',
            'addendum_lessor_date'  => '10 March 2026',
            'addendum_agent_date'   => '10 March 2026',
        ];

        if ($request->boolean('empty')) {
            $data = [];
        }

        return view('docuperfect.web-templates.letting-marketing-permission-v7', $data);
    }

    /**
     * Preview the Lease Agreement POPI V8 web template with sample data.
     */
    public function leaseAgreementPopiV8(Request $request)
    {
        $data = [
            'lessor_name'           => 'Shaun Hartley',
            'lessor_address'        => '14 Marine Drive, Uvongo',
            'lessor_id'             => '7501015800085',
            'lessee_name'           => 'John Smith',
            'lessee_address'        => '22 Beach Road, Margate',
            'lessee_id'             => '8501015800085',
            'erf_no'                => '1234',
            'street_address'        => '14 Marine Drive, Uvongo',
            'unit_no'               => '5',
            'complex_name'          => 'Ocean View Estate',
            'adults'                => '2',
            'other_persons'         => '1',
            'rental_amount'         => '8 500',
            'rental_in_words'       => 'Eight thousand five hundred',
            'escalation_percent'    => '8',
            'escalation_in_words'   => 'Eight percent',
            'escalation_month'      => 'April',
            'lease_start'           => '1 April 2026',
            'min_term_day'          => '31st',
            'min_term_month'        => 'March',
            'min_term_year'         => '2027',
            'lease_end'             => '31 March 2027',
            'renewal_months'        => '12',
            'electricity_settlement' => 'Yes',
            'pets_1'                => '1 x small dog',
            'pets_2'                => '',
            'lessor_signed_at'      => 'Shelly Beach',
            'lessor_signed_day'     => '10th',
            'lessor_signed_month'   => 'March',
            'lessor_signed_year'    => '26',
            'lessor_signed_time'    => '10:00',
            'lessee_signed_at'      => 'Shelly Beach',
            'lessee_signed_day'     => '10th',
            'lessee_signed_month'   => 'March',
            'lessee_signed_year'    => '26',
            'lessee_signed_time'    => '10:30',
            'agent_signed_at'       => 'Shelly Beach',
            'agent_signed_day'      => '10th',
            'agent_signed_month'    => 'March',
            'agent_signed_year'     => '26',
            'agent_signed_time'     => '10:30',
            'total_rental'          => '8 500',
            'service_fee'           => '977.50',
            'lets_assist'           => '0.00',
            'net_to_owner'          => '7 522.50',
            'addendum_lessor_date'  => '10 March 2026',
            'addendum_tenant_date'  => '10 March 2026',
            'addendum_agent_date'   => '10 March 2026',
        ];

        if ($request->boolean('empty')) {
            $data = [];
        }

        return view('docuperfect.web-templates.lease-agreement-popi-v8', $data);
    }

    /**
     * Preview the Commercial Lease Agreement V5 web template with sample data.
     */
    public function commercialLeaseAgreementV5(Request $request)
    {
        $data = [
            'lessor_name'           => 'ABC Properties (Pty) Ltd',
            'lessor_name_2'         => '',
            'lessor_address'        => '10 Main Road, Port Shepstone',
            'lessor_id'             => '2015/123456/07',
            'lessee_name'           => 'KZN Motors (Pty) Ltd',
            'lessee_name_2'         => '',
            'lessee_address'        => '22 Beach Road, Margate',
            'lessee_id'             => '2018/654321/07',
            'erf_no'                => '5678',
            'street_address'        => '10 Main Road, Port Shepstone',
            'unit_no'               => '3',
            'complex_name'          => 'Main Street Business Park',
            'business_type'         => 'Motor vehicle sales and repairs',
            'rental_amount'         => '15 000',
            'rental_in_words'       => 'Fifteen thousand',
            'escalation_percent'    => '8',
            'escalation_in_words'   => 'Eight percent',
            'escalation_month'      => 'April',
            'lease_start'           => '1 April 2026',
            'min_term_day'          => '31st',
            'min_term_month'        => 'March',
            'min_term_year'         => '2028',
            'lease_end'             => '31 March 2028',
            // .ai/specs/rental-property-tab.md §5, Part 4
            'lease_type'            => 'Triple Net',
            'renewal_months'        => '24',
            'electricity_deposit'   => '3 000',
            'lessor_signed_at'      => 'Port Shepstone',
            'lessor_signed_day'     => '10th',
            'lessor_signed_month'   => 'March',
            'lessor_signed_year'    => '26',
            'lessor_signed_time'    => '10:00',
            'lessee_signed_at'      => 'Port Shepstone',
            'lessee_signed_day'     => '10th',
            'lessee_signed_month'   => 'March',
            'lessee_signed_year'    => '26',
            'lessee_signed_time'    => '10:30',
            'agent_signed_at'       => 'Shelly Beach',
            'agent_signed_day'      => '10th',
            'agent_signed_month'    => 'March',
            'agent_signed_year'     => '26',
            'agent_signed_time'     => '10:30',
            'total_rental'          => '15 000',
            'agent_commission'      => '1 500',
            'lets_assist'           => '0.00',
            'net_to_owner'          => '13 500',
            'addendum_lessor_date'  => '10 March 2026',
            'addendum_lessee_date'  => '10 March 2026',
            'addendum_agent_date'   => '10 March 2026',
        ];

        if ($request->boolean('empty')) {
            $data = [];
        }

        return view('docuperfect.web-templates.commercial-lease-agreement-v5', $data);
    }
}
