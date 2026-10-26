<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md "Lease fee" (26 Oct 2026, Johan) - what an agency calls the fee ("Admin fee", "Lease fee", "Letting fee").
 *
 * `lease_settings.lease_fee_label`: the agency's own name for the fee, shown everywhere the fee is shown or mapped. NULL / blank
 * reads as "Lease fee", so an agency that never sets it sees exactly what it saw before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('lease_settings', 'lease_fee_label')) {
                $table->string('lease_fee_label', 60)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('lease_settings', function (Blueprint $table) {
            if (Schema::hasColumn('lease_settings', 'lease_fee_label')) {
                $table->dropColumn('lease_fee_label');
            }
        });
    }
};
