<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Part A1 — per-send delivery record.
 *
 * Each row = one channel send to one recipient. We denormalise enough so the
 * row stays meaningful even after a Contact is deleted (recipient_name,
 * recipient_email, recipient_phone, mode). The snapshot_link_id back-reference
 * keeps engagement metrics linked.
 *
 * sms is reserved in the enum for a future Bulk SMS or Twilio integration;
 * channel='copy' = "agent just wanted a URL to paste somewhere", which still
 * gets tracked as a delivery for analytics.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('presentation_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_link_id')->constrained('presentation_snapshot_links')->cascadeOnDelete();
            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->foreignId('sent_by_user_id')->constrained('users');

            $table->enum('channel', ['email', 'whatsapp', 'copy', 'sms']);
            $table->foreignId('recipient_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('recipient_name', 200);
            $table->string('recipient_email', 200)->nullable();
            $table->string('recipient_phone', 30)->nullable();

            $table->enum('mode', ['full', 'teaser']);
            $table->enum('status', ['queued', 'sent', 'failed', 'bounced', 'delivered', 'opened'])
                ->default('queued');
            $table->text('error_message')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();

            $table->string('whatsapp_url', 500)->nullable();
            $table->timestamp('whatsapp_click_through_at')->nullable();

            $table->string('subject_line', 200)->nullable();
            $table->text('message_body')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['presentation_id', 'sent_at']);
            $table->index('recipient_contact_id');
            $table->index(['channel', 'status']);
            $table->index('sent_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_deliveries');
    }
};
