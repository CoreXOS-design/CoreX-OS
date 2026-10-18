<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rentals fault-report flow (Johan, 2026-10-08, F1-F7) - .ai/specs/rentals-faults-work-orders.md §15.
 *
 *  rental_fault_reports:
 *    owner_title / owner_description / owner_agent_note / owner_photo_ids - the SANITISED version the agent
 *      prepares for the owner. The tenant's original (title, description, photos) is never edited by this.
 *    owner_version_saved_at/_by_user_id - the agent has reviewed and prepared the owner version.
 *    sent_to_owner_at/_by_user_id       - the agent pressed send; ONLY now may the owner see the fault.
 *
 *  rental_approvals (append-only decision evidence): who handles the repair.
 *    contractor_source = 'own' (the owner's own contractor: optional name + phone) | 'agency' (a supplier picked from
 *    the agency's supplier list: agency_service_provider_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->string('owner_title', 191)->nullable()->after('description');
            $table->text('owner_description')->nullable()->after('owner_title');
            $table->text('owner_agent_note')->nullable()->after('owner_description');
            $table->json('owner_photo_ids')->nullable()->after('owner_agent_note');
            $table->timestamp('owner_version_saved_at')->nullable()->after('owner_photo_ids');
            $table->unsignedBigInteger('owner_version_saved_by_user_id')->nullable()->after('owner_version_saved_at');
            $table->timestamp('sent_to_owner_at')->nullable()->after('owner_version_saved_by_user_id');
            $table->unsignedBigInteger('sent_to_owner_by_user_id')->nullable()->after('sent_to_owner_at');
            $table->index(['agency_id', 'sent_to_owner_at'], 'rfr_agency_sent_idx');
        });

        Schema::table('rental_approvals', function (Blueprint $table) {
            $table->string('contractor_source', 12)->nullable()->after('approval_route');
            $table->string('contractor_name', 191)->nullable()->after('contractor_source');
            $table->string('contractor_phone', 40)->nullable()->after('contractor_name');
            $table->unsignedBigInteger('agency_service_provider_id')->nullable()->after('contractor_phone');
        });

        // Faults already waiting on the owner were "sent" the moment they were marked awaiting approval: keep them
        // visible to that owner. Everything else (reported, not yet asked) is now hidden until the agent sends it.
        DB::statement(
            "UPDATE rental_fault_reports r
                SET r.sent_to_owner_at = COALESCE(
                    (SELECT MIN(u.created_at) FROM rental_fault_report_updates u
                      WHERE u.rental_fault_report_id = r.id AND u.update_type = 'approval_requested'),
                    r.created_at)
              WHERE r.owner_approval_status = 'pending' AND r.sent_to_owner_at IS NULL"
        );
    }

    public function down(): void
    {
        Schema::table('rental_approvals', function (Blueprint $table) {
            $table->dropColumn(['contractor_source', 'contractor_name', 'contractor_phone', 'agency_service_provider_id']);
        });
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->dropIndex('rfr_agency_sent_idx');
            $table->dropColumn([
                'owner_title', 'owner_description', 'owner_agent_note', 'owner_photo_ids',
                'owner_version_saved_at', 'owner_version_saved_by_user_id', 'sent_to_owner_at', 'sent_to_owner_by_user_id',
            ]);
        });
    }
};
