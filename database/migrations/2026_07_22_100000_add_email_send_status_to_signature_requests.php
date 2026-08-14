<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-294 — honest per-recipient email delivery state for the e-sign ceremony.
 *
 * The invitation and completed-document emails were sent through try/catch blocks
 * that logged-and-swallowed failures; `sent_at` was even written BEFORE the send,
 * so a failed invitation still read "sent". These columns record the real outcome
 * of each of the two recipient emails so a failed send surfaces to the agent (and
 * can be resent) instead of silently parking the ceremony.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            // Invitation email (party's turn to sign)
            $table->string('invite_send_status', 20)->nullable()->after('sent_at');       // sent | failed
            $table->text('invite_send_error')->nullable()->after('invite_send_status');
            // Completed signed-document email (after completion)
            $table->string('completion_send_status', 20)->nullable()->after('invite_send_error'); // sent | failed
            $table->text('completion_send_error')->nullable()->after('completion_send_status');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn([
                'invite_send_status',
                'invite_send_error',
                'completion_send_status',
                'completion_send_error',
            ]);
        });
    }
};
