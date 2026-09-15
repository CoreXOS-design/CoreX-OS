<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, Johan's ruling 4 — the live link stays live for the
 * buyer (one permanent link, always current stock); "all share history
 * is INTERNAL." An append-only log of share events, never shown to the
 * buyer, that also feeds ruling 2's working clock (a share resets it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_match_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_match_id')->constrained('contact_matches')->cascadeOnDelete();
            $table->foreignId('shared_by_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('channel', ['copy_link', 'whatsapp', 'email', 'other'])->nullable();
            $table->timestamp('shared_at');
            $table->timestamps();

            $table->index(['contact_match_id', 'shared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_match_shares');
    }
};
