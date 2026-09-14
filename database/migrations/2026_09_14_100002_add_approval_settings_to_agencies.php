<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-sign compliance approval gate — spec §5.2.
 *
 *  - esign_approval_route      : 'full_status' (default — full-status practitioners send without
 *                                approval; candidates always route to a full-status practitioner)
 *                                | 'ro_co' (a Reporting Officer approves every send; the
 *                                Compliance Officer overrides). Ruling 6.
 *  - whistleblow_ro_can_submit : may compliance-reporting ROs (not only the CO) approve a report
 *                                and send it onward to the PPRA. Ruling 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'esign_approval_route')) {
                $table->string('esign_approval_route', 16)->default('full_status')->after('fica_referral_recipient_user_id');
            }
            if (! Schema::hasColumn('agencies', 'whistleblow_ro_can_submit')) {
                $table->boolean('whistleblow_ro_can_submit')->default(false)->after('whistleblow_tier_recipients');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['esign_approval_route', 'whistleblow_ro_can_submit'] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
