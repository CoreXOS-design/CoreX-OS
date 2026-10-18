<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §43 — which parties get notified on
 * schedule/reschedule/cancel, which channel(s), the minimum notice an agent
 * should give (warns, never blocks), and whether/when a reminder fires.
 * Nullable, read-time-default pattern (matching every other column on this
 * table) — null means "use the RentalInspectionSetting::DEFAULT_* constant,"
 * never a hardcoded default baked into the schema itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->boolean('notify_tenant_enabled')->nullable()->after('auto_send_report_enabled');
            $table->boolean('notify_landlord_enabled')->nullable()->after('notify_tenant_enabled');
            $table->boolean('notify_inspector_enabled')->nullable()->after('notify_landlord_enabled');
            $table->boolean('notify_via_mail_enabled')->nullable()->after('notify_inspector_enabled');
            $table->boolean('notify_via_whatsapp_enabled')->nullable()->after('notify_via_mail_enabled');
            $table->unsignedSmallInteger('minimum_notice_days')->nullable()->after('notify_via_whatsapp_enabled');
            $table->unsignedSmallInteger('reminder_days_before')->nullable()->after('minimum_notice_days');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn([
                'notify_tenant_enabled', 'notify_landlord_enabled', 'notify_inspector_enabled',
                'notify_via_mail_enabled', 'notify_via_whatsapp_enabled',
                'minimum_notice_days', 'reminder_days_before',
            ]);
        });
    }
};
