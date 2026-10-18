<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-442 — "who does the work" is the FIRST choice on every rental work
 * order: outside supplier (today's flow, unchanged) or our maintenance team
 * (job card, new). Every existing work order defaults to outside_supplier —
 * nothing about today's behaviour changes for a row that predates this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_orders', function (Blueprint $table) {
            $table->string('assignment_type', 20)->default('outside_supplier')->after('status');
            // outside_supplier | internal — RentalWorkOrder::ASSIGNMENT_*
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_orders', function (Blueprint $table) {
            $table->dropColumn('assignment_type');
        });
    }
};
