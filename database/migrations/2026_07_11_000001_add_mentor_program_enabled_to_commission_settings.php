<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mentor programme becomes an explicit on/off switch, mirroring
 * revenue_share_enabled.
 *
 * Default TRUE on purpose: until now the engine applied the mentor fee whenever
 * an AgentMentor row existed, so every agency was effectively running the
 * programme. Defaulting to false would silently change the payout for any agency
 * that already has mentored agents. True preserves today's behaviour exactly;
 * agencies that don't run a mentor programme switch it off in Settings and the
 * mentor fee stops being charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('commission_settings', 'mentor_program_enabled')) {
                $table->boolean('mentor_program_enabled')
                    ->default(true)
                    ->after('monthly_platform_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('commission_settings', function (Blueprint $table) {
            if (Schema::hasColumn('commission_settings', 'mentor_program_enabled')) {
                $table->dropColumn('mentor_program_enabled');
            }
        });
    }
};
