<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-230 Demo Access Control — what the prospect actually looked at.
 *
 * Spec: .ai/specs/demo-access-control.md §4.5
 *
 * Written on PRIMARY, from a queued job on the demo host (§6.4). The whole path
 * FAILS OPEN: if primary is unreachable the view is dropped and logged, and the
 * demo page renders exactly as it would have. This is the deliberate inversion
 * of the gate (§6.3), which fails CLOSED — a demo page must never block, slow,
 * or error because a page-view could not be logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_page_views')) {
            return;
        }

        Schema::create('demo_page_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('demo_session_id');
            $table->string('path');
            $table->string('route_name')->nullable();
            $table->string('title')->nullable();
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->index(['demo_session_id', 'viewed_at'], 'demo_page_views_session_idx');

            $table->foreign('demo_session_id', 'demo_page_views_session_fk')
                  ->references('id')->on('demo_sessions')
                  ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_page_views');
    }
};
