<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill calendar_event_links for manually-created events (source_type='manual')
 * that have property_id or contact_id set but no corresponding link rows.
 * Does not touch demo events (already backfilled by 000002).
 */
return new class extends Migration {
    public function up(): void
    {
        $rows = DB::table('calendar_events')
            ->where('source_type', 'manual')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNotNull('property_id')->orWhereNotNull('contact_id');
            })
            ->get();

        $inserts = [];
        $now = now();

        foreach ($rows as $row) {
            // Skip if links already exist for this event
            $hasLinks = DB::table('calendar_event_links')
                ->where('calendar_event_id', $row->id)
                ->exists();
            if ($hasLinks) {
                continue;
            }

            if ($row->property_id) {
                $inserts[] = [
                    'calendar_event_id'  => $row->id,
                    'linkable_type'      => 'App\\Models\\Property',
                    'linkable_id'        => $row->property_id,
                    'role'               => 'subject_property',
                    'created_by_user_id' => $row->user_id,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            }

            if ($row->contact_id) {
                $inserts[] = [
                    'calendar_event_id'  => $row->id,
                    'linkable_type'      => 'App\\Models\\Contact',
                    'linkable_id'        => $row->contact_id,
                    'role'               => 'attendee',
                    'created_by_user_id' => $row->user_id,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            }

            // Check metadata for additional contacts
            if ($row->metadata) {
                $meta = json_decode($row->metadata, true) ?: [];
                foreach (($meta['linked_contact_ids'] ?? []) as $cid) {
                    if ($cid == $row->contact_id) {
                        continue; // already added via direct FK
                    }
                    $inserts[] = [
                        'calendar_event_id'  => $row->id,
                        'linkable_type'      => 'App\\Models\\Contact',
                        'linkable_id'        => $cid,
                        'role'               => 'attendee',
                        'created_by_user_id' => $row->user_id,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }
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
                  ->where('source_type', 'manual');
            })
            ->whereNotNull('created_by_user_id')
            ->delete();
    }
};
