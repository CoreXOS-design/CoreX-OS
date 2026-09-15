<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // The one required link (AT-392 spec) — a rental application always belongs to a contact.
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', [
                'sent', 'in_progress', 'returned', 'under_assessment', 'approved', 'declined', 'withdrawn',
            ])->default('sent');

            // Applicant's chosen return route — null until they pick one on the token page,
            // or set to 'download' the moment the agent-side PDF is downloaded.
            $table->enum('delivery_mode', ['download', 'online'])->nullable();

            $table->string('token', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            // Personal — every field nullable per BUILD_STANDARD §2 (input-space rule):
            // nothing here may block a send.
            $table->string('property_address_override')->nullable();
            $table->string('full_name')->nullable();
            $table->string('id_number')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('spouse_name')->nullable();
            $table->string('spouse_id')->nullable();
            $table->string('citizenship')->nullable();
            $table->text('current_residential_address')->nullable();
            $table->string('email')->nullable();
            $table->string('cell')->nullable();
            $table->string('work_number')->nullable();

            // Emergency contact
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_cell')->nullable();
            $table->string('emergency_contact_work')->nullable();

            // Current landlord
            $table->string('current_landlord_name')->nullable();
            $table->string('current_landlord_tel')->nullable();
            $table->decimal('current_rental_amount', 12, 2)->nullable();
            $table->date('current_rental_from')->nullable();
            $table->date('current_rental_to')->nullable();

            // Employment
            $table->string('employer_name')->nullable();
            $table->string('employer_position')->nullable();
            $table->text('employer_address')->nullable();
            $table->string('employer_tel')->nullable();
            $table->decimal('monthly_salary', 12, 2)->nullable();
            $table->enum('employment_type', [
                'permanently_employed', 'business_owner_personal_account', 'business_owner_business_account',
            ])->nullable();

            // Lease requirement
            $table->date('occupation_date')->nullable();
            $table->string('rental_terms')->nullable();
            $table->text('special_conditions')->nullable();
            $table->unsignedSmallInteger('adults')->nullable();
            $table->unsignedSmallInteger('children')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'status']);
            $table->index(['agency_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_applications');
    }
};
