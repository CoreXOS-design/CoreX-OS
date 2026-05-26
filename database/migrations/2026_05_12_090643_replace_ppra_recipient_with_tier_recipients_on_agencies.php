<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill: read existing single value before dropping
        $agencies = DB::table('agencies')
            ->whereNotNull('whistleblow_ppra_recipient_email')
            ->get(['id', 'whistleblow_ppra_recipient_email']);

        Schema::table('agencies', function (Blueprint $table) {
            $table->json('whistleblow_tier_recipients')->nullable()->after('whistleblow_compliance_officer_email');
        });

        // Migrate existing single email to all 3 tiers
        foreach ($agencies as $a) {
            $email = $a->whistleblow_ppra_recipient_email;
            if ($email) {
                DB::table('agencies')->where('id', $a->id)->update([
                    'whistleblow_tier_recipients' => json_encode([
                        'tier_1' => [$email],
                        'tier_2' => [$email],
                        'tier_3' => [$email],
                    ]),
                ]);
            }
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('whistleblow_ppra_recipient_email');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('whistleblow_ppra_recipient_email')->nullable()->after('whistleblow_compliance_officer_email');
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('whistleblow_tier_recipients');
        });
    }
};
