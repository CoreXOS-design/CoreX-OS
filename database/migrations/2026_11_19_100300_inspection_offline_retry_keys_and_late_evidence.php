<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-19 (B8, B9, B17) - what an offline queue needs from the server.
 *
 *  - rental_inspection_observations.received_at   - when the server RECEIVED an observation whose own `created_at` is the app's captured time (B8).
 *  - rental_inspection_observations.added_after_signing_at / rental_inspection_photos.added_after_signing_at - evidence that arrived late, after
 *    the report was signed or completed, stored and flagged instead of refused (B9, spec 14.4 case 4).
 *  - client_idempotency_key on room notes, photo notes and signatures (B17) - a retry of the same write returns the row already stored, never a second one.
 *    Unique per inspection (not globally), so a key reused on another inspection can never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_observations', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('created_at');
            $table->timestamp('added_after_signing_at')->nullable()->after('received_at');
        });

        Schema::table('rental_inspection_photos', function (Blueprint $table) {
            $table->timestamp('added_after_signing_at')->nullable()->after('taken_at_source');
        });

        Schema::table('rental_inspection_room_notes', function (Blueprint $table) {
            $table->char('client_idempotency_key', 36)->nullable()->after('created_by_user_id');
            $table->unique(['rental_inspection_id', 'client_idempotency_key'], 'ri_room_notes_insp_key_unique');
        });

        Schema::table('rental_inspection_photo_notes', function (Blueprint $table) {
            $table->char('client_idempotency_key', 36)->nullable()->after('created_by_user_id');
            $table->unique(['rental_inspection_id', 'client_idempotency_key'], 'ri_photo_notes_insp_key_unique');
        });

        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->char('client_idempotency_key', 36)->nullable()->after('recorded_by_user_id');
            $table->unique(['rental_inspection_id', 'client_idempotency_key'], 'ri_signatures_insp_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->dropUnique('ri_signatures_insp_key_unique');
            $table->dropColumn('client_idempotency_key');
        });
        Schema::table('rental_inspection_photo_notes', function (Blueprint $table) {
            $table->dropUnique('ri_photo_notes_insp_key_unique');
            $table->dropColumn('client_idempotency_key');
        });
        Schema::table('rental_inspection_room_notes', function (Blueprint $table) {
            $table->dropUnique('ri_room_notes_insp_key_unique');
            $table->dropColumn('client_idempotency_key');
        });
        Schema::table('rental_inspection_photos', function (Blueprint $table) {
            $table->dropColumn('added_after_signing_at');
        });
        Schema::table('rental_inspection_observations', function (Blueprint $table) {
            $table->dropColumn(['received_at', 'added_after_signing_at']);
        });
    }
};
