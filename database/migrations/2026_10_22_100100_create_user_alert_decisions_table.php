<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-451 — the append-only record of what was decided when a user had every alert switched off:
 * "accepted_off" (the user or an admin accepted it) or "turned_on" (in-app alerts switched back on from the
 * warning bar / the agency list). One row per decision, never edited; the switch state is stored as it stood
 * once the decision took effect. settings_stamp ties an acceptance to the notification-settings version it
 * was made against, so the warning bar returns if the settings are changed and are still all off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_alert_decisions')) {
            return;
        }

        Schema::create('user_alert_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->string('decision', 20); // accepted_off | turned_on
            $table->boolean('switch_in_app');
            $table->boolean('switch_email');
            $table->boolean('switch_push');
            $table->timestamp('settings_stamp')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'id'], 'user_alert_decisions_user_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_alert_decisions');
    }
};
