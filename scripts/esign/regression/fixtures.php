<?php

/**
 * E-SIGN REGRESSION HARNESS — disposable test data.
 *
 * Idempotent: safe to run before every harness invocation. Never touches a
 * record it did not itself create in a prior run (matches by the fixed
 * REGRESSION_TAG identifiers below, never by scanning for "any natural
 * person" the way ad-hoc testing did all of tonight — that is exactly the
 * cross-contamination this harness exists to avoid).
 *
 * Run via: php artisan tinker --execute="require 'scripts/esign/regression/fixtures.php';"
 * (from the QA1 repo root). Read-only against everything except the fixture
 * rows it owns — never touches a real agent's contacts or properties.
 *
 * Outputs a JSON map of fixture id => contact/property id to
 * scripts/esign/regression/.fixtures.json so the Node driver can look them
 * up without re-deriving IDs.
 */

if (!function_exists('regFindOrCreateContact')) {
    function regFindOrCreateContact(array $attrs, ?int $sellerTypeId): \App\Models\Contact
    {
        $existing = \App\Models\Contact::withoutGlobalScopes()
            ->where('agency_id', $attrs['agency_id'])
            ->where('email', $attrs['email'])
            ->first();
        if ($existing) {
            return $existing;
        }
        $attrs['contact_type_id'] = $sellerTypeId;
        return \App\Models\Contact::create($attrs);
    }
}

$agencyId = 1;

// The one contact_type tagged esign_role=seller — REG contacts use it so
// the harness's own recipient search never trips over the separately
// tracked, separately reported "search excludes imperfectly-tagged
// contacts" bug. That bug is Johan's call to fix or not; this harness
// tests Domicilium/clause/signature-block correctness, not that filter.
$sellerTypeId = DB::table('contact_types')->where('esign_role', 'seller')->value('id');

$fixtures = [];

// --- Shared property -------------------------------------------------
$property = \App\Models\Property::withoutGlobalScopes()
    ->where('agency_id', $agencyId)
    ->where('title', 'REGRESSION HARNESS TEST PROPERTY — DO NOT EDIT')
    ->first();
if (!$property) {
    $property = \App\Models\Property::create([
        'agency_id'      => $agencyId,
        'title'          => 'REGRESSION HARNESS TEST PROPERTY — DO NOT EDIT',
        'address'        => '1 Regression Harness Way',
        'street_number'  => '1',
        'street_name'    => 'Regression Harness Way',
        'suburb'         => 'Uvongo',
        'town'           => 'Uvongo',
        'province'       => 'KwaZulu-Natal',
        'property_type'  => 'House',
        'status'         => 'active',
        'listing_type'   => 'sale',
        'price'          => 850000,
        'beds'           => 3,
        'baths'          => 2,
        'agent_id'       => 22,
        'branch_id'      => \App\Models\User::find(22)->branch_id,
    ]);
}
$fixtures['property_id'] = $property->id;

// --- Shape A/B/C natural-person sellers -------------------------------
$sellerOne = regFindOrCreateContact([
    'agency_id'  => $agencyId,
    'first_name' => 'RegSellerOne',
    'last_name'  => 'HarnessFixture',
    'email'      => 'reg.seller.one@harness.test',
    'phone'      => '0821000001',
    'id_number'  => '8001015800101',
    'contact_kind' => 'natural_person',
], $sellerTypeId);
$fixtures['seller_one_contact_id'] = $sellerOne->id;

$sellerTwo = regFindOrCreateContact([
    'agency_id'  => $agencyId,
    'first_name' => 'RegSellerTwo',
    'last_name'  => 'HarnessFixture',
    'email'      => 'reg.seller.two@harness.test',
    'phone'      => '0821000002',
    'id_number'  => '8002015800102',
    'contact_kind' => 'natural_person',
], $sellerTypeId);
$fixtures['seller_two_contact_id'] = $sellerTwo->id;

