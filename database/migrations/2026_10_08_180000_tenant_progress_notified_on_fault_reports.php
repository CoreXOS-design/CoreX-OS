<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant progress (Johan, 8 Oct 2026): the tenant is told at "sent to owner", "owner approved / not approved" and "work
 * completed" - once each. This is the last step key the tenant was told about, so the same step never mails twice
 * (e.g. a work order completing and then the fault outcome being saved).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->string('tenant_progress_notified', 40)->nullable()->after('sent_to_owner_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->dropColumn('tenant_progress_notified');
        });
    }
};
