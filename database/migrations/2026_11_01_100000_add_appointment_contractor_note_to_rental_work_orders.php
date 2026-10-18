<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 14 Oct 2026 (cc5 re-walk): the agent's private word to the contractor about an appointment ("please phone the tenant first")
 * must never reach the tenant or the owner. It gets its own column; the existing appointment_note stays the tenant-visible note.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_work_orders', 'appointment_contractor_note')) {
            Schema::table('rental_work_orders', function (Blueprint $table) {
                $table->string('appointment_contractor_note', 500)->nullable()->after('appointment_note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_work_orders', 'appointment_contractor_note')) {
            Schema::table('rental_work_orders', function (Blueprint $table) {
                $table->dropColumn('appointment_contractor_note');
            });
        }
    }
};
