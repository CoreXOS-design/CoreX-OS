<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §2 — AT-445. "Confirm a completed job"
 * — the tenant's own 'fixed'/'not fixed' sign-off, contact-attributed (not
 * the agent-recorded stand-in `rental_job_cards.tenant_confirmed_by_user_id`
 * that AT-442's own migration comment deferred to this ticket: "tenant
 * confirmation is recorded BY THE AGENT for now (tenant login is AT-445)").
 * Lives on the work order itself (not only the job card) because a tenant
 * sign-off applies to ANY completed work order, including the
 * outside_supplier path, which has no job card at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_orders', function (Blueprint $table) {
            $table->timestamp('tenant_confirmed_at')->nullable();
            $table->boolean('tenant_confirmed_fixed')->nullable();
            $table->foreignId('tenant_confirmed_by_contact_id')->nullable()
                ->constrained('contacts', indexName: 'rwo_tenant_confirmed_contact_fk')->nullOnDelete();
            $table->text('tenant_confirmation_note')->nullable();
        });

        // AT-442's job-card tenant_confirmed_by_user_id stays untouched (agent
        // path unchanged) — this adds the contact-attributed counterpart so a
        // REAL tenant confirmation can also be mirrored onto the job card when
        // one exists, without altering any existing column or RentalJobCard logic.
        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->boolean('tenant_confirmed_fixed')->nullable();
            $table->foreignId('tenant_confirmed_by_contact_id')->nullable()
                ->constrained('contacts', indexName: 'rjc_tenant_confirmed_contact_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_confirmed_by_contact_id');
            $table->dropColumn(['tenant_confirmed_at', 'tenant_confirmed_fixed', 'tenant_confirmation_note']);
        });

        Schema::table('rental_job_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_confirmed_by_contact_id');
            $table->dropColumn(['tenant_confirmed_fixed']);
        });
    }
};
