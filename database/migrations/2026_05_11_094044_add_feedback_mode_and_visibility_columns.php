<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add feedback_mode to class settings (drives which feedback form to show)
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->string('feedback_mode', 30)->default('per_contact')->after('completion_behaviour');
        });

        // Set known modes
        DB::table('calendar_event_class_settings')
            ->where('event_class', 'viewing')
            ->update(['feedback_mode' => 'per_contact']);
        DB::table('calendar_event_class_settings')
            ->where('event_class', 'listing_presentation')
            ->update(['feedback_mode' => 'per_property']);

        // 2. Add feedback_kind + visibility + kind_specific_data to feedback table
        Schema::table('calendar_event_feedback', function (Blueprint $table) {
            $table->string('feedback_kind', 30)->default('viewing')->after('contact_id');
            $table->string('visibility', 30)->default('public_to_seller')->after('branch_id');
            $table->json('kind_specific_data')->nullable()->after('visibility');
        });

        // Backfill existing rows
        DB::table('calendar_event_feedback')
            ->whereNull('deleted_at')
            ->update([
                'feedback_kind' => 'viewing',
                'visibility' => 'public_to_seller',
            ]);
    }

    public function down(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->dropColumn('feedback_mode');
        });
        Schema::table('calendar_event_feedback', function (Blueprint $table) {
            $table->dropColumn(['feedback_kind', 'visibility', 'kind_specific_data']);
        });
    }
};
