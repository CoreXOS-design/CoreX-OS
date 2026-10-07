<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §15.10 M4 (Build L1 — foundation) — what an agency's linked lease agreement
 * needs beyond "which template": the FIELD MAP (which of the template's own field names hold the
 * rent, the dates, the names and the agreement terms — §15.7.1, §15.12.3), whether it is the agency's
 * default (one per agency + category, kept by the service in a transaction), and the result of the
 * last LeaseAgreementTemplateGuard check so the set-up page can show it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_lease_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_lease_templates', 'field_map')) {
                $table->json('field_map')->nullable();
            }
            if (! Schema::hasColumn('rental_lease_templates', 'is_default')) {
                $table->boolean('is_default')->default(false);
            }
            if (! Schema::hasColumn('rental_lease_templates', 'validated_at')) {
                $table->timestamp('validated_at')->nullable();
            }
            if (! Schema::hasColumn('rental_lease_templates', 'validation_problems')) {
                $table->json('validation_problems')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_lease_templates', function (Blueprint $table) {
            $table->dropColumn(['field_map', 'is_default', 'validated_at', 'validation_problems']);
        });
    }
};
