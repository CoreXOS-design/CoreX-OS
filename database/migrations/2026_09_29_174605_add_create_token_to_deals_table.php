<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency token for Dr2DealRegisterController::store() — a hidden
 * one-time form value. A second POST carrying the same token (double-click,
 * Enter+click, or a network-level retry) is recognised as a resubmission of
 * the SAME deal and returns the deal already created, instead of creating a
 * second one. Nullable + unique: most rows will never carry one (edits,
 * pre-existing/backfilled deals), and NULL is exempt from a unique index in
 * MySQL, so any number of NULLs coexist — only a genuine token collision
 * (the resubmission case) is rejected at the DB layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('create_token', 64)->nullable()->after('deal_no');
            $table->unique('create_token');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropUnique(['create_token']);
            $table->dropColumn('create_token');
        });
    }
};
