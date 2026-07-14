<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-216 — pipeline due-date anchoring fix. The DR1 overlay projects EVERY step's
 * due date at attach time (deal_date + cumulative offset) for day-one RAG. When an
 * AND-gated step later activates, its due date must RE-ANCHOR to the actual latest
 * predecessor completion + offset — but activateStep preserved the stale projection
 * because it could not tell a system projection from a genuine agent edit.
 *
 * This flag is that distinction: false = system-projected (re-anchorable on
 * activation), true = an agent edited it inline (preserve, never overwrite).
 * Existing rows default false, so their projected dates correctly re-anchor on
 * their next activation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_step_instances', function (Blueprint $table) {
            $table->boolean('due_date_manual')->default(false)->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('deal_step_instances', function (Blueprint $table) {
            $table->dropColumn('due_date_manual');
        });
    }
};
