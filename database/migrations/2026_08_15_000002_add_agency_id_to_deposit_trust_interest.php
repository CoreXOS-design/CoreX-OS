<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HFC tenant-isolation audit, Wave 2 (#8) — Trust Interest Register. The
 * table was built single-tenant from day one: no agency_id, no user_id,
 * no branch_id — nothing ties a row to a tenant — plus a GLOBAL unique
 * constraint on interest_date, so two agencies could never even both have
 * an entry for the same month. Every agency with access_trust_interest
 * saw and could edit/delete the same single shared register (HFC's
 * captured data).
 *
 * No creator/uploader column exists to backfill ownership from — there is
 * no way to determine which agency captured which historical row. Per
 * Johan's explicit instruction, every existing row is attributed to the
 * first (historical) agency — the same rationale as every other backfill
 * in this audit: leaving agency_id NULL would make the row invisible to
 * everyone under AgencyScope's strict-orphan rule, which for real
 * historical financial data is worse than attributing it to the tenant
 * that has always effectively owned it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('deposit_trust_interest', 'agency_id')) {
            Schema::table('deposit_trust_interest', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('id');
            });
        }

        $firstAgencyId = DB::table('agencies')->orderBy('id')->value('id');
        if ($firstAgencyId) {
            DB::table('deposit_trust_interest')->whereNull('agency_id')->update(['agency_id' => $firstAgencyId]);
        }

        Schema::table('deposit_trust_interest', function (Blueprint $table) {
            try {
                $table->dropUnique('deposit_trust_interest_interest_date_unique');
            } catch (\Throwable $e) {
                // Already dropped (re-run) or never existed under this name.
            }
        });

        Schema::table('deposit_trust_interest', function (Blueprint $table) {
            $table->unique(['agency_id', 'interest_date'], 'deposit_trust_interest_agency_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('deposit_trust_interest', function (Blueprint $table) {
            try {
                $table->dropUnique('deposit_trust_interest_agency_date_unique');
            } catch (\Throwable $e) {
            }
        });

        Schema::table('deposit_trust_interest', function (Blueprint $table) {
            try {
                $table->unique('interest_date', 'deposit_trust_interest_interest_date_unique');
            } catch (\Throwable $e) {
                // Duplicate interest_date values across agencies now exist —
                // cannot restore the global unique without first resolving
                // them. Leave the composite unique in place rather than fail
                // the rollback outright.
            }
        });

        if (Schema::hasColumn('deposit_trust_interest', 'agency_id')) {
            Schema::table('deposit_trust_interest', function (Blueprint $table) {
                $table->dropColumn('agency_id');
            });
        }
    }
};
