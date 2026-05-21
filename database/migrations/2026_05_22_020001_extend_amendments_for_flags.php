<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-Sign V3 Phase 2 (ES-4) — flag promotion to first-class amendments.
 *
 * Adds the new amendment_type value 'flag_raised' (via raw ALTER TABLE
 * because Blueprint can't mutate ENUMs cleanly) and three nullable
 * columns to capture the flag origin context:
 *   flag_origin       — agent_preparation | compliance_officer | signing_party
 *   flag_clause_ref   — location in the document where the flag was raised
 *   flag_reason       — the free-form concern text from the signer
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §17 ES-4
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `document_amendments` MODIFY `amendment_type` ENUM(
            'addition','strikeout','modification','flag_raised'
        ) NOT NULL DEFAULT 'addition'");

        Schema::table('document_amendments', function (Blueprint $table) {
            $table->enum('flag_origin', ['agent_preparation', 'compliance_officer', 'signing_party'])
                ->nullable()
                ->after('amendment_type');
            $table->string('flag_clause_ref')->nullable()->after('flag_origin');
            $table->text('flag_reason')->nullable()->after('flag_clause_ref');
        });
    }

    public function down(): void
    {
        Schema::table('document_amendments', function (Blueprint $table) {
            $table->dropColumn(['flag_origin', 'flag_clause_ref', 'flag_reason']);
        });
        // Enum revert intentionally skipped — rows tagged 'flag_raised' would
        // become invalid. Forward-safe to leave the value in place.
    }
};
