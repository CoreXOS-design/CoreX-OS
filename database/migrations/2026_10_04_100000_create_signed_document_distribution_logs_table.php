<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §41, 2026-09-28 — Johan's ruling: the report/share build is a SHARED
 * service (App\Services\Distribution\SignedDocumentDistributionService),
 * not inspection-specific, because cc2 wires the Inventory module to the
 * same service next. This log is that service's own audit trail —
 * polymorphic (distributable_type/id) from day one so it never needs a
 * schema change when a second consumer (Inventory) starts writing rows
 * to it. Append-only, same "immutable, one row per event" convention as
 * every other history table in this codebase (RentalInspectionObservation,
 * RentalInspectionRoomNote) — no UPDATED_AT, a correction is a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signed_document_distribution_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('distributable_type');
            $table->unsignedBigInteger('distributable_id');
            $table->string('channel'); // 'filed' | 'email' | 'public_link'
            $table->string('mode')->nullable(); // 'auto' | 'manual', email channel only
            $table->string('recipient_role')->nullable(); // 'tenant' | 'landlord' | 'agent_cc'
            $table->unsignedBigInteger('recipient_contact_id')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('status'); // 'sent' | 'failed'
            $table->string('message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('sent_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            // Explicit short name — Laravel's auto-generated name for this
            // pair (signed_document_distribution_logs_distributable_type_
            // distributable_id_index) exceeds MySQL's 64-char identifier
            // limit (SQLSTATE 42000, error 1059), found running this
            // migration for real.
            $table->index(['distributable_type', 'distributable_id'], 'sddl_distributable_index');
            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signed_document_distribution_logs');
    }
};
