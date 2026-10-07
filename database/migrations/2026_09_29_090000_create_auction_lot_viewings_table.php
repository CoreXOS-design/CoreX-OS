<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 5 — .ai/specs/auctions.md §5.6. Speced in Phase 1 but
 * deliberately deferred (see AuctionCalendarSource's own docblock) since
 * nothing consumed it yet. Built now because Phase 5's public lot page
 * and Website API auction block both need "scheduled viewing times" —
 * a real, spec-required dependency, not a speculative addition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_lot_viewings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_by_appointment')->default(false);
            $table->string('notes', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'auction_lot_id', 'starts_at'], 'auction_lot_viewings_lot_starts_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_lot_viewings');
    }
};
