<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.27.6 item 2 / §14.28 — the contractor
 * secure-link table also carries crew links: one per job card
 * (`crew_job_card`) and, for the crew page, one per crew (`crew_standing`).
 * Exactly one target is set per row (enforced in
 * RentalSecureAccessTokenService, not by a constraint — revoked rows stay
 * for the audit trail). `rental_work_order_id` and `expires_at` become
 * nullable: a crew link has no work order, and a crew-page link may stand
 * until revoked (null = no expiry). Existing rows are contractor links and
 * are back-filled `contractor_work_order` by the column default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_secure_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_secure_access_tokens', 'rental_job_card_id')) {
                $table->foreignId('rental_job_card_id')->nullable()->after('rental_work_order_id')
                    ->constrained('rental_job_cards')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('rental_secure_access_tokens', 'rental_crew_id')) {
                $table->foreignId('rental_crew_id')->nullable()->after('rental_job_card_id')
                    ->constrained('rental_crews')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('rental_secure_access_tokens', 'purpose')) {
                $table->string('purpose', 30)->default('contractor_work_order')->after('token_hash');
            }
        });

        Schema::table('rental_secure_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('rental_work_order_id')->nullable()->change();
            $table->timestamp('expires_at')->nullable()->change();
        });

        Schema::table('rental_secure_access_tokens', function (Blueprint $table) {
            $table->index(['rental_job_card_id', 'revoked_at'], 'rsat_job_card_revoked_idx');
            $table->index(['rental_crew_id', 'revoked_at'], 'rsat_crew_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::table('rental_secure_access_tokens', function (Blueprint $table) {
            $table->dropIndex('rsat_job_card_revoked_idx');
            $table->dropIndex('rsat_crew_revoked_idx');
        });
        Schema::table('rental_secure_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_job_card_id');
            $table->dropConstrainedForeignId('rental_crew_id');
            $table->dropColumn('purpose');
        });
    }
};
