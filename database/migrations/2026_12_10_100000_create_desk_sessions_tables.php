<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CoreX Desk (.ai/specs/corex-desk.md section 5) - the permanent audit of every in-app screen-share session, plus the
 * agency's on/off switch.
 *
 *  - desk_sessions        one row per session: who asked, which support user joined, when, how it ended. The 6-digit code is NOT
 *                         stored here - it lives hashed in the canonical `otps` store (OtpService); otp_id points at it.
 *  - desk_session_events  append-only trail per session (page, highlight, pointer on/off, ended ...). The screen content itself
 *                         is never stored.
 *  - agencies.desk_enabled  default ON; when off the agency cannot start a session.
 *
 * Soft deletes on both tables - nothing is ever hard-deleted (non-negotiable 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('desk_sessions')) {
            Schema::create('desk_sessions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->nullable()->index();
                $table->foreignId('user_id')->constrained('users');
                $table->foreignId('support_user_id')->nullable()->constrained('users');
                $table->string('status', 16)->default('waiting');          // waiting | live | ended
                $table->unsignedBigInteger('otp_id')->nullable();
                $table->timestamp('code_expires_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('ended_by', 16)->nullable();                // agent | support | logout | timeout | expired | disabled | replaced
                $table->foreignId('ended_by_user_id')->nullable()->constrained('users');
                $table->string('page_url_last', 255)->nullable();          // path only, never a query string
                $table->timestamp('last_heartbeat_at')->nullable();
                $table->timestamp('leaving_at')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'agency_id']);
                $table->index(['user_id', 'status']);
                $table->index('started_at');
            });
        }

        if (! Schema::hasTable('desk_session_events')) {
            Schema::create('desk_session_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('desk_session_id')->constrained('desk_sessions');
                $table->unsignedBigInteger('agency_id')->nullable()->index();
                $table->string('type', 32);                                // created | joined | page | highlight | highlight_cleared | pointer_on | pointer_off | ended
                $table->json('payload')->nullable();
                $table->foreignId('actor_user_id')->nullable()->constrained('users');
                $table->timestamp('created_at')->useCurrent();
                $table->softDeletes();

                $table->index(['desk_session_id', 'created_at']);
            });
        }

        if (! Schema::hasColumn('agencies', 'desk_enabled')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->boolean('desk_enabled')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'desk_enabled')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropColumn('desk_enabled');
            });
        }
        Schema::dropIfExists('desk_session_events');
        Schema::dropIfExists('desk_sessions');
    }
};
