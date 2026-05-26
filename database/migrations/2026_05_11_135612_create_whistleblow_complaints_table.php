<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whistleblow_complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->foreignId('reported_by_user_id')->constrained('users');

            // Subject of complaint
            $table->enum('tier', ['tier_1', 'tier_2', 'tier_3']);
            $table->string('subject_agency_name');
            $table->string('subject_practitioner_name')->nullable();
            $table->string('subject_ffc_number')->nullable();
            $table->string('subject_practitioner_email')->nullable();
            $table->string('subject_practitioner_phone')->nullable();

            // Property reference
            $table->foreignId('property_id')->nullable()->constrained();
            $table->string('property_address');
            $table->string('property_portal_url')->nullable();
            $table->enum('portal_source', ['p24', 'pp', 'other'])->nullable();
            $table->string('portal_listing_ref')->nullable();

            // Seller info (Tier 1 only)
            $table->foreignId('seller_contact_id')->nullable()->constrained('contacts');
            $table->text('seller_statement')->nullable();
            $table->boolean('seller_consents_to_named_complaint')->default(false);

            // Internal notes
            $table->text('agent_notes')->nullable();

            // Workflow status
            $table->enum('status', [
                'draft',
                'pending_approval',
                'changes_requested',
                'rejected',
                'approved',
                'sent',
                'acknowledged_by_ppra',
                'closed',
            ])->default('draft');

            // Approval
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // PPRA submission
            $table->timestamp('sent_to_ppra_at')->nullable();
            $table->string('ppra_reference_number')->nullable();
            $table->timestamp('ppra_acknowledged_at')->nullable();

            // Generated complaint PDF
            $table->string('complaint_pdf_path')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whistleblow_complaints');
    }
};
