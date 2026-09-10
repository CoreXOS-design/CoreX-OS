<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer/Rental Pipeline kanban board loads every buyer/tenant in scope with
 * no pagination, only CSS-scrolled per column — fine on QA1's handful of
 * rows, not at a real agency's volume. Agency-configurable cap per column
 * (default 50); List view already paginates and stays uncapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('buyer_kanban_column_limit')->default(50)->after('buyer_pipeline_default_scope');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('buyer_kanban_column_limit');
        });
    }
};
