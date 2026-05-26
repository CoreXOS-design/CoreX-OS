<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('prospecting_pitch_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('prospecting_listing_id')->constrained('prospecting_listings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('locked_at')->useCurrent();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('released_at')->nullable();
            // release_reason values: consumed_by_send | manual_release | auto_expired
            $table->string('release_reason', 50)->nullable();
            $table->timestamps();

            // Per-listing single-active-lock invariant is enforced by ProspectingClaimService
            // via lockForUpdate() inside a transaction — MySQL does not support partial unique
            // indexes (UNIQUE ... WHERE released_at IS NULL), so the constraint lives in code.
            $table->index(['prospecting_listing_id', 'released_at', 'expires_at'], 'idx_pitch_locks_active');
            $table->index(['agency_id', 'user_id'], 'idx_pitch_locks_agency_user');
            $table->index('expires_at', 'idx_pitch_locks_expires');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospecting_pitch_locks');
    }
};
