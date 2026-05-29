<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['user_dashboard_settings', 'agency_dashboard_settings'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'notify_push')) {
                    $t->boolean('notify_push')->default(true)->after('notify_email');
                }
                if (! Schema::hasColumn($table, 'open_hours_enabled')) {
                    $t->boolean('open_hours_enabled')->default(false)->after('notify_push');
                }
                if (! Schema::hasColumn($table, 'open_hours_start')) {
                    $t->time('open_hours_start')->default('07:00:00')->after('open_hours_enabled');
                }
                if (! Schema::hasColumn($table, 'open_hours_end')) {
                    $t->time('open_hours_end')->default('21:00:00')->after('open_hours_start');
                }
                if (! Schema::hasColumn($table, 'min_minutes_between_same')) {
                    $t->unsignedSmallInteger('min_minutes_between_same')->default(360)->after('open_hours_end');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['user_dashboard_settings', 'agency_dashboard_settings'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['min_minutes_between_same', 'open_hours_end', 'open_hours_start', 'open_hours_enabled', 'notify_push'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
