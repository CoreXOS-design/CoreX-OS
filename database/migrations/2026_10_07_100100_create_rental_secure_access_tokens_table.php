<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §4/§6 — AT-445. Contractor access to a
 * single work order, no login. Only the HASH of the token is stored (the
 * raw token is shown to the contractor exactly once, in the link, and never
 * logged) — same discipline as every other bearer-secret table in CoreX.
 * Flat `rental_work_order_id` (not polymorphic) — this ticket scopes
 * contractor links to work orders only, per the build brief.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_secure_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_service_provider_id')->nullable()->constrained()->nullOnDelete();

            $table->string('token_hash', 100)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // At most one LIVE (not revoked) token per work order — enforced
            // in RentalSecureAccessTokenService::issueFor() by revoking any
            // existing active token before minting a new one, not by a DB
            // constraint (revoked rows must stay, for the audit trail).
            $table->index(['rental_work_order_id', 'revoked_at'], 'rsat_work_order_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_secure_access_tokens');
    }
};
