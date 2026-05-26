<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->string('event_nature', 20)->default('actionable')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->dropColumn('event_nature');
        });
    }
};
