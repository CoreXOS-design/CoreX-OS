<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->boolean('buyer_facing')->default(false)->after('allow_multiple_properties');
        });

        // Viewing is buyer-facing by default
        DB::table('calendar_event_class_settings')
            ->where('event_class', 'viewing')
            ->update(['buyer_facing' => true]);
    }

    public function down(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->dropColumn('buyer_facing');
        });
    }
};
