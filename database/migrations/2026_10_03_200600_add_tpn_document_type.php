<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-430 Part E, 2026-09-25 — Johan: "I log into tpn do the verifications
 * and download the results. then I can attach whilst on the tpn
 * verification." The taxonomy `documentTypeOptions` reads from
 * (`RentalApplicationReviewController::show()`,
 * `DocumentType::where('is_active', true)`) has no TPN entry — investigated
 * directly against every migration that seeds `document_types` (the
 * FICA seed, the rental-application seed, the original table seed): slugs
 * present include bank_statement, tax_clearance, company_registration,
 * trust_deed, payslip, financial_statements, ids, por, coc_request,
 * proforma_invoice, and the generic suburb_stats/cma/market_report set —
 * no tpn slug anywhere. Without this, a TPN report filed via the checklist
 * paperclip (or retyped by hand) has no proper home and lands as "Other",
 * exactly the "lost in filing" Johan flagged. Same idempotent
 * insert-if-absent pattern as
 * 2026_09_04_150003_seed_rental_application_document_types.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        $maxSort = DB::table('document_types')->max('sort_order') ?? 0;

        $exists = DB::table('document_types')->where('slug', 'tpn_report')->exists();
        if (! $exists) {
            DB::table('document_types')->insert([
                'slug' => 'tpn_report',
                'label' => 'TPN Report',
                'grouping' => 'contact',
                'sort_order' => $maxSort + 1,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('document_types')->where('slug', 'tpn_report')->delete();
    }
};
