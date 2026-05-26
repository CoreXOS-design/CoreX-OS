<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR rerouting: when an agent leaves, their printed QR codes must keep
 * working by pointing at a live agent. We never move or reuse the slug
 * (it stays on the departed agent forever as the audit anchor) — instead
 * a nullable pointer says "scans of this agent's slug now resolve to user X".
 *
 * Spec: .ai/specs/agent-qr-onboarding.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('qr_reroute_user_id')->nullable()->after('qr_code_slug');
            $table->index('qr_reroute_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['qr_reroute_user_id']);
            $table->dropColumn('qr_reroute_user_id');
        });
    }
};
