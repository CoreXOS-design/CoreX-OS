<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rentals - the work order is the EXTERNAL record (Johan, 8 Oct 2026, W1-W6) - .ai/specs/rentals-faults-work-orders.md §16.
 *
 *  W3: who does the work lives on the work order. `assignment_type` gains 'owner_contractor' (the owner's own
 *      contractor); `contractor_name` / `contractor_phone` are that contractor's optional details.
 *  W2/W6: the appointment lives on the work order (agent or owner sets it; the tenant is told when it is set or
 *      changed). appointment_set_by_* say WHO set it last; every set/change is also a row in rental_work_order_updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_orders', function (Blueprint $table) {
            $table->string('contractor_name', 191)->nullable()->after('agency_service_provider_id');
            $table->string('contractor_phone', 40)->nullable()->after('contractor_name');
            $table->timestamp('appointment_at')->nullable()->after('ordered_at');
            $table->string('appointment_note', 500)->nullable()->after('appointment_at');
            $table->timestamp('appointment_set_at')->nullable()->after('appointment_note');
            $table->unsignedBigInteger('appointment_set_by_user_id')->nullable()->after('appointment_set_at');
            $table->unsignedBigInteger('appointment_set_by_contact_id')->nullable()->after('appointment_set_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'contractor_name', 'contractor_phone', 'appointment_at', 'appointment_note',
                'appointment_set_at', 'appointment_set_by_user_id', 'appointment_set_by_contact_id',
            ]);
        });
    }
};
