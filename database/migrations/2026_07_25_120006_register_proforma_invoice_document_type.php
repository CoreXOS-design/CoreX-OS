<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Register the "Proforma Invoice" splitter/document type so generated proformas file
 * onto the deal under a first-class type. Idempotent (slug guard) so it travels to
 * demo/live; global reference row — also carried by deploy:sync-reference-data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('document_types')->where('slug', 'proforma_invoice')->exists()) {
            return;
        }
        $maxSort = (int) DB::table('document_types')->max('sort_order');
        DB::table('document_types')->insert([
            'slug'                => 'proforma_invoice',
            'label'               => 'Proforma Invoice',
            'sort_order'          => $maxSort + 1,
            'is_active'           => true,
            'grouping'            => 'deal',
            'contact_roles'       => json_encode(['seller_owner']),
            'fica_slot'           => 'none',
            'buyer_pack_eligible' => false,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function down(): void
    {
        // No hard delete of reference data — soft-deactivate instead.
        DB::table('document_types')->where('slug', 'proforma_invoice')->update(['is_active' => false]);
    }
};
