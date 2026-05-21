<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ES-1 data remediation.
 *
 * 1. Ensure the four legally-blocked document-type slugs exist in
 *    document_types (only offer_to_purchase existed at audit time):
 *      - otp
 *      - sale_agreement
 *      - deed_of_alienation
 *      - deed_of_sale
 *
 * 2. Classify any docuperfect_templates row whose name matches one of the
 *    blocked name patterns AND whose document_type_id IS NULL. Templates
 *    that already carry a document_type_id are NEVER overridden — pre-
 *    existing classifications are authoritative.
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §5.6
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1 — ensure the four new slugs exist
        $maxSort = (int) DB::table('document_types')->max('sort_order');
        $slugsToAdd = [
            ['slug' => 'otp',                'label' => 'OTP (Offer to Purchase)'],
            ['slug' => 'sale_agreement',     'label' => 'Sale Agreement'],
            ['slug' => 'deed_of_alienation', 'label' => 'Deed of Alienation'],
            ['slug' => 'deed_of_sale',       'label' => 'Deed of Sale'],
        ];
        foreach ($slugsToAdd as $i => $row) {
            if (! DB::table('document_types')->where('slug', $row['slug'])->exists()) {
                DB::table('document_types')->insert([
                    'slug'       => $row['slug'],
                    'label'      => $row['label'],
                    'sort_order' => $maxSort + $i + 1,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Step 2 — resolve slug → id for the patterns
        $slugId = function (string $slug): ?int {
            $row = DB::table('document_types')->where('slug', $slug)->first(['id']);
            return $row?->id;
        };

        $otpId             = $slugId('otp');
        $offerToPurchaseId = $slugId('offer_to_purchase');
        $saleAgreementId   = $slugId('sale_agreement');
        $deedAlienationId  = $slugId('deed_of_alienation');
        $deedSaleId        = $slugId('deed_of_sale');

        // Pattern → preferred slug id (in priority order — most specific first)
        // We classify into 'otp' for OTP / offer-to-purchase patterns. If a
        // template already had offer_to_purchase, that classification is
        // preserved (Step 2 only touches NULL rows).
        $classifications = [
            ['pattern' => '/\bdeed of alienation\b/i',                 'doc_type_id' => $deedAlienationId],
            ['pattern' => '/\bdeed of sale\b/i',                       'doc_type_id' => $deedSaleId],
            ['pattern' => '/\b(agreement for sale|sale agreement|agreement of sale)\b/i', 'doc_type_id' => $saleAgreementId],
            ['pattern' => '/\b(otp|offer to purchase)\b/i',            'doc_type_id' => $otpId],
        ];

        // Step 3 — iterate templates that have NULL document_type_id and apply
        $rows = DB::table('docuperfect_templates')
            ->whereNull('deleted_at')
            ->whereNull('document_type_id')
            ->get(['id', 'name']);

        foreach ($rows as $row) {
            foreach ($classifications as $c) {
                if (preg_match($c['pattern'], (string) $row->name) && $c['doc_type_id']) {
                    DB::table('docuperfect_templates')
                        ->where('id', $row->id)
                        ->update([
                            'document_type_id' => $c['doc_type_id'],
                            'updated_at'       => now(),
                        ]);
                    break; // first match wins
                }
            }
        }
    }

    public function down(): void
    {
        // Reversal is best-effort: we cannot reliably know which templates we
        // updated vs which already had a doc_type. We DO NOT delete the new
        // slugs in down() because other rows may reference them by now.
        // Manual rollback required if needed.
    }
};
