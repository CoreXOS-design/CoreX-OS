<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-application-field-config.md §7, piece (c)(4) — file
 * upload for custom fields, the genuinely new piece the spec flagged as
 * unsettled. Reuses the existing document pipeline exactly (Document
 * model, storage convention, contacts/properties pivots, allowlist,
 * soft-delete-not-hard-delete) rather than inventing a second one — this
 * one nullable column is the entire schema footprint.
 *
 * `custom_field_key` distinguishes "this document answers a specific
 * custom field" from a generic supporting document sharing the SAME
 * source_type/source_id — without it, a file uploaded to answer
 * "Pet Details" would also silently appear in the generic Supporting
 * Documents list, which is wrong (it's already shown in its own field
 * slot). Nullable and generic-by-name-only in practice: only ever set for
 * rental-application custom-field uploads today, but not literally scoped
 * to that table in the column name (matching `deal_id` on this same
 * table — a module-specific nullable column bolted onto the shared
 * `documents` table as needed, an already-established pattern here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('custom_field_key', 150)->nullable()->after('deal_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('custom_field_key');
        });
    }
};
