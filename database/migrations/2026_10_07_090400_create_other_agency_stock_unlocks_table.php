<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/other-agency-stock.md §8a (Johan) — append-only event log for the
 * "request edit access" flow that temporarily unlocks an Other Agency Stock
 * property's imported advert content. THREE event types share one table
 * (never updated, never deleted — see OtherAgencyStockUnlock's overridden
 * save()/delete()):
 *
 *   'requested' — an agent asks to edit (requested_by_user_id, reason)
 *   'approved'/'declined' — an authorised user decides (decided_by_user_id,
 *       request_id points back at the 'requested' row it resolves)
 *   'relocked' — an authorised user re-locks it, OR a re-import re-locks it
 *       automatically (relocked_by_user_id)
 *
 * Current state is ALWAYS derived from the latest row for a property
 * (ORDER BY id DESC LIMIT 1) — never a separate mutable "status" column, so
 * there is nothing to drift out of sync with the append-only history:
 *   latest.event_type = 'approved'                → UNLOCKED
 *   latest.event_type = 'requested'                → PENDING
 *   'declined' / 'relocked' / no rows at all       → LOCKED
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_agency_stock_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            $table->string('event_type', 16); // 'requested' | 'approved' | 'declined' | 'relocked'

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();

            $table->foreignId('request_id')->nullable()->constrained('other_agency_stock_unlocks')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('relocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['property_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_agency_stock_unlocks');
    }
};
