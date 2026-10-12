<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 1 — .ai/specs/auctions.md §5.8. The audit trail for
 * auction-lot status transitions. Written only by AuctionLotStatusService,
 * never by a controller (§6.2). No soft-deletes on this table — it is
 * itself an audit log; nothing here is ever archived or restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_lot_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auction_lot_id', 'created_at'], 'auction_lot_status_history_lot_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_lot_status_history');
    }
};
