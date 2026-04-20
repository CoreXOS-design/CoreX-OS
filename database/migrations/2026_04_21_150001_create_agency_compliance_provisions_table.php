<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_compliance_provisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();

            // Which compliance item is centrally provided
            $table->string('provision_type', 50);

            $table->enum('status', ['active', 'expired', 'superseded'])->default('active');

            // Optional central document upload
            $table->string('document_path', 500)->nullable();
            $table->string('document_original_name', 500)->nullable();
            $table->string('policy_reference', 200)->nullable();

            $table->date('effective_from');
            $table->date('effective_until')->nullable();

            // Which roles the provision covers (null/[] = all roles)
            $table->json('applies_to_roles')->nullable();

            // Which branches (optional scoping)
            $table->json('applies_to_branches')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'provision_type', 'status'], 'acp_agency_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_compliance_provisions');
    }
};
