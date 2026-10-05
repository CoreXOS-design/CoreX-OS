<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §7 — AT-445. One row per agency,
 * read-time default pattern (matches RentalWorkOrderSetting): a null
 * column resolves to the model's DEFAULT_* constant, nothing written
 * until the agency actually changes a value.
 *
 * No `notify_agent_on_new_fault` column: "email on new fault to agent" is
 * already live, generic infrastructure — `RentalFaultReportService::
 * notifyCreated()` fires `rental_fault_report.created` through
 * NotificationDispatcher, which already resolves channel (in-app/email/
 * push) per-USER via NotificationPreferenceService, a finer and more
 * correct control than a second, agency-wide, email-only toggle here would
 * be. The two notifications below are genuinely new: tenant/landlord are
 * portal identities (ClientUser/Contact), not CoreX staff Users, so
 * NotificationDispatcher (keyed off App\Models\User) cannot address them —
 * these are plain agency-level Mail toggles, matching the existing
 * unconditional RentalWorkOrderService::notifyOwner() pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_portal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete()->unique();

            $table->boolean('tenant_portal_enabled')->nullable();
            $table->boolean('landlord_portal_enabled')->nullable();
            $table->boolean('contractor_links_enabled')->nullable();
            $table->unsignedSmallInteger('contractor_secure_link_expiry_days')->nullable();

            $table->boolean('notify_landlord_on_decision_needed')->nullable();
            $table->boolean('notify_tenant_on_status_change')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_portal_settings');
    }
};
