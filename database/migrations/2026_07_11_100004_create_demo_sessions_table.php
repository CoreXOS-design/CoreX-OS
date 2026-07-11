<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-230 Demo Access Control — one row per browser session inside the demo.
 *
 * Spec: .ai/specs/demo-access-control.md §4.4
 *
 * Lives on PRIMARY, not on the demo host. The demo database is wiped every 3
 * days (§6.7), so a session table there would be telemetry that deletes itself
 * — which is worse than no telemetry, because it looks like it works.
 *
 * `session_token` is the UUID carried in the demo host's signed
 * `corex_demo_session` cookie. The gate re-checks it against primary on every
 * request (cached 60s) — that round trip is what makes revoke bite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('demo_sessions')) {
            return;
        }

        Schema::create('demo_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('demo_access_grant_id');
            $table->char('session_token', 36)->unique();
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['demo_access_grant_id', 'started_at'], 'demo_sessions_grant_idx');

            $table->foreign('demo_access_grant_id', 'demo_sessions_grant_fk')
                  ->references('id')->on('demo_access_grants')
                  ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_sessions');
    }
};
