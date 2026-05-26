<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-property events support (Module 2.5):
 * - Add property_id to calendar_event_feedback (nullable for backward compat)
 * - Add allow_multiple_properties to calendar_event_class_settings
 * - Backfill existing feedback rows with their event's property_id
 * - Backfill existing events to ensure property_id has a link row
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Add property_id to feedback table + new unique index (idempotent)
        if (!Schema::hasColumn('calendar_event_feedback', 'property_id')) {
            Schema::table('calendar_event_feedback', function (Blueprint $table) {
                $table->foreignId('property_id')->nullable()->after('contact_id')
                      ->constrained('properties')->nullOnDelete();
            });
        } else {
            // Column exists from partial run — add FK if missing
            $fks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_event_feedback' AND COLUMN_NAME = 'property_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
            if (empty($fks)) {
                Schema::table('calendar_event_feedback', function (Blueprint $table) {
                    $table->foreign('property_id')->references('id')->on('properties')->nullOnDelete();
                });
            }
        }

        // Add new composite unique index
        $idxExists = DB::select("SHOW INDEX FROM calendar_event_feedback WHERE Key_name = 'cef_event_contact_property_unique'");
        if (empty($idxExists)) {
            Schema::table('calendar_event_feedback', function (Blueprint $table) {
                $table->unique(
                    ['calendar_event_id', 'contact_id', 'property_id'],
                    'cef_event_contact_property_unique'
                );
            });
        }

        // 2. Add allow_multiple_properties to class settings
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->boolean('allow_multiple_properties')->default(false)->after('daily_digest_roles');
        });

        // 3. Seed: viewing class gets allow_multiple_properties = true
        DB::table('calendar_event_class_settings')
            ->where('event_class', 'viewing')
            ->update(['allow_multiple_properties' => true]);

        // 4. Backfill: set feedback.property_id from event's property_id for single-property events
        DB::statement("
            UPDATE calendar_event_feedback AS cef
            INNER JOIN calendar_events AS ce ON ce.id = cef.calendar_event_id
            SET cef.property_id = ce.property_id
            WHERE cef.property_id IS NULL
              AND ce.property_id IS NOT NULL
        ");

        // 5. Backfill: ensure events with property_id have a link row
        $events = DB::table('calendar_events')
            ->whereNotNull('property_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('calendar_event_links')
                    ->whereColumn('calendar_event_links.calendar_event_id', 'calendar_events.id')
                    ->where('calendar_event_links.linkable_type', 'App\\Models\\Property')
                    ->whereColumn('calendar_event_links.linkable_id', 'calendar_events.property_id')
                    ->where('calendar_event_links.role', 'subject_property');
            })
            ->get(['id', 'property_id', 'user_id']);

        $now = now();
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'calendar_event_id' => $event->id,
                'linkable_type' => 'App\\Models\\Property',
                'linkable_id' => $event->property_id,
                'role' => 'subject_property',
                'created_by_user_id' => $event->user_id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if (!empty($rows)) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('calendar_event_links')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->dropColumn('allow_multiple_properties');
        });

        Schema::table('calendar_event_feedback', function (Blueprint $table) {
            $table->dropUnique('cef_event_contact_property_unique');
            $table->dropConstrainedForeignId('property_id');
            $table->unique(['calendar_event_id', 'contact_id'], 'cef_event_contact_unique');
        });
    }
};
