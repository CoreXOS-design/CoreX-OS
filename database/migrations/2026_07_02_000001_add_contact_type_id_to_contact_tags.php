<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-79 — sub-tags belong to a parent contact type.
 *
 * Each contact_tag becomes a "sub-tag" nested under exactly one of the four
 * fixed e-sign parents (Seller/Buyer/Lessor/Lessee). The column is nullable at
 * the DB level: existing tags have no parent until the `contacts:normalise-types`
 * command assigns one, and the app layer (ContactTagController validation)
 * requires a parent on every NEW sub-tag. A NULL parent therefore means
 * "legacy tag awaiting normalisation", never "valid orphan".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_tags', function (Blueprint $table) {
            $table->foreignId('contact_type_id')->nullable()->after('id')
                  ->constrained('contact_types')->nullOnDelete();
            $table->index('contact_type_id', 'contact_tags_contact_type_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_tags', function (Blueprint $table) {
            $table->dropForeign(['contact_type_id']);
            $table->dropIndex('contact_tags_contact_type_id_idx');
            $table->dropColumn('contact_type_id');
        });
    }
};