$deceased = regFindOrCreateContact([
    'agency_id'  => $agencyId,
    'first_name' => 'RegDeceased',
    'last_name'  => 'HarnessFixture',
    'email'      => 'reg.deceased@harness.test',
    'phone'      => '0821000003',
    'id_number'  => '8003015800103',
    'contact_kind' => 'natural_person',
], $sellerTypeId);
$fixtures['deceased_contact_id'] = $deceased->id;

$executor = regFindOrCreateContact([
    'agency_id'  => $agencyId,
    'first_name' => 'RegExecutor',
    'last_name'  => 'HarnessFixture',
    'email'      => 'reg.executor@harness.test',
    'phone'      => '0821000004',
    'id_number'  => '8004015800104',
    'contact_kind' => 'natural_person',
], $sellerTypeId);
$fixtures['executor_contact_id'] = $executor->id;

// Deliberately NO contact_property link between these fixture contacts and
// the shared harness property. The esign_role='seller' contact_type tag
// alone is enough for the recipient search to find them (that is exactly
// why they're tagged that way) — linking them to the property as well
// triggers the wizard's OWN "auto-populate recipients from linked sellers"
// feature the moment the property is selected, silently adding all three
// as default recipients before any shape gets to explicitly add the two it
// actually wants. Found this the hard way on the harness's own first run:
// shape A (2 sellers) showed 5 Domicilium entries because the property
// auto-populated seller-one/seller-two/deceased, then the shape's own
// addRecipientBySearch() added seller-one/seller-two AGAIN. Not a product
// bug — a harness fixture-design mistake, fixed here so every shape's
// recipient list is exactly what that shape's build() adds, nothing more.

// --- Shape C: supplier-sourced executor -------------------------------
$firm = DB::table('agency_service_providers')
    ->where('agency_id', $agencyId)
    ->where('name', 'REG Estate Executors — HARNESS FIXTURE')
    ->first();
if (!$firm) {
    $firmId = DB::table('agency_service_providers')->insertGetId([
        'agency_id'          => $agencyId,
        'name'               => 'REG Estate Executors — HARNESS FIXTURE',
        'specialty'          => 'Executor',
        'registration_number' => '8005015800105',
        'address'            => '1 Regression Firm Street, Uvongo',
        'email'              => 'reg.supplier.firm@harness.test',
        'is_active'          => 1,
        'created_at'         => now(), 'updated_at' => now(),
    ]);
} else {
    $firmId = $firm->id;
}
$fixtures['supplier_firm_id'] = $firmId;

$supplierContact = DB::table('agency_service_provider_contacts')
    ->where('service_provider_id', $firmId)
    ->where('email', 'reg.supplier.executor@harness.test')
    ->first();
if (!$supplierContact) {
    $supplierContactId = DB::table('agency_service_provider_contacts')->insertGetId([
        'agency_id'      => $agencyId,
        'service_provider_id' => $firmId,
        'attorney_name'  => 'RegSupplierExecutor HarnessFixture',
        'contact_person' => 'RegSupplierExecutor HarnessFixture',
        'email'          => 'reg.supplier.executor@harness.test',
        'phone'          => '0821000005',
        'id_number'      => '8005015800105',
        'is_active'      => 1,
        'created_at'     => now(), 'updated_at' => now(),
    ]);
} else {
    $supplierContactId = $supplierContact->id;
}
$fixtures['supplier_executor_contact_id'] = $supplierContactId;

// --- Shape D/E: company with three directors --------------------------
$company = \App\Models\Contact::withoutGlobalScopes()
    ->where('agency_id', $agencyId)
    ->where('entity_name', 'REG Proxy Test CC — HARNESS FIXTURE')
    ->first();
