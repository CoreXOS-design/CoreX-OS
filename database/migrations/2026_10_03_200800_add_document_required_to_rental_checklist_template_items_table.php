<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 Part E, §5 — "document required", mirroring `note_required`
 * (2026_10_03_200100_create_rental_checklist_template_items_table.php)
 * exactly: same boolean shape, same default false, same
 * RentalApplicationChecklistTemplateController::storeItem()/updateItem()
 * validation pattern. When set, the per-application item cannot be marked
 * Done without at least one non-archived attachment
 * (RentalApplicationReviewController::updateChecklistItem()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_checklist_template_items', function (Blueprint $table) {
            $table->boolean('document_required')->default(false)->after('note_required');
        });
    }

    public function down(): void
    {
        Schema::table('rental_checklist_template_items', function (Blueprint $table) {
            $table->dropColumn('document_required');
        });
    }
};
