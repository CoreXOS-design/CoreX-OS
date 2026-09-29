<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 Part E — the per-application snapshot side of `document_required`,
 * copied from the template item at snapshot time
 * (RentalApplicationChecklistService::snapshotFor()), same as
 * `note_required` already is. See the sibling migration on
 * rental_checklist_template_items for the full reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_checklist_items', function (Blueprint $table) {
            $table->boolean('document_required')->default(false)->after('note_required');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_checklist_items', function (Blueprint $table) {
            $table->dropColumn('document_required');
        });
    }
};
