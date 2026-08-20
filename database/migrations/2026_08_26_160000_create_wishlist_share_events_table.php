<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyers Report (Johan, 2026-08-20) — the one genuine gap found when verifying
 * his belief that WhatsApp wishlist shares are already tracked: they are not,
 * on any channel. The only in-app share action is "Copy Link"
 * (resources/views/command-center/buyers/detail.blade.php), which records
 * nothing when clicked. Narrowed scope, per Johan: record the copy-link
 * action itself — small, self-contained, starts building history from today.
 *
 * Deliberately event-log shaped (one row per copy), not a single "shared_at"
 * column on buyer_portal_links — an agent may copy the link more than once
 * over a buyer's lifecycle, and "how many times, and when was the last one"
 * is exactly what the report needs to show. channel is nullable and
 * defaults to 'link_copy' now; the column exists so a future dedicated
 * "Share via WhatsApp" action (should one ever get built) can log through
 * the same table without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_share_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            // Nullable: buyer_portal_links.contact_id is the per-buyer share link;
            // a specific wishlist (contact_matches) may be null when the shared
            // link is the buyer's whole portal, not one wishlist's own share_token.
            $table->unsignedBigInteger('contact_match_id')->nullable();
            $table->string('channel', 20)->default('link_copy'); // 'link_copy' | future: 'whatsapp', 'email'
            $table->foreignId('shared_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('shared_at');
            $table->timestamps();

            $table->index(['agency_id', 'contact_id', 'shared_at'], 'wse_agency_contact_shared_idx');
            $table->index(['shared_by_user_id', 'shared_at'], 'wse_agent_shared_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_share_events');
    }
};
