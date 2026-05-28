<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Map Workspace Phase B (Fix 2+3) — T-pin WhatsApp outreach flow.
 *
 * Two structural changes wired through one migration so the new
 * EntryPointController::fromTrackedProperty path is fully backed:
 *
 *  1. tracked_properties.owner_contact_id — the owner Contact a T-pin
 *     captures during the "WhatsApp / Pitch" flow. Nullable; only set
 *     once an agent actually captures contact details for the TP.
 *
 *  2. prospecting_pitch_locks.tracked_property_id — lets the existing
 *     temp-lock pattern (30-min soft lock to prevent two agents racing
 *     the same contact-capture) apply to T-pins as well as portal
 *     listings. The existing prospecting_listing_id stays — locks
 *     reference EITHER a listing OR a TP, never both, so we make
 *     prospecting_listing_id nullable and let the service enforce the
 *     XOR in code.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->foreignId('owner_contact_id')
                  ->nullable()
                  ->after('promoted_by_user_id')
                  ->constrained('contacts')
                  ->nullOnDelete();
            $table->index('owner_contact_id', 'idx_tracked_props_owner_contact');
        });

        Schema::table('prospecting_pitch_locks', function (Blueprint $table) {
            $table->foreignId('tracked_property_id')
                  ->nullable()
                  ->after('prospecting_listing_id')
                  ->constrained('tracked_properties')
                  ->cascadeOnDelete();
            $table->index(
                ['tracked_property_id', 'released_at', 'expires_at'],
                'idx_pitch_locks_tp_active',
            );
        });

        // Make prospecting_listing_id nullable so a TP-only lock can omit it.
        // Done in a SEPARATE table call because some MySQL versions reject
        // mixing column adds with column changes in one Schema::table().
        Schema::table('prospecting_pitch_locks', function (Blueprint $table) {
            $table->foreignId('prospecting_listing_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_pitch_locks', function (Blueprint $table) {
            $table->foreignId('prospecting_listing_id')->nullable(false)->change();
        });

        Schema::table('prospecting_pitch_locks', function (Blueprint $table) {
            $table->dropForeign(['tracked_property_id']);
            $table->dropIndex('idx_pitch_locks_tp_active');
            $table->dropColumn('tracked_property_id');
        });

        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->dropForeign(['owner_contact_id']);
            $table->dropIndex('idx_tracked_props_owner_contact');
            $table->dropColumn('owner_contact_id');
        });
    }
};
