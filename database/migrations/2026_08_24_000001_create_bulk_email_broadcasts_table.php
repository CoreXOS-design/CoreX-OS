<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk Email Broadcasts — the audit log of System-Owner-authored emails sent
 * to every CoreX user or to one specific agency's users.
 *
 * Spec: .ai/specs/system-updates-bulk-email.md §4.1.
 *
 * DELIBERATELY NO BelongsToAgency (same documented exception as system_updates,
 * spec §3): this is an immutable log of an email that was already sent, authored
 * by the System Owner, addressed across tenants by design. `target_agency_id` is a
 * plain nullable column recording WHO a given broadcast targeted — not a tenancy
 * scope. Write access is owner_only, so a global table cannot become a cross-tenant
 * leak vector.
 *
 * No soft deletes: a sent email cannot be un-sent, so there is nothing to archive
 * or restore — the row is a permanent record of what happened, same treatment as
 * system_update_views.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_email_broadcasts', function (Blueprint $table) {
            $table->id();

            $table->string('subject', 200);
            $table->text('body');

            // 'all' | 'agency' — app-level allow-list, not a DB enum, so a future
            // target kind never needs an ALTER TABLE on a live database.
            $table->string('target_type', 20);

            $table->foreignId('target_agency_id')->nullable()
                  ->constrained('agencies')->nullOnDelete();

            // Snapshot of how many users the send actually queued to, computed
            // server-side at send time — never the client-submitted count.
            $table->unsignedInteger('recipient_count');

            $table->foreignId('sent_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['target_type', 'target_agency_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_email_broadcasts');
    }
};
