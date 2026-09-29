<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/other-agency-stock.md — "Other Agency Stock" (a single-listing
 * import of ANOTHER agency's Property24/PrivateProperty listing, status
 * other_agency_stock, never syndicated, included in Core Matches). Johan's
 * ruling: visibility of that stock is a ROLE SETTING each agency configures
 * itself, reusing the existing role system — never hardcoded roles.
 *
 * One JSON string[] column on `agencies`, not a dedicated child table —
 * unlike calendar_event_class_settings (one row PER EVENT CLASS, several
 * distinct visibility lists per agency), there is exactly ONE such setting
 * per agency here, so a direct column follows the same pattern already used
 * for split_branches_enabled/assistants_enabled rather than adding a table
 * for a single value.
 *
 * NULL (the default) = visible to all roles, so nothing disappears from any
 * agency's Properties list / Core Matches / viewing-pack picker the moment
 * this ships — Johan's explicit rollout requirement. An agency narrows this
 * by writing an explicit role list from Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->json('other_agency_stock_visible_roles')->nullable()->after('split_branches_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('other_agency_stock_visible_roles');
        });
    }
};
