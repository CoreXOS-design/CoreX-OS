<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\SyncableReferenceSeeder;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * Webinar-eve config sweep (2026-09-02) — demo's `document_types` catalogue
 * (a GLOBAL reference table, no agency_id column) only ever had 4 rows
 * (mandate, disclosure, marketing_permission, addendum) against the real
 * catalogue's ~37, and even those 4 carried stale `buyer_pack_eligible`
 * values. This is exactly the class of bug AT-162 exists to prevent — a
 * seeded GLOBAL reference table that never travelled to an environment
 * because seeders don't run on a `git pull` deploy — except here it never
 * ran on demo even once, so demo just started with the 4 rows a much older
 * migration inserted directly and nothing since.
 *
 * Consequence, confirmed by reading the code that reads this table:
 *   - `buyer_pack_eligible` gates whether a document can appear in a buyer's
 *     Viewing Pack — with 4 stale rows, every document type either couldn't
 *     appear at all, or (after an unrelated manual DB edit made mid-
 *     investigation tonight) appeared too broadly, including seller-side
 *     types (mandate) that must never be buyer-visible.
 *   - `contact_roles` drives AT-105 Save-To auto-routing of a filed document
 *     to the right contact — null on all 4 demo rows, so nothing auto-routes.
 *
 * Values below are the canonical catalogue as it exists today on Staging/
 * production (read directly off that environment, not guessed). One
 * intentional deviation: `marketing_permission` is a demo-only slug that
 * does not exist in the real catalogue at all — kept (existing filed demo
 * documents reference it) but classified the same as its real siblings
 * (mandate/addendum): seller-side, never buyer-pack-eligible.
 *
 * Idempotent (updateOrCreate keyed on slug) and registered for
 * `deploy:sync-reference-data` via SyncableReferenceSeeder so this can never
 * silently go stale on any environment again.
 */
class DocumentTypesCatalogueSeeder extends Seeder implements SyncableReferenceSeeder
{
    /**
     * [slug => [label, sort_order, grouping, contact_roles, fica_slot, buyer_pack_eligible]]
     * contact_roles is stored as JSON; null stays null (no routing role).
     */
    private const CATALOGUE = [
        'mandate' => ['Mandate', 1, 'shared', ['seller_owner'], 'none', false],
        'fica' => ['FICA', 2, 'contact', ['seller_owner'], 'fica_form', false],
        'ids' => ['IDs / Identity', 3, 'contact', ['seller_owner'], 'id', false],
        'por' => ['Proof of Residence', 4, 'shared', ['seller_owner'], 'por', false],
        'condition_report' => ['Condition Report', 5, 'property', null, 'none', true],
        'listing_form' => ['Listing Form', 6, 'shared', ['seller_owner'], 'none', false],
        'rates_taxes' => ['Rates & Taxes', 7, 'property', null, 'none', true],
        'body_corporate' => ['Body Corporate', 8, 'property', null, 'none', true],
        'house_rules' => ['House Rules', 9, 'property', null, 'none', true],
        'disclosure' => ['Disclosure', 11, 'shared', ['seller_owner'], 'none', true],
        'other' => ['Other', 12, 'shared', null, 'none', false],
        'addendum' => ['Addendums', 13, 'shared', null, 'none', false],
        'rental_agreement' => ['Rental Agreements', 14, 'shared', null, 'none', false],
        'lease_agreement' => ['Lease Agreement', 15, 'shared', null, 'none', false],
        'notice' => ['Notice', 16, 'shared', null, 'none', false],
        'inspection_report' => ['Inspection Report', 17, 'shared', null, 'none', true],
        'power_of_attorney' => ['Power of Attorney', 18, 'shared', null, 'none', false],
        'bank_statement' => ['Bank Statement', 19, 'contact', null, 'none', false],
        'tax_clearance' => ['Tax Clearance', 20, 'contact', null, 'none', false],
        'company_registration' => ['Company Registration', 21, 'contact', null, 'none', false],
        'trust_deed' => ['Trust Deed', 22, 'contact', null, 'none', false],
        'otp' => ['OTP (Offer to Purchase)', 23, 'shared', ['seller_owner', 'buyer'], 'none', false],
        'sale_agreement' => ['Sale Agreement', 24, 'shared', null, 'none', false],
        'deed_of_alienation' => ['Deed of Alienation', 25, 'shared', null, 'none', false],
        'deed_of_sale' => ['Deed of Sale', 26, 'shared', null, 'none', false],
        'levy_statement' => ['Levy Statement', 27, 'shared', null, 'none', true],
        'letter_of_executorship' => ['Letter of Executorship', 28, 'shared', null, 'none', true],
        'mandate_extension' => ['Mandate Extension', 29, 'shared', null, 'none', false],
        'mandate_price_reduction' => ['Mandate Price Reduction', 30, 'shared', null, 'none', false],
        'coc' => ['COC', 31, 'shared', null, 'none', false],
        'property_plans' => ['Property Plans', 32, 'shared', null, 'none', false],
        'inventory_list' => ['Inventory List', 33, 'shared', null, 'none', false],
        'inventory_exclusion_list' => ['Inventory Exclusion List', 34, 'shared', null, 'none', false],
        'market_analysis_report' => ['Market Analysis Report', 35, 'shared', [], 'none', true],
        'coc_request' => ['COC Request', 900, 'shared', null, 'none', false],
        'proforma_invoice' => ['Proforma Invoice', 901, 'deal', ['seller_owner'], 'none', false],
        'work_authorisation' => ['Work Authorisation', 905, 'shared', null, 'none', false],
        // Demo-only slug (not in the real catalogue) — existing filed demo
        // documents reference it; classified same as its seller-side siblings.
        'marketing_permission' => ['Marketing Permission', 910, 'shared', null, 'none', false],
    ];

    public function run(): void
    {
        $updated = 0;
        $created = 0;

        foreach (self::CATALOGUE as $slug => [$label, $sortOrder, $grouping, $contactRoles, $ficaSlot, $buyerPackEligible]) {
            $type = DocumentType::withTrashed()->where('slug', $slug)->first();
            $attrs = [
                'label' => $label,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'grouping' => $grouping,
                'contact_roles' => $contactRoles,
                'fica_slot' => $ficaSlot,
                'buyer_pack_eligible' => $buyerPackEligible,
                'deleted_at' => null,
            ];

            if ($type) {
                $type->fill($attrs)->save();
                $updated++;
            } else {
                DocumentType::create(array_merge(['slug' => $slug], $attrs));
                $created++;
            }
        }

        if ($this->command) {
            $this->command->info("  DocumentTypesCatalogueSeeder: {$created} created, {$updated} synced to canonical values.");
        }
    }
}