if (!$company) {
    $company = \App\Models\Contact::create([
        'agency_id'    => $agencyId,
        'contact_kind' => 'entity',
        'entity_name'  => 'REG Proxy Test CC — HARNESS FIXTURE',
        'entity_reg_no' => '2026/999000/23',
        'first_name'   => 'REG Proxy Test CC',
        'last_name'    => '',
        'email'        => 'reg.proxytest.company@harness.test',
    ]);
}
$fixtures['company_contact_id'] = $company->id;

$directorNames = ['RegDirectorOne', 'RegDirectorTwo', 'RegDirectorThree'];
$directorIds = [];
foreach ($directorNames as $i => $name) {
    $d = regFindOrCreateContact([
        'agency_id'  => $agencyId,
        'first_name' => $name,
        'last_name'  => 'HarnessFixture',
        'email'      => strtolower($name) . '@harness.test',
        'phone'      => '08210000' . (10 + $i),
        'id_number'  => '800' . (6 + $i) . '015800' . (106 + $i),
        'contact_kind' => 'natural_person',
    ], null); // directors are not independently seller-tagged; they sign via the company
    $directorIds[] = $d->id;

    $link = DB::table('contact_representatives')
        ->where('entity_contact_id', $company->id)
        ->where('representative_contact_id', $d->id)
        ->first();
    if (!$link) {
        DB::table('contact_representatives')->insert([
            'entity_contact_id' => $company->id,
            'representative_contact_id' => $d->id,
            'is_primary' => $i === 0 ? 1 : 0,
            'capacity' => 'Director',
            'signs_as_proxy' => 0,
            'asserted_by_user_id' => 22,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
$fixtures['director_contact_ids'] = $directorIds;

// --- Pre-approved FICA for every fixture contact that can be asked to
// sign as a recipient ---------------------------------------------------
// 2026-08-27 — the recipient-signing chain (Johan: "rec 1 matches from
// agent, rec 2 matches from rec 1") hits a REAL FICA gate
// (SigningController checks FicaSubmission::where('contact_id',...)->
// where('status','approved')->exists() before allowing a recipient to
// reach the document). A disposable test contact obviously has no real
// FICA submission, and completing that gate for real (document upload +
// review) is a different screen from the one being regression-tested —
// same reasoning fixtures.php already uses for Contacts/Properties. One
// idempotent, clearly-labelled 'approved' row per fixture contact, dated
// far in the future so it never itself trips a staleness check.
if (!function_exists('regEnsureFicaApproved')) {
    function regEnsureFicaApproved(int $contactId, int $agencyId): void
    {
        $exists = DB::table('fica_submissions')
            ->where('contact_id', $contactId)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->exists();
        if ($exists) return;
        DB::table('fica_submissions')->insert([
            'contact_id'      => $contactId,
            'agency_id'       => $agencyId,
            'requested_by'    => 22,
            'entity_type'     => 'natural',
            'status'          => 'approved',
            'intake_type'     => 'online',
            'verification_method' => json_encode(['source' => 'regression-harness-fixture']),
            'verified_by'     => 22,
            'verified_at'     => now(),
            'fica_expires_at' => now()->addYears(5),
            'reviewer_notes'  => 'REGRESSION HARNESS FIXTURE — pre-approved so the recipient-signing chain (rec 1, rec 2, ...) can be driven end to end. Not a real FICA verification.',
            'created_at'      => now(), 'updated_at' => now(),
        ]);
    }
}
foreach ([$sellerOne->id, $sellerTwo->id, $executor->id, ...$directorIds] as $ficaContactId) {
    regEnsureFicaApproved($ficaContactId, $agencyId);
}
// Deliberately NOT for $deceased — she must never receive a signing link
// at all (assertion 10), so she has no reason to ever reach the FICA gate.

file_put_contents(
    __DIR__ . '/.fixtures.json',
    json_encode($fixtures, JSON_PRETTY_PRINT)
);

echo "Fixtures ready:\n";
echo json_encode($fixtures, JSON_PRETTY_PRINT) . "\n";
