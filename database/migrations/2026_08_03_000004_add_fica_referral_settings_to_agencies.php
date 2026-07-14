<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-236 — agency-configurable Refer-to-CO settings (Johan: defaults ON / primary CO).
 *
 *  - fica_referral_enabled            : is the "Refer to CO" action available? (default ON)
 *  - fica_referral_recipient_user_id  : which CO receives referrals (null = the primary CO)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'fica_referral_enabled')) {
                $table->boolean('fica_referral_enabled')->default(true)->after('id');
            }
            if (! Schema::hasColumn('agencies', 'fica_referral_recipient_user_id')) {
                $table->unsignedBigInteger('fica_referral_recipient_user_id')->nullable()->after('fica_referral_enabled');
                $table->foreign('fica_referral_recipient_user_id')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'fica_referral_recipient_user_id')) {
                $table->dropForeign(['fica_referral_recipient_user_id']);
                $table->dropColumn('fica_referral_recipient_user_id');
            }
            if (Schema::hasColumn('agencies', 'fica_referral_enabled')) {
                $table->dropColumn('fica_referral_enabled');
            }
        });
    }
};
