<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-329 — record WHY a trigger-fired work-order send failed, so a per-order failure
 * (e.g. the recipient has no email) is surfaced to the agent instead of being swallowed.
 * `status` gains a 'failed' value alongside 'pending'|'sent'; `send_error` holds the reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_step_work_orders', function (Blueprint $table) {
            $table->text('send_error')->nullable()->after('sent_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('deal_step_work_orders', function (Blueprint $table) {
            $table->dropColumn('send_error');
        });
    }
};
