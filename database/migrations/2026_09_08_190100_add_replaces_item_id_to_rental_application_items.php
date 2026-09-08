<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 authoriser markup — Johan, confirmed directly: "auth can rather
 * strike out and re-add a value than edit a value. this way we have the
 * evidence needed of who did what." This supersedes the earlier plan to let
 * an authoriser edit their own added row in place: there is now no EDIT
 * verb at all, only STRIKE and ADD.
 *
 * The coordinator's explicit design requirement built on top of Johan's
 * rule: "the result should read clearly as 'this figure was replaced by
 * that one, by this person, at this time'. Both rows visible, both
 * attributed, only the live one in the totals." That sentence can't be
 * satisfied by UI state alone (a page reload would lose which new row
 * replaced which struck one) — replaces_item_id is the one additional fact
 * needed to persist it: nullable, self-referencing, set only when a row is
 * added via the "strike out & replace" flow, never otherwise.
 *
 * Additive, nullable, reversible — same shape as the previous migration.
 * Committed to this branch BEFORE being run, per the standing rule this
 * feature's own incident established.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['rental_application_income_items', 'rental_application_expense_items'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreignId('replaces_item_id')->nullable()
                    ->constrained($table, 'id', $table === 'rental_application_income_items' ? 'rai_items_replaces_fk' : 'rae_items_replaces_fk')
                    ->nullOnDelete()->after('added_by_user_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['rental_application_income_items', 'rental_application_expense_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('replaces_item_id');
            });
        }
    }
};
