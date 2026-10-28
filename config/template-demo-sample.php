<?php

/*
|--------------------------------------------------------------------------
| Sample data for the template demo view (.ai/specs/esign-template-demo-preview.md §3)
|--------------------------------------------------------------------------
| Neutral, South African, and obviously fake: no real person, no real agency, nothing of HFC's. The demo fills every blank of a template
| from these, so a person can see the document the way a signer will. Nothing here is ever stored, mailed or shown outside the demo.
|
|  people     the sample signers (the demo's signature places print these names)
|  vars       exact blade variable names the compiled pages use (WebTemplateDataService / TemplateController::deriveBladeName)
|  patterns   regex on the variable name (first match wins) for anything not named above - those values are flagged "guessed"
|  money / dates are written the way the documents print them
*/

return [
    'people' => [
        'landlord' => ['name' => 'Thandi Mokoena', 'surname' => 'Mokoena', 'id_number' => '7001010000087', 'email' => 'thandi.mokoena@example.co.za', 'cell' => '082 555 0101'],
        'tenant'   => ['name' => 'Pieter van der Merwe', 'surname' => 'van der Merwe', 'id_number' => '8506155000085', 'email' => 'pieter.vdm@example.co.za', 'cell' => '082 555 0102'],
        'agent'    => ['name' => 'Lerato Dlamini', 'surname' => 'Dlamini', 'id_number' => '9003035000081', 'email' => 'lerato.dlamini@example.co.za', 'cell' => '082 555 0103'],
        'seller'   => ['name' => 'Thandi Mokoena', 'surname' => 'Mokoena', 'id_number' => '7001010000087', 'email' => 'thandi.mokoena@example.co.za', 'cell' => '082 555 0101'],
        'buyer'    => ['name' => 'Pieter van der Merwe', 'surname' => 'van der Merwe', 'id_number' => '8506155000085', 'email' => 'pieter.vdm@example.co.za', 'cell' => '082 555 0102'],
    ],

    'property' => [
        'address' => '12 Sample Street, Table View, Cape Town, 7441',
        'street' => '12 Sample Street',
        'suburb' => 'Table View',
        'city' => 'Cape Town',
        'erf' => 'Erf 12345',
        'description' => 'Erf 12345, Table View, City of Cape Town',
        'complex' => 'Sample Gardens',
        'unit' => '14',
        'size' => '650',
        'lease_type' => 'Residential',
    ],

    'lease' => [
        'rent' => 'R 12 500.00',
        'deposit' => 'R 18 750.00',
        'start_date' => 'first of next month',   // resolved at render time to a real date
        'months' => 12,
        'escalation' => '8%',
        'notice_days' => '20',
        'fee' => 'R 1 500.00',
        'managed_by' => 'Sample Agency',
        'bank' => ['name' => 'Sample Bank', 'account_name' => 'Sample Agency Trust Account', 'account_number' => '1234567890', 'branch' => 'Table View', 'branch_code' => '470010'],
    ],

    // Exact blade variable names (first choice).
    'vars' => [
        'property_address' => '12 Sample Street, Table View, Cape Town, 7441',
        'property_street' => '12 Sample Street',
        'property_township' => 'Table View',
        'property_district' => 'Cape Town',
        'property_erf_number' => 'Erf 12345',
        'property_description' => 'Erf 12345, Table View, City of Cape Town',
        'property_complex_name' => 'Sample Gardens',
        'unit_no' => '14',
        'property_lease_type' => 'Residential',
        'monthly_rental' => 'R 12 500.00',
        'deposit_amount' => 'R 18 750.00',
        'price' => 'R 1 850 000.00',
        'mandate_expiry' => '31 December',
        'lessor_name' => 'Thandi Mokoena',
        'lessor_last_name' => 'Mokoena',
        'lessor_first_name' => 'Thandi',
        'lessor_id_number' => '7001010000087',
        'lessor_email' => 'thandi.mokoena@example.co.za',
        'lessor_cell' => '082 555 0101',
        'lessor_address' => '12 Sample Street, Table View, Cape Town, 7441',
        'lessee_name' => 'Pieter van der Merwe',
        'lessee_last_name' => 'van der Merwe',
        'lessee_first_name' => 'Pieter',
        'lessee_id_number' => '8506155000085',
        'lessee_email' => 'pieter.vdm@example.co.za',
        'lessee_cell' => '082 555 0102',
        'lessee_address' => '12 Sample Street, Table View, Cape Town, 7441',
        'seller_name' => 'Thandi Mokoena',
        'seller_last_name' => 'Mokoena',
        'seller_id_number' => '7001010000087',
        'seller_email' => 'thandi.mokoena@example.co.za',
        'seller_phone' => '082 555 0101',
        'buyer_name' => 'Pieter van der Merwe',
        'buyer_last_name' => 'van der Merwe',
        'buyer_id_number' => '8506155000085',
        'buyer_email' => 'pieter.vdm@example.co.za',
        'buyer_phone' => '082 555 0102',
        'agent_name' => 'Lerato Dlamini',
        'agent_email' => 'lerato.dlamini@example.co.za',
        'agent_cell' => '082 555 0103',
        'lease_start' => null,       // filled with a real date at render time
        'lease_end' => null,
        'property_lease_end_date' => null,
        'bank_name' => 'Sample Bank',
        'lessor_bank_name' => 'Sample Bank',
        'lessor_bank_account_name' => 'Thandi Mokoena',
        'lessor_bank_account_number' => '1234567890',
        'lessor_bank_branch_name' => 'Table View',
    ],

    // Anything else: the first matching pattern decides (and the field is flagged "guessed").
    'patterns' => [
        '/(^|_)(email)($|_)/i' => 'sample@example.co.za',
        '/(cell|phone|mobile|tel)/i' => '082 555 0100',
        '/id_?number|identity/i' => '7001010000087',
        '/(first_name)/i' => 'Sample',
        '/(last_name|surname)/i' => 'Sample-Surname',
        '/(^|_)name($|_)/i' => 'Sample Name',
        '/(rent|rental|amount|fee|charge|deposit|price|levy|levies|rates|cost|vat)/i' => 'R 1 000.00',
        '/(date|expiry|start|end|commence|expire)/i' => 'first of next month',
        '/(size|sqm|area|m2)/i' => '650',
        '/(address|street|suburb|township|city|district)/i' => '12 Sample Street, Table View',
        '/(account|branch_code|branch)/i' => '1234567890',
        '/(percent|%|escalation|rate)/i' => '8%',
        '/(yes|no|allowed|pets|garden|pool|wifi|security|electricity)/i' => 'Yes',
    ],
];
