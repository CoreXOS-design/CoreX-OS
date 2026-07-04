<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-158 WS-R3 (Ruling 2) — the DR2 pipeline BM-approval gate ships OFF by
 * default and is agency-configurable. When false (default), completing a
 * status-trigger step applies its status immediately (the pipeline is a pure
 * tracking overlay). When an agency opts in, the WS0 hold-for-BM flow applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('deal_v2_bm_approval_enabled')->default(false)->after('maintenance_mode');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('deal_v2_bm_approval_enabled');
        });
    }
};
