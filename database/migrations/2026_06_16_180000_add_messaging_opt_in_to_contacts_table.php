<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-45 — Contact messaging OPT-IN marker.
 *
 * Mirrors the opt-out triplet added in 2026_05_14_080004. Opt-in is a recorded
 * FACT — explicit consent, e.g. a seller replying YES to a consent-request
 * message. It is captured for compliance + re-engagement and does NOT alter the
 * send gate; opt-out (messaging_opt_out_at) remains the hard block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('messaging_opted_in_at')->nullable();
            $table->string('messaging_opt_in_reason', 255)->nullable();
            $table->unsignedBigInteger('messaging_opt_in_recorded_by_user_id')->nullable();

            $table->foreign('messaging_opt_in_recorded_by_user_id', 'contacts_msg_optin_recorded_by_fk')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index('messaging_opted_in_at', 'contacts_messaging_opted_in_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign('contacts_msg_optin_recorded_by_fk');
            $table->dropIndex('contacts_messaging_opted_in_at_idx');
            $table->dropColumn([
                'messaging_opt_in_recorded_by_user_id',
                'messaging_opt_in_reason',
                'messaging_opted_in_at',
            ]);
        });
    }
};
