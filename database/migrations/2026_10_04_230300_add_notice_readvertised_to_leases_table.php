<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §15 (GATE 2) row 2 — whether the "put this
 * property back on the market" tick was applied when notice was recorded.
 * Needed so reversing the notice knows whether a property-status change
 * must also be undone, without re-deriving it from anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->boolean('notice_readvertised')->nullable()->after('move_out_date');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('notice_readvertised');
        });
    }
};
