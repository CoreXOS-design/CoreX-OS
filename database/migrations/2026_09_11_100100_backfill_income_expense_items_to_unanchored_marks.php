<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Capture-ledger rework, 2026-09-11 — Johan: "it stays split and
 * disconnected" (the old separate ledger strip is deleted this same
 * change). Existing affordability lines captured through that strip
 * (rental_application_income_items / rental_application_expense_items)
 * MUST survive — migrated here as UNANCHORED entries (no document, no
 * page, no drawn geometry) on rental_application_document_marks, the same
 * table a capture-pen drag now writes to. Nothing is dropped: every row,
 * struck-out or not, is copied.
 *
 * A struck-out item (`struck_out_at` not null) already meant "excluded
 * from the total, kept visible for the audit trail" under the old screen.
 * The new panel has no strike-out concept — the closest honest equivalent
 * is SOFT-DELETED here: the row survives (no hard delete, ever), it does
 * not count in the new panel's live totals, and it remains queryable by
 * anyone who needs the history. This is a deliberate mapping decision,
 * not an oversight — flagged in the spec for the same reason.
 *
 * `author_user_id` maps from `added_by_user_id`. `author_name`/
 * `author_role` are left null — the old tables never stored a role
 * alongside the adding user, and this table already has an established,
 * documented "unattributed legacy mark" state for exactly this situation
 * (see RentalApplicationDocumentMark's own docblock) rather than guessing
 * a role that was never recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (['income' => 'rental_application_income_items', 'expense' => 'rental_application_expense_items'] as $entryType => $tableName) {
            // whereNull('i.deleted_at') — a row the agent/authoriser already
            // soft-deleted on the old screen (SoftDeletes is on both source
            // models) stays exactly as deleted; migrating it back in would
            // resurrect something a user explicitly removed, not preserve
            // captured data.
            $items = DB::table($tableName . ' as i')
                ->join('rental_application_assessments as a', 'a.id', '=', 'i.rental_application_assessment_id')
                ->whereNull('i.deleted_at')
                ->select('i.*', 'a.rental_application_id as rental_application_id')
                ->get();

            $migrated = 0;
            foreach ($items as $item) {
                // Blank-string entry_date (35 of 45 income rows, live-checked
                // before writing this) must become NULL, not '' — an empty
                // string is not a valid value for the target DATE column
                // under strict SQL mode.
                $entryDate = ($item->entry_date === '' || $item->entry_date === null) ? null : $item->entry_date;

                DB::table('rental_application_document_marks')->insert([
                    'agency_id' => $item->agency_id,
                    'document_id' => null,
                    'rental_application_id' => $item->rental_application_id,
                    'mark_uid' => (string) \Illuminate\Support\Str::uuid(),
                    'type' => null,
                    'entry_type' => $entryType,
                    'entry_date' => $entryDate,
                    'entry_description' => $item->description,
                    'entry_amount' => $item->amount,
                    'page' => null,
                    'points' => null,
                    'width' => null,
                    'x' => null,
                    'y' => null,
                    'text' => null,
                    'highlighter_id' => null,
                    'author_user_id' => $item->added_by_user_id,
                    'author_name' => null,
                    'author_role' => null,
                    'source' => 'human',
                    'confidence' => null,
                    'created_at' => $item->created_at ?? $now,
                    'updated_at' => $now,
                    // Struck-out items are preserved but excluded from the
                    // new panel's live view (soft-deleted, never counted,
                    // never hard-removed) — see this file's own docblock.
                    'deleted_at' => $item->struck_out_at,
                ]);
                $migrated++;
            }

            echo "  Migrated {$migrated} {$entryType} row(s) from {$tableName}." . PHP_EOL;
        }
    }

    public function down(): void
    {
        // Deliberately not reversed: these rows are now indistinguishable,
        // post-migration, from any capture entry an agent may have typed
        // manually in the meantime against the same application — a
        // rollback that deleted "everything with source=human and no
        // document_id" would risk deleting real new work, not just what
        // this migration inserted. The original rental_application_income_
        // items / rental_application_expense_items rows are untouched by
        // this migration (copy, not move), so the pre-migration data is
        // never at risk regardless.
    }
};
