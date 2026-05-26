<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename event_class 'valuation' → 'property_evaluation' across
 * calendar_event_class_settings + calendar_events.category.
 * HFC uses "evaluation" / "property evaluation", never "valuation".
 */
return new class extends Migration {
    public function up(): void
    {
        // Rename class setting (system default)
        DB::table('calendar_event_class_settings')
            ->where('event_class', 'valuation')
            ->update([
                'event_class' => 'property_evaluation',
                'label'       => 'Property evaluation',
            ]);

        // Rename category on all calendar events
        DB::table('calendar_events')
            ->where('category', 'valuation')
            ->update(['category' => 'property_evaluation']);
    }

    public function down(): void
    {
        DB::table('calendar_event_class_settings')
            ->where('event_class', 'property_evaluation')
            ->update([
                'event_class' => 'valuation',
                'label'       => 'Property valuation',
            ]);

        DB::table('calendar_events')
            ->where('category', 'property_evaluation')
            ->update(['category' => 'valuation']);
    }
};
