<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.32 — what a tenant / owner reads in place of a crew member's NAME when the agency's own team does the
 * job ("Our maintenance team" by default). Null = the neutral default; each agency may word it its own way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            $table->string('internal_team_label', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            $table->dropColumn('internal_team_label');
        });
    }
};
