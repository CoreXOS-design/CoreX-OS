<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One buyer-level, permanent Client Page link (Johan, 2026-08-24): "the first
 * link created stays with this person, and if more wishlists are added they
 * can use it from the same link." Additive — every existing
 * contact_matches.share_slug/share_token keeps resolving exactly as before
 * (SharedMatchController still checks those first); this table is the NEW
 * canonical target the Share button on the buyer header points at going
 * forward, resolved via the SAME /shared/match/{token} route.
 *
 * Deliberately a NEW table rather than extending buyer_portal_links, which
 * already exists with almost this shape (contact_id, token, revoked_at) but
 * drives a materially different, already-shipped feature: a two-way
 * self-service portal (buyer_property_responses — interested/not_interested/
 * viewing_requested) with its own "Generate Buyer Portal Link" UI gate that
 * checks for ANY active row regardless of purpose. Reusing it as-is would
 * hide that button whenever a Client Page link already exists, and would mix
 * two unrelated link types in the one agent-facing list that already reads
 * buyer_portal_links unfiltered (command-center/buyers/detail.blade.php).
 * Investigated before building, not assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_client_page_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->timestamps();

            $table->unique('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_client_page_links');
    }
};
