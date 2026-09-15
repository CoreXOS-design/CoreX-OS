<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 authoriser markup — Johan, verbatim: "so the auth can highlight in
 * own colour, auth what agent did and edit... remove im thinking is just a
 * strike out tick - which leaves the amount there but removes it from the
 * calcs... it shows the authoriser disagreed with a specific line rather
 * than the figure quietly vanishing. It is an audit trail, not a display
 * choice."
 *
 * Superseded refinement, same day, Johan again, more precise: "auth can
 * rather strike out and re-add a value than edit a value. this way we have
 * the evidence needed of who did what." Edit is scoped to your OWN capture
 * only; disagreement with someone else's figure is expressed by striking
 * it and adding a replacement, never by changing it in place.
 *
 * struck_out_at/struck_out_by_user_id — deliberately NOT SoftDeletes. A
 * struck-out line must stay visible with a line through it and drop out of
 * the total; SoftDeletes hides the row entirely, which is the opposite of
 * what an audit trail needs here. added_by_user_id identifies a line the
 * AUTHORISER added (nullable — every existing agent-captured line, and
 * every future agent-captured line, has no value here; only the
 * authoriser's own additions ever populate it, so "null = agent's own
 * capture" needs no backfill).
 *
 * NOTE (2026-09-08, re-landed): this migration ran once already, then its
 * file was lost when a shared-checkout branch switch swept it into another
 * lane's WIP commit and it was never actually committed to QA1 — the
 * columns it created were later dropped along with a schema reset that had
 * no record of it. Re-landing it properly this time: committed on this
 * branch, not sitting uncommitted in a shared working tree. Content is
 * byte-identical to the original; this is the same approved migration,
 * not a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['rental_application_income_items', 'rental_application_expense_items'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->timestamp('struck_out_at')->nullable()->after('sort_order');
                $t->foreignId('struck_out_by_user_id')->nullable()
                    ->constrained('users', 'id', $table === 'rental_application_income_items' ? 'rai_items_struck_by_fk' : 'rae_items_struck_by_fk')
                    ->nullOnDelete()->after('struck_out_at');
                $t->foreignId('added_by_user_id')->nullable()
                    ->constrained('users', 'id', $table === 'rental_application_income_items' ? 'rai_items_added_by_fk' : 'rae_items_added_by_fk')
                    ->nullOnDelete()->after('struck_out_by_user_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['rental_application_income_items', 'rental_application_expense_items'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropConstrainedForeignId('struck_out_by_user_id');
                $t->dropConstrainedForeignId('added_by_user_id');
                $t->dropColumn('struck_out_at');
            });
        }
    }
};
