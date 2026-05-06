<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $rows = DB::table('calendar_events')
            ->where('source_type', 'manual:demo')
            ->whereNotNull('metadata')
            ->get();

        $inserts = [];
        $now = now();

        foreach ($rows as $row) {
            $meta = json_decode($row->metadata, true) ?: [];

            if (!empty($meta['linked_property_id'])) {
                $inserts[] = [
                    'calendar_event_id'  => $row->id,
                    'linkable_type'      => 'App\\Models\\Property',
                    'linkable_id'        => $meta['linked_property_id'],
                    'role'               => 'subject_property',
                    'created_by_user_id' => $row->user_id,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            }

            foreach (($meta['linked_contact_ids'] ?? []) as $contactId) {
                $inserts[] = [
                    'calendar_event_id'  => $row->id,
                    'linkable_type'      => 'App\\Models\\Contact',
                    'linkable_id'        => $contactId,
                    'role'               => 'attendee',
                    'created_by_user_id' => $row->user_id,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            }
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('calendar_event_links')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::table('calendar_event_links')
            ->whereIn('calendar_event_id', function ($q) {
                $q->select('id')
                  ->from('calendar_events')
                  ->where('source_type', 'manual:demo');
            })
            ->delete();
    }
};
