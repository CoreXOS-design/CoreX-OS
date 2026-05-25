<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A.2.5 — POPIA audit fields on contacts for ID number captures.
 *
 * `contacts.id_number` already exists (added 2026_03_05). This migration
 * adds the audit pair so we can answer "who captured this ID, when, and
 * from which surface" — needed for POPIA Article 11 (purpose-limitation)
 * compliance reviews.
 *
 *   id_number_captured_at — timestamp set at the moment the field was
 *                            first populated. Once non-null it should not
 *                            be reset on update.
 *   id_number_source      — short slug identifying the originating module:
 *                            'quick_create_from_map',
 *                            'quick_create_from_mic',
 *                            'property_inline_create',
 *                            'seller_outreach_entry',
 *                            'manual_edit', etc.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'id_number_captured_at')) {
                $table->timestamp('id_number_captured_at')->nullable()->after('id_number');
            }
            if (!Schema::hasColumn('contacts', 'id_number_source')) {
                $table->string('id_number_source', 60)->nullable()->after('id_number_captured_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            foreach (['id_number_source', 'id_number_captured_at'] as $col) {
                if (Schema::hasColumn('contacts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
