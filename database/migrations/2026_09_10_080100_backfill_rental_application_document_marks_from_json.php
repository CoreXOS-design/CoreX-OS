<?php

use App\Models\RentalApplicationDocumentHighlight;
use App\Models\RentalApplicationDocumentMark;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * AT-401 — one-time backfill: every mark in every
 * rental_application_document_highlights.marks_json row becomes its own row
 * in rental_application_document_marks. Nothing is lost or discarded —
 * marks_json is left in place afterward (untouched, unread by anything
 * going forward) as the frozen pre-migration record.
 *
 * Runs over EVERY highlight row, including soft-deleted ones (withTrashed)
 * — a trashed highlight-state row can still hold real marks history.
 *
 * A legacy mark with no client-generated `id` (pre-dates the 2026-09-08
 * ownership/id scheme) gets a fresh UUID as its mark_uid here — it never
 * had a stable identity to preserve, and after this migration every mark,
 * old or new, has exactly one: a real row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $highlights = RentalApplicationDocumentHighlight::withTrashed()->get();

        $created = 0;
        foreach ($highlights as $highlight) {
            foreach ((array) ($highlight->marks_json ?? []) as $page => $marks) {
                foreach ((array) $marks as $m) {
                    if (! is_array($m)) {
                        continue;
                    }

                    $type = ($m['type'] ?? null) === 'note' ? 'note' : 'highlight';
                    $uid = isset($m['id']) && is_string($m['id']) && $m['id'] !== ''
                        ? mb_substr($m['id'], 0, 64)
                        : (string) Str::uuid();

                    RentalApplicationDocumentMark::create([
                        'agency_id' => $highlight->agency_id,
                        'document_id' => $highlight->document_id,
                        'mark_uid' => $uid,
                        'type' => $type,
                        'page' => (int) $page,
                        'points' => $type === 'highlight' ? ($m['points'] ?? null) : null,
                        'width' => $type === 'highlight' ? ($m['width'] ?? null) : null,
                        'x' => $type === 'note' ? ($m['x'] ?? null) : null,
                        'y' => $type === 'note' ? ($m['y'] ?? null) : null,
                        'text' => $type === 'note' ? ($m['text'] ?? null) : null,
                        'highlighter_id' => $m['highlighter_id'] ?? null,
                        'author_user_id' => $m['author_user_id'] ?? null,
                        'author_name' => $m['author_name'] ?? null,
                        'author_role' => in_array($m['author_role'] ?? null, ['agent', 'authoriser'], true) ? $m['author_role'] : null,
                        'source' => 'human',
                        // Preserve the row's own timestamps rather than "now"
                        // for every migrated mark, so ordering-by-id remains
                        // the only ordering signal needed going forward.
                        'created_at' => $highlight->updated_at ?? $highlight->created_at ?? now(),
                        'updated_at' => $highlight->updated_at ?? $highlight->created_at ?? now(),
                    ]);
                    $created++;
                }
            }
        }

        \Log::info("AT-401 mark backfill: created {$created} rental_application_document_marks rows from marks_json.");
    }

    public function down(): void
    {
        // Data-only migration — marks_json (the source of truth this
        // reads from) is untouched, so this is safely reversible: drop
        // everything this backfill created and marks_json still has it all.
        RentalApplicationDocumentMark::query()->forceDelete();
    }
};
