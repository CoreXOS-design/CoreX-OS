<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conductor brief 2026-09-29 — wet-ink signing for rental Inventory,
 * mirroring rental_inspection_signatures' own
 * 2026_10_01_100000_add_wet_ink_to_rental_inspection_signatures migration
 * exactly (see that migration's own docblock for the full reasoning): a
 * tenant or landlord who signs on paper rather than in the app. Adds the
 * same three columns, same append-only "superseded, never edited/destroyed"
 * shape RentalInspectionSignature already uses (non-negotiable #1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_signatures', function (Blueprint $table) {
            $table->string('wet_ink_upload_path')->nullable()->after('party_signature_path');
            $table->timestamp('superseded_at')->nullable()->after('disposition_recorded_at');
            $table->unsignedBigInteger('superseded_by_signature_id')->nullable()->after('superseded_at');

            $table->foreign('superseded_by_signature_id')
                ->references('id')->on('rental_inventory_signatures')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_signatures', function (Blueprint $table) {
            $table->dropForeign(['superseded_by_signature_id']);
            $table->dropColumn(['wet_ink_upload_path', 'superseded_at', 'superseded_by_signature_id']);
        });
    }
};
