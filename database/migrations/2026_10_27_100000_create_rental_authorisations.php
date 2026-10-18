<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22 ("nothing is automatic"). Stage 1:
 *  - `rental_authorisations`: the AUTHORISATION RECORD, written at each of the two decisions (who goes to look / the cost). Append-only: a newer
 *    record supersedes an older one (superseded_at / superseded_by_id); nothing is edited or deleted.
 *  - on the fault: what the tenant ticked when they reported (a snapshot), how/when/what the agent confirmed with the tenant, and the contractor the
 *    agent proposed when sending the fault to the owner to authorise.
 * Idempotent: every step checks before it writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_authorisations')) {
            Schema::create('rental_authorisations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->unsignedBigInteger('rental_fault_report_id')->nullable()->index();
                $table->unsignedBigInteger('rental_work_order_id')->nullable()->index();
                $table->string('point', 12);                          // inspect (Decision 1) | cost (Decision 2)
                $table->string('outcome', 12);                        // authorised | declined
                $table->string('basis', 30);                          // owner_link | owner_instruction | agent_no_callout_fee | agent_callout_setting | agent_repair_limit | agent_emergency_limit
                $table->string('stage', 40)->nullable();              // where the fault / work order stood when it was given
                $table->decimal('amount_up_to', 12, 2)->nullable();   // D2: approved up to; D1: the call-out fee it covers
                $table->decimal('limit_amount', 12, 2)->nullable();   // the owner's limit / permission the agent relied on
                $table->boolean('is_emergency')->default(false);
                $table->text('emergency_reason')->nullable();
                $table->unsignedBigInteger('owner_contact_id')->nullable();   // which owner (link press, or the one who told the agent)
                $table->string('told_via', 20)->nullable();           // phone_call | whatsapp | email | link
                $table->dateTime('told_at')->nullable();              // when the owner said it / pressed the button / the agent authorised
                $table->text('note')->nullable();                     // what the owner said
                $table->string('proof_path', 500)->nullable();
                $table->unsignedBigInteger('by_user_id')->nullable();         // the agent who authorised / recorded
                $table->unsignedBigInteger('by_contact_id')->nullable();      // the owner, on their link
                $table->string('inspector_label', 191)->nullable();   // D1: who is sent (contractor / crew name)
                $table->unsignedBigInteger('agency_service_provider_id')->nullable();
                $table->decimal('callout_fee', 12, 2)->nullable();    // D1: the call-out fee disclosed to the owner
                $table->timestamp('owner_told_at')->nullable();
                $table->timestamp('superseded_at')->nullable();
                $table->unsignedBigInteger('superseded_by_id')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['rental_fault_report_id', 'point'], 'ra_fault_point_idx');
                $table->index(['rental_work_order_id', 'point'], 'ra_wo_point_idx');
            });
        }

        if (! Schema::hasColumn('rental_fault_reports', 'tenant_self_checks')) {
            Schema::table('rental_fault_reports', function (Blueprint $table) {
                $table->json('tenant_self_checks')->nullable();
                $table->string('tenant_confirmed_via', 20)->nullable();
                $table->dateTime('tenant_confirmed_at')->nullable();
                $table->text('tenant_confirmed_note')->nullable();
                $table->unsignedBigInteger('tenant_confirmed_by_user_id')->nullable();
                $table->timestamp('tenant_confirmed_recorded_at')->nullable();
                $table->string('proposed_assignment', 20)->nullable();   // outside_supplier | internal - proposed when sent to the owner to authorise
                $table->unsignedBigInteger('proposed_supplier_id')->nullable();
                $table->unsignedBigInteger('proposed_crew_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_authorisations');
        if (Schema::hasColumn('rental_fault_reports', 'tenant_self_checks')) {
            Schema::table('rental_fault_reports', fn (Blueprint $t) => $t->dropColumn([
                'tenant_self_checks', 'tenant_confirmed_via', 'tenant_confirmed_at', 'tenant_confirmed_note', 'tenant_confirmed_by_user_id',
                'tenant_confirmed_recorded_at', 'proposed_assignment', 'proposed_supplier_id', 'proposed_crew_id',
            ]));
        }
    }
};
