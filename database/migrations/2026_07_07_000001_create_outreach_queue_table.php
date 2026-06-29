<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-117 §7 — outreach_queue: the deferred-outreach preparation/surfacing layer.
 *
 * An agent prepares a WhatsApp message (from a contact, the map, or the MIC) and
 * queues it with a due_at; a sweep flips it pending → surfaced at the due-time
 * (re-checking consent via canMarketTo), the agent works the list and taps Send
 * by hand, and on dispatch the row links to its canonical seller_outreach_sends
 * record. This table is ONLY persistence — no UI, no sweep yet.
 *
 * Tenant-owned: agency_id + AgencyScope (a queued row is never visible across
 * agencies). Soft-deletes only — cancel/archive is a soft delete; no hard
 * deletes ever. body_snapshot is REQUIRED: the MIC/map dispatch paths persist no
 * message text today, so the queue must carry the fully merge-rendered body.
 *
 * FKs confirmed: contact_id→contacts.id, property_id→properties.id (nullable),
 * agent_id→users.id, template_id→seller_outreach_templates.id (nullable),
 * seller_outreach_send_id→seller_outreach_sends.id (nullable), agency_id→agencies.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();      // the consent subject
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete(); // listing/match context
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();            // who prepared / works it
            $table->foreignId('template_id')->nullable()->constrained('seller_outreach_templates')->nullOnDelete();
            $table->foreignId('seller_outreach_send_id')->nullable()->constrained('seller_outreach_sends')->nullOnDelete();

            // 'whatsapp' for now; mirrors the seller-outreach channel enum, built
            // to extend. Default keeps the NOT-NULL column safe even if a caller omits it.
            $table->string('channel', 20)->default('whatsapp');
            // Which surface prepared the row: contact / map / mic.
            $table->string('source', 10);
            // The prepared, merge-rendered message — REQUIRED (MIC/map persist nothing today).
            $table->text('body_snapshot');
            // The deferred surface time.
            $table->dateTime('due_at');
            // pending → surfaced → sent ; or dropped (consent revoked) / expired / cancelled.
            $table->string('status', 12)->default('pending');

            $table->dateTime('claimed_at')->nullable();   // sweep claim (prevents double-surface)
            $table->dateTime('surfaced_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('dropped_reason')->nullable();  // e.g. marketingBlockReason at surface

            $table->timestamps();
            $table->softDeletes();

            // Sweep: WHERE status = pending AND due_at <= now.
            $table->index(['status', 'due_at']);
            // UI: an agent's queue by state.
            $table->index(['agent_id', 'status']);
            // Standalone due_at for any due-range read not led by status.
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_queue');
    }
};
