<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agent applications
        Schema::create('agent_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255);
            $table->string('phone', 50)->nullable();
            $table->string('id_number', 20)->nullable();
            $table->string('current_agency', 255)->nullable();
            $table->integer('years_experience')->default(0);
            $table->string('ffc_number', 100)->nullable();
            $table->date('ffc_expiry')->nullable();
            $table->string('ppra_status', 50)->nullable();
            $table->enum('designation', ['property_practitioner', 'candidate_practitioner', 'intern'])->default('property_practitioner');
            $table->text('motivation')->nullable();
            $table->string('referral_source', 255)->nullable();
            $table->unsignedBigInteger('referred_by_user_id')->nullable();
            $table->enum('status', ['applied', 'documents_pending', 'compliance_review', 'mentor_assignment', 'training', 'activated', 'rejected', 'withdrawn'])->default('applied');
            $table->timestamp('status_changed_at')->nullable();
            $table->text('status_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('agency_id');
            $table->index('email');
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('referred_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('activated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // 2. Application documents
        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->enum('document_type', ['id_copy', 'ffc_certificate', 'qualifications', 'pi_insurance', 'tax_clearance', 'proof_of_address', 'cv', 'other']);
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->enum('status', ['uploaded', 'verified', 'rejected'])->default('uploaded');
            $table->string('rejection_reason', 500)->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('application_id')->references('id')->on('agent_applications')->cascadeOnDelete();
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
        });

        // 3. Onboarding checklists
        Schema::create('onboarding_checklists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->string('item_key', 100);
            $table->string('item_label', 255);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['application_id', 'item_key']);
            $table->foreign('application_id')->references('id')->on('agent_applications')->cascadeOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_checklists');
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('agent_applications');
    }
};
