<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-371 (#18) — per-STEP guided-tour persistence. A tour previously recorded only a single
 * completed_at at the END of the tour; a page/section update (or missing anchors at auto-start)
 * meant the tour never reached its end, nothing persisted, and the WHOLE tour re-triggered next
 * visit. This column records the individual steps a user has already SEEN, keyed by each step's
 * stable `element` selector — so a completed step never re-triggers, even across deploys (the key
 * is content-stable, not an index). Backward-compatible: null = no per-step progress recorded yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_tour_progress', function (Blueprint $table) {
            $table->json('completed_steps')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_tour_progress', function (Blueprint $table) {
            $table->dropColumn('completed_steps');
        });
    }
};
