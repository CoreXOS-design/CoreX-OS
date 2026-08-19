<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-owner / ownership-history capture (Johan 2026-08-19,
 * .ai/specs/deeds-capture.md §7). Ownership parsing failing must NEVER fail
 * the whole capture (Johan, binding) — the property still lands, and this is
 * where the reason lives so a screen can eventually show it. Default 'ok'
 * means "nothing to report" for every existing row and for every capture that
 * doesn't carry ownership_history_raw at all (the common single/simple-owner
 * path, §7.2, is untouched by this column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->string('ownership_parse_status', 20)->default('ok')->after('deeds_captured_at'); // 'ok' | 'warning' | 'failed'
            $table->text('ownership_parse_note')->nullable()->after('ownership_parse_status');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->dropColumn(['ownership_parse_status', 'ownership_parse_note']);
        });
    }
};
