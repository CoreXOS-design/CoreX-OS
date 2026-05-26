<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Requires agency_feedback_options to be seeded first.
        // If the options table is empty, skip silently.
        $outcomeMap = DB::table('agency_feedback_options')
            ->where('category', 'outcome')
            ->whereNull('agency_id')
            ->pluck('id', 'label')
            ->toArray();

        if (empty($outcomeMap)) {
            return;
        }

        $rows = DB::table('calendar_events')
            ->where('source_type', 'manual:demo')
            ->whereNotNull('metadata')
            ->get();

        $inserts = [];
        $now = now();

        foreach ($rows as $row) {
            $meta = json_decode($row->metadata, true) ?: [];
            $fb = $meta['feedback'] ?? null;
            if (empty($fb)) {
                continue;
            }

            // Check if already backfilled
            $existing = DB::table('calendar_event_feedback')
                ->where('calendar_event_id', $row->id)
                ->exists();
            if ($existing) {
                continue;
            }

            // Resolve outcome by label match
            $outcomeId = $outcomeMap[$fb['outcome'] ?? ''] ?? null;

            // Resolve contact from linked_contact_ids in metadata
            $contactIds = $meta['linked_contact_ids'] ?? [];
            $contactId = $contactIds[0] ?? null;

            $inserts[] = [
                'calendar_event_id'  => $row->id,
                'contact_id'         => $contactId,
                'outcome_option_id'  => $outcomeId,
                'concern_option_ids' => json_encode($fb['concerns'] ?? []),
                'seller_visible_notes' => $fb['seller_visible'] ?? null,
                'internal_notes'     => $fb['internal'] ?? null,
                'next_action_notes'  => null,
                'captured_by_user_id' => $fb['captured_by'] ?? $row->user_id,
                'captured_at'        => $fb['captured_at'] ?? $now,
                'agency_id'          => $row->agency_id ?? 1,
                'branch_id'          => $row->branch_id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('calendar_event_feedback')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::table('calendar_event_feedback')
            ->whereIn('calendar_event_id', function ($q) {
                $q->select('id')
                  ->from('calendar_events')
                  ->where('source_type', 'manual:demo');
            })
            ->delete();
    }
};
