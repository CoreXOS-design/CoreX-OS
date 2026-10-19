<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-19 B28 (non-negotiable #1) - "Undo" on a "nothing in this room" mark hard-deleted the row. It is now archived: the row stays
 * with who undid it and when, and marking the room again brings the same row back (the one-row-per-inventory-room unique index stays).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_room_marks', function (Blueprint $table) {
            $table->unsignedBigInteger('unmarked_by_user_id')->nullable()->after('marked_empty_at');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_room_marks', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('unmarked_by_user_id');
        });
    }
};
