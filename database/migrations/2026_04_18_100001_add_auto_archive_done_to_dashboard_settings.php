<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dashboard_settings', function (Blueprint $table) {
            // null = never auto-archive, 0 = archive immediately on mark-done, N = archive N days after completion
            $table->unsignedSmallInteger('auto_archive_done_days')->nullable()->after('event_reminder_hours_before');
        });

        Schema::table('agency_dashboard_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('auto_archive_done_days')->nullable()->after('event_reminder_hours_before');
        });
    }

    public function down(): void
    {
        Schema::table('user_dashboard_settings', function (Blueprint $table) {
            $table->dropColumn('auto_archive_done_days');
        });

        Schema::table('agency_dashboard_settings', function (Blueprint $table) {
            $table->dropColumn('auto_archive_done_days');
        });
    }
};
