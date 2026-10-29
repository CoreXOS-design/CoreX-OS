<?php

/*
|--------------------------------------------------------------------------
| Template builder - the curated field catalogue
|--------------------------------------------------------------------------
|
| esign-lease-field-source.md. The named-field table is filled from every database column (docuperfect:sync-fields), so the
| builder's pickers listed things like "Lessor Purged At" and "P24 Image Signature". This is the short list of columns that
| mean something on a document, per data source. The builder shows these by default; "Show every database field" in the
| picker brings the rest back, and a field a template is already mapped to is always shown.
|
| Contact columns are listed once and apply to each contact role (Lessor, Lessee, Seller, Buyer). The 'lease' source is not
| listed here: every Lease field is curated (App\Services\Docuperfect\LeaseFieldSource). Sources not listed (static, manual,
| computed) are short already and are shown whole.
|
| MULTI-AGENCY: neutral, no agency named. The list is about what a document may say, not about any agency's documents.
*/

return [

    'contact' => [
        'first_name+last_name', 'first_name', 'last_name', 'id_number', 'passport_number', 'address',
        'unit_number', 'complex_name', 'street_number', 'street_name', 'suburb', 'city', 'province',
        'phone', 'email', 'entity_name', 'entity_reg_no',
        'bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch_name', 'bank_branch_code', 'bank_account_type',
    ],

    'property' => [
        'address', 'address+suburb', 'street_name', 'street_number', 'unit_number', 'complex_name', 'property_number',
        'erf_number', 'stand_number', 'scheme_number', 'suburb', 'town', 'district', 'city', 'province',
        'price', 'rental_amount', 'deposit_amount', 'commission_percent', 'expiry_date',
        'title', 'description', 'rates_taxes', 'levy', 'special_levy', 'admin_fee', 'marketing_fee',
        'property_type', 'mandate_type', 'erf_size_m2', 'size_m2', 'beds', 'baths', 'garages',
        'title_deed_number', 'furnished_status', 'occupation_date',
    ],

    'agent' => [
        'name', 'email', 'phone', 'cell', 'fax', 'ffc_number', 'ffc_expiry_date', 'designation', 'website', 'whatsapp_number',
    ],

    'deal' => [
        'deal_no', 'file_no', 'deal_date', 'sale_date', 'sale_price', 'property_value', 'total_commission', 'registration_date',
        'seller_name', 'buyer_name', 'attorney_name', 'property_address', 'commission', 'mandate_start', 'mandate_expiry',
    ],

    // Sources shown whole (already short and all meaningful).
    'whole' => ['lease', 'computed', 'static', 'manual'],
];
