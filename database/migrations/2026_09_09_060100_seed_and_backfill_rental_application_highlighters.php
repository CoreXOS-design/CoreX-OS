<?php

use App\Models\RentalApplicationHighlighter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Highlighter collection expansion, 2026-09-09 — the one-time data
 * migration Johan asked to see proven, not glossed over.
 *
 * Step 1: seed the six starting highlighters for every EXISTING agency,
 * reading that agency's already-saved RentalApplicationMarkColorSetting row
 * when one exists (an agency that already customised its six colours keeps
 * those exact values — never silently reset to the shipped defaults).
 *
 * Step 2: every existing mark stores `category` ('income'|'expense'|
 * 'unpaid') + `author_role` ('agent'|'authoriser') as plain fields inside
 * its own JSON — never a foreign key to anything. This is what makes the
 * migration purely ADDITIVE and safe: for each mark, find the seeded
 * highlighter with the matching label+role_scope for that mark's own
 * agency, and add a NEW `highlighter_id` key alongside the existing ones.
 * `category`/`author_role` are left exactly as they are — never removed,
 * never overwritten — so nothing is lost and the legacy fallback in
 * RentalApplicationDocumentHighlightService::resolveMarkColors() still
 * works if a `highlighter_id` is ever unresolvable for any reason.
 *
 * A mark with a null/missing `author_role` (genuinely legacy, pre-dating
 * role attribution) is mapped the same way resolveMarkColors() already
 * treats it today: anything that isn't exactly 'authoriser' resolves to
 * the 'agent' highlighter for that category — so the mark's rendered
 * colour is provably unchanged by this migration, not just probably
 * unchanged. A mark with no `category` at all (older than the colour
 * system entirely) is left with no `highlighter_id` — it was never part of
 * this system and keeps using the original 4-colour legacy fallback,
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Real QA1 data check (2026-09-09) surfaced why this can't be a
        // bare pluck('id'): the agencies table also carries soft-deleted
        // isolated-test-agency fixtures from other lanes' own verification
        // work (deleted_at IS NOT NULL) — an archived agency needs no live
        // highlighter settings. "Every existing agency" means every
        // existing, non-archived one.
        $agencyIds = DB::table('agencies')->whereNull('deleted_at')->pluck('id');

        foreach ($agencyIds as $agencyId) {
            $existingColors = DB::table('rental_application_mark_color_settings')
                ->where('agency_id', $agencyId)->first();

            $colors = [];
            if ($existingColors) {
                $colors = [
                    'agent' => [
                        'income' => $existingColors->agent_income_color,
                        'expense' => $existingColors->agent_expense_color,
                        'unpaid' => $existingColors->agent_unpaid_color,
                    ],
                    'authoriser' => [
                        'income' => $existingColors->authoriser_income_color,
                        'expense' => $existingColors->authoriser_expense_color,
                        'unpaid' => $existingColors->authoriser_unpaid_color,
                    ],
                ];
            }

            RentalApplicationHighlighter::seedDefaultsFor((int) $agencyId, $colors);
        }

        // Build a (agency_id, category, role) -> highlighter_id lookup from
        // what was just seeded, using the row order DEFAULT_SEED itself
        // defines (label+role_scope), not a guess.
        $seeded = DB::table('rental_application_highlighters')->get();
        $lookup = []; // "{agencyId}:{category}:{role}" => highlighter id
        foreach ($seeded as $row) {
            foreach (RentalApplicationHighlighter::DEFAULT_SEED as $seed) {
                if ($seed['role_scope'] === $row->role_scope && $seed['label'] === $row->label) {
                    $lookup[$row->agency_id . ':' . $seed['legacy_category'] . ':' . $seed['legacy_role']] = $row->id;
                }
            }
        }

        $highlights = DB::table('rental_application_document_highlights')->get();
        foreach ($highlights as $highlight) {
            $marksByPage = json_decode($highlight->marks_json, true) ?? [];
            $changed = false;

            foreach ($marksByPage as $pageIndex => $marks) {
                foreach ($marks as $i => $mark) {
                    $category = $mark['category'] ?? null;
                    if ($category === null || isset($mark['highlighter_id'])) {
                        continue; // never part of the category system, or already migrated
                    }
                    $role = ($mark['author_role'] ?? null) === 'authoriser' ? 'authoriser' : 'agent';
                    $key = $highlight->agency_id . ':' . $category . ':' . $role;
                    if (isset($lookup[$key])) {
                        $marksByPage[$pageIndex][$i]['highlighter_id'] = $lookup[$key];
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                DB::table('rental_application_document_highlights')
                    ->where('id', $highlight->id)
                    ->update(['marks_json' => json_encode($marksByPage)]);
            }
        }
    }

    public function down(): void
    {
        // Additive-only migration (adds a highlighter_id key to existing
        // mark JSON, never removes category/author_role) — reversing it
        // means stripping that key back out, not restoring lost data.
        $highlights = DB::table('rental_application_document_highlights')->get();
        foreach ($highlights as $highlight) {
            $marksByPage = json_decode($highlight->marks_json, true) ?? [];
            foreach ($marksByPage as $pageIndex => $marks) {
                foreach ($marks as $i => $mark) {
                    unset($marksByPage[$pageIndex][$i]['highlighter_id']);
                }
            }
            DB::table('rental_application_document_highlights')
                ->where('id', $highlight->id)
                ->update(['marks_json' => json_encode($marksByPage)]);
        }

        DB::table('rental_application_highlighters')->truncate();
    }
};
