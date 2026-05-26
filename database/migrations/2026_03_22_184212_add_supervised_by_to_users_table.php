<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Candidate Practitioner Flow (PPRA Compliance):
     * 1. Add supervised_by FK to users table (informational, not used for routing)
     * 2. Add is_candidate_flow + supervisor_user_id to signature_templates
     * 3. Expand status enum on signature_templates for supervisor statuses
     * 4. Add returned_notes to signature_requests for return-to-candidate flow
     * 5. Add authorised_by + authorised_at to signature_requests (audit trail)
     */
    public function up(): void
    {
        // 1. Users: supervised_by
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('supervised_by')->nullable()->after('designation');
            $table->index('supervised_by');
            $table->foreign('supervised_by')->references('id')->on('users')->onDelete('set null');
        });

        // 2. Signature templates: candidate flow fields
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->boolean('is_candidate_flow')->default(false)->after('created_by');
            $table->unsignedBigInteger('supervisor_user_id')->nullable()->after('is_candidate_flow');
            $table->foreign('supervisor_user_id')->references('id')->on('users')->onDelete('set null');
        });

        // 3. Expand status enum to include supervisor + candidate statuses
        //    (cross-driver: MySQL prod + SQLite test DB).
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->enum('status', [
                'draft', 'ready', 'signing',
                'awaiting_tenant', 'awaiting_landlord', 'awaiting_buyer', 'awaiting_seller',
                'awaiting_supervisor', 'awaiting_supervisor_final',
                'pending_agent_approval',
                'returned_to_candidate',
                'completed', 'expired', 'declined', 'rejected',
            ])->default('draft')->change();
        });

        // 4. Signature requests: returned_notes for return-to-candidate flow
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->text('returned_notes')->nullable()->after('status');
        });

        // 5. Signature requests: authorised_by + authorised_at (PPRA audit trail)
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('authorised_by')->nullable()->after('returned_notes');
            $table->timestamp('authorised_at')->nullable()->after('authorised_by');
            $table->foreign('authorised_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropForeign(['authorised_by']);
            $table->dropColumn(['authorised_by', 'authorised_at']);
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn('returned_notes');
        });

        Schema::table('signature_templates', function (Blueprint $table) {
            $table->enum('status', [
                'draft', 'ready', 'signing',
                'awaiting_tenant', 'awaiting_landlord',
                'pending_agent_approval',
                'completed', 'expired', 'declined',
            ])->default('draft')->change();
        });

        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropForeign(['supervisor_user_id']);
            $table->dropColumn(['is_candidate_flow', 'supervisor_user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['supervised_by']);
            $table->dropIndex(['supervised_by']);
            $table->dropColumn('supervised_by');
        });
    }
};
