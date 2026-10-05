<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §43 — "log every notification on the
 * inspection." Append-only audit trail, same convention as
 * signed_document_distribution_logs (one row per attempted send, success
 * or failure, no updated_at — a correction is a new row, never an edit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->foreignId('rental_inspection_id')->constrained('rental_inspections')->cascadeOnDelete();

            $table->string('event', 20); // scheduled | rescheduled | cancelled | reminder
            $table->string('party_role', 20); // tenant | landlord | inspector
            $table->unsignedBigInteger('recipient_contact_id')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->string('channel', 20); // mail | whatsapp
            $table->string('recipient')->nullable(); // email or phone shown to the recipient
            $table->string('status', 20); // sent | failed | queued | skipped
            $table->text('error')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['rental_inspection_id', 'created_at'], 'rin_inspection_created_index');
            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_notifications');
    }
};
