<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Test-bootstrap-only. Closes the gap `AssistantRoleSeeder`'s own docblock already named:
 * "any environment bootstrapped from the schema snapshot (the test suite, migrate:fresh on a
 * clean DB) sees [a data-seeding] migration as already-run and never gets the row." That gap
 * was never actually closed for tests — `deploy:sync-reference-data` (AT-162) exists precisely
 * for this class of problem and multiple of its registered seeders' own comments say they're
 * needed by "the test suite," but nothing in the test bootstrap ever called it. Confirmed
 * directly: a fresh test database has ZERO rows in `roles` (including the global `assistant`
 * role AssistantRoleSeeder's docblock is specifically about) and ZERO rows in `document_types`
 * (blocking, among others, the alienation-document e-sign guard's classification test).
 *
 * Wired in via `Tests\TestCase::$seeder` — Laravel's own `CanConfigureMigrationCommands` runs
 * this automatically, once, immediately after `migrate:fresh` loads the schema snapshot
 * (RefreshDatabase only does this once per PHP process). No per-test-class changes needed.
 *
 * Scope, deliberately narrow: (1) reuses `deploy:sync-reference-data` verbatim rather than
 * duplicating its seeder list — whatever that command covers on a real deploy, tests now get
 * too, no more and no less. (2) Additionally seeds `document_types`, the one confirmed,
 * evidenced gap NOT already covered by that command (it's seeded via inline migration
 * `DB::table()->insert()` calls scattered across several migrations, not a registered Seeder,
 * so `deploy:sync-reference-data` never touched it). Deliberately does NOT attempt to also
 * seed every other table found empty during the 2026-08-23 diagnosis
 * (`contact_types`, `activity_definitions`, `p24_provinces`, `leave_types`,
 * `payroll_earning_types`) — those don't yet have a specific failing test tying them to a real
 * consequence, and hand-reconstructing each from migration history risks introducing stale or
 * wrong data. Extend this seeder (or, better, promote a table to its own registered seeder in
 * `deploy:sync-reference-data`, the sanctioned path) when a real gap is found for one of them.
 */
class TestReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('deploy:sync-reference-data');

        $this->seedDocumentTypes();
    }

    /**
     * Canonical rows pulled from `corex_qa1` (a real, fully-migrated environment) on
     * 2026-08-23 — the authoritative current state after every migration that has ever
     * touched `document_types` (creation, the contact-role/FICA-slot addition, the
     * OTP-consolidation rename). Upserted by `slug` so this is safe to re-run and safe against
     * drift if a future migration adds a new type — it only ever adds/refreshes rows named
     * here, never removes one a later migration introduced.
     */
    private function seedDocumentTypes(): void
    {
        $now = now();
        $types = [
            ['slug' => 'mandate', 'label' => 'Mandate', 'sort_order' => 1, 'is_active' => true],
            ['slug' => 'fica', 'label' => 'FICA', 'sort_order' => 2, 'is_active' => true],
            ['slug' => 'ids', 'label' => 'IDs / Identity', 'sort_order' => 3, 'is_active' => true],
            ['slug' => 'por', 'label' => 'Proof of Residence', 'sort_order' => 4, 'is_active' => true],
            ['slug' => 'condition_report', 'label' => 'Condition Report', 'sort_order' => 5, 'is_active' => true],
            ['slug' => 'listing_form', 'label' => 'Listing Form', 'sort_order' => 6, 'is_active' => true],
            ['slug' => 'rates_taxes', 'label' => 'Rates & Taxes', 'sort_order' => 7, 'is_active' => true],
            ['slug' => 'body_corporate', 'label' => 'Body Corporate', 'sort_order' => 8, 'is_active' => true],
            ['slug' => 'house_rules', 'label' => 'House Rules', 'sort_order' => 9, 'is_active' => true],
            ['slug' => 'offer_to_purchase', 'label' => 'Offer to Purchase', 'sort_order' => 10, 'is_active' => false],
            ['slug' => 'disclosure', 'label' => 'Disclosure', 'sort_order' => 11, 'is_active' => true],
            ['slug' => 'other', 'label' => 'Other', 'sort_order' => 12, 'is_active' => true],
            ['slug' => 'addendum', 'label' => 'Addendums', 'sort_order' => 13, 'is_active' => true],
            ['slug' => 'rental_agreement', 'label' => 'Rental Agreements', 'sort_order' => 14, 'is_active' => true],
            ['slug' => 'lease_agreement', 'label' => 'Lease Agreement', 'sort_order' => 15, 'is_active' => true],
            ['slug' => 'notice', 'label' => 'Notice', 'sort_order' => 16, 'is_active' => true],
            ['slug' => 'inspection_report', 'label' => 'Inspection Report', 'sort_order' => 17, 'is_active' => true],
            ['slug' => 'power_of_attorney', 'label' => 'Power of Attorney', 'sort_order' => 18, 'is_active' => true],
            ['slug' => 'bank_statement', 'label' => 'Bank Statement', 'sort_order' => 19, 'is_active' => true],
            ['slug' => 'tax_clearance', 'label' => 'Tax Clearance', 'sort_order' => 20, 'is_active' => true],
            ['slug' => 'company_registration', 'label' => 'Company Registration', 'sort_order' => 21, 'is_active' => true],
            ['slug' => 'trust_deed', 'label' => 'Trust Deed', 'sort_order' => 22, 'is_active' => true],
            ['slug' => 'otp', 'label' => 'OTP (Offer to Purchase)', 'sort_order' => 23, 'is_active' => true],
            ['slug' => 'sale_agreement', 'label' => 'Sale Agreement', 'sort_order' => 24, 'is_active' => true],
            ['slug' => 'deed_of_alienation', 'label' => 'Deed of Alienation', 'sort_order' => 25, 'is_active' => true],
            ['slug' => 'deed_of_sale', 'label' => 'Deed of Sale', 'sort_order' => 26, 'is_active' => true],
            ['slug' => 'levy_statement', 'label' => 'Levy Statement', 'sort_order' => 27, 'is_active' => true],
            ['slug' => 'letter_of_executorship', 'label' => 'Letter of Executorship', 'sort_order' => 28, 'is_active' => true],
            ['slug' => 'mandate_extension', 'label' => 'Mandate Extension', 'sort_order' => 29, 'is_active' => true],
            ['slug' => 'mandate_price_reduction', 'label' => 'Mandate Price Reduction', 'sort_order' => 30, 'is_active' => true],
            ['slug' => 'coc', 'label' => 'COC', 'sort_order' => 31, 'is_active' => true],
            ['slug' => 'property_plans', 'label' => 'Property Plans', 'sort_order' => 32, 'is_active' => true],
            ['slug' => 'inventory_list', 'label' => 'Inventory List', 'sort_order' => 33, 'is_active' => true],
            ['slug' => 'inventory_exclusion_list', 'label' => 'Inventory Exclusion List', 'sort_order' => 34, 'is_active' => true],
            ['slug' => 'market_analysis_report', 'label' => 'Market Analysis Report', 'sort_order' => 35, 'is_active' => true],
            ['slug' => 'coc_request', 'label' => 'COC Request', 'sort_order' => 900, 'is_active' => true],
            ['slug' => 'proforma_invoice', 'label' => 'Proforma Invoice', 'sort_order' => 901, 'is_active' => true],
            ['slug' => 'work_authorisation', 'label' => 'Work Authorisation', 'sort_order' => 905, 'is_active' => true],
        ];

        foreach ($types as $t) {
            DB::table('document_types')->updateOrInsert(
                ['slug' => $t['slug']],
                [
                    'label' => $t['label'],
                    'sort_order' => $t['sort_order'],
                    'is_active' => $t['is_active'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
