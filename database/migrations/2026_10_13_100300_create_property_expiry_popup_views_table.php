<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-448 — "this user has been shown this listing in the expiring-soon popup".
 *
 * Spec: .ai/specs/at448-property-expiry.md §4.3a
 *
 * Andre's ruling (2026-10-07): a listing is announced in the Properties-page
 * popup ONCE — the first time the user opens Properties on or after the day it
 * enters the agency's warning window — and not again. That needs a durable,
 * per-user record (a session flag would re-announce on every new login).
 * Keyed on the expiry date the listing carried when shown: an extended date
 * is a new cycle and may announce again when it re-enters the window.
 *
 * Rows are a log; nothing deletes them (no delete path exists). agency_id is
 * nullable on purpose (Rule 17) — BelongsToAgency stamps it from the acting
 * user and must never 1452 on an owner outside the switcher.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('property_expiry_popup_views')) {
            return;
        }

        Schema::create('property_expiry_popup_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->date('expiry_date');
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'property_id', 'expiry_date'], 'pepv_user_property_expiry_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_expiry_popup_views');
    }
};
