<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md "Lease fee" (26 Oct 2026, Johan) - the lease (letting/placement) fee is a real lease term.
 *
 * `leases.lease_fee`: the fee charged for this lease term, a plain money column beside rent and deposit. NULL = not on record
 * (existing leases stay exactly as they were); 0 = on record as "not charged".
 * `lease_settings.default_lease_fee`: the agency's starting amount for a new lease. NULL reads as 0 (not charged), so an agency
 * that never sets it charges nothing and a lease it captures starts at 0.00.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            if (! Schema::hasColumn('leases', 'lease_fee')) {
                $table->decimal('lease_fee', 12, 2)->nullable()->after('deposit_amount');
            }
        });

        Schema::table('lease_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_settings', 'default_lease_fee')) {
                $table->decimal('default_lease_fee', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            if (Schema::hasColumn('lease_settings', 'default_lease_fee')) {
                $table->dropColumn('default_lease_fee');
            }
        });

        Schema::table('leases', function (Blueprint $table) {
            if (Schema::hasColumn('leases', 'lease_fee')) {
                $table->dropColumn('lease_fee');
            }
        });
    }
};
