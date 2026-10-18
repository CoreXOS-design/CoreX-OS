<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-451 — a dropped alert is never silent. When NotificationDispatcher has nothing to deliver on because the
 * recipient has every channel switched off, it writes one row here (event, intended recipient, reason). Kept apart
 * from notification_dispatch_log on purpose: that table is the "already told them" idempotency ledger, and a row
 * for an alert that was NOT delivered would stop the same alert reaching the user once they switch alerts back on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_drop_log')) {
            return;
        }

        Schema::create('notification_drop_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('notification_event_type_id')->nullable();
            $table->string('event_key', 120);
            $table->string('subject_type', 190)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('reason', 60);
            $table->timestamp('threshold_hit_at')->nullable();
            $table->timestamp('dropped_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'event_key', 'subject_type', 'subject_id'], 'notification_drop_log_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_drop_log');
    }
};
