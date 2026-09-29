<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §16 — a tenant or landlord who signs on
 * paper, not on the agent's device. Adds a third `disposition` value
 * ('wet_ink') to the shape §15's migration already built, plus the two
 * columns that value needs: where the uploaded scan lives, and how an
 * uploaded-in-error scan gets corrected without ever being edited in place
 * or destroyed (non-negotiable #1 — soft delete/archive only, applied here
 * as "superseded", the same append-only-evidence shape §3a/§15.2a already
 * use elsewhere in this spec).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->string('wet_ink_upload_path')->nullable()->after('party_signature_path');
            $table->timestamp('superseded_at')->nullable()->after('disposition_recorded_at');
            $table->unsignedBigInteger('superseded_by_signature_id')->nullable()->after('superseded_at');

            $table->foreign('superseded_by_signature_id')
                ->references('id')->on('rental_inspection_signatures')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->dropForeign(['superseded_by_signature_id']);
            $table->dropColumn(['wet_ink_upload_path', 'superseded_at', 'superseded_by_signature_id']);
        });
    }
};
