<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Onboarding Setup Wizard — the resumable, token-gated guided setup
 * an Admin runs after their agency is created.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §4.1
 *
 * Mirrors p24_onboarding_portals (token/slug/expiry/revoke/open-tracking)
 * and adds the wizard-progress columns (current_step, completed_steps) plus
 * the admin_user_id the login gate authenticates against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_onboarding_setups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('token', 64)->unique();
            $table->string('slug')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->json('completed_steps')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['agency_id', 'revoked_at', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_onboarding_setups');
    }
};
