<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05: the notice
 * dialog's single "put back on the market" tick is replaced by a three-way
 * choice the agent must make explicitly every time (readvertise / withdraw /
 * leave as is) — nothing pre-selected. `notice_readvertised` (boolean) could
 * only ever represent two of those three states, so it is replaced outright
 * rather than kept alongside a second column.
 *
 * Backfill: a lease with an active notice and notice_readvertised=true maps
 * to 'readvertise'; a lease with an active notice and notice_readvertised
 * false/null maps to 'leave' (the old unticked behaviour — property
 * untouched, exactly what 'leave' means); a lease with no active notice at
 * all gets null (nothing to migrate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->string('notice_outcome')->nullable()->after('move_out_date');
        });

        DB::table('leases')->whereNotNull('notice_date')->where('notice_readvertised', true)->update(['notice_outcome' => 'readvertise']);
        DB::table('leases')->whereNotNull('notice_date')->where(function ($q) {
            $q->where('notice_readvertised', false)->orWhereNull('notice_readvertised');
        })->update(['notice_outcome' => 'leave']);

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('notice_readvertised');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->boolean('notice_readvertised')->nullable()->after('move_out_date');
        });

        DB::table('leases')->where('notice_outcome', 'readvertise')->update(['notice_readvertised' => true]);
        DB::table('leases')->whereNotNull('notice_outcome')->where('notice_outcome', '!=', 'readvertise')->update(['notice_readvertised' => false]);

        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('notice_outcome');
        });
    }
};
