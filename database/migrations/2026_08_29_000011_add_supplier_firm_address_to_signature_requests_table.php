<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-27 — cc4 gave suppliers a real, firm-level business
 * address (AgencyServiceProvider->address, 1407ef455). A supplier-sourced
 * recipient (an executor standing in from the supplier directory) has no
 * linked Contact, so the domicilium address block had nowhere to resolve
 * from at all — "steps screen missing address" / "typed value doesn't
 * carry to agent signing" was this gap, not a plumbing bug on its own.
 *
 * Same frozen-at-generation-time contract as supplier_firm_name/
 * supplier_firm_registration_number in 2026_08_29_000008 — a document
 * a firm was actually sent with never reprints a different address later
 * if the supplier directory record changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('supplier_firm_address', 500)->nullable()->after('supplier_firm_registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn('supplier_firm_address');
        });
    }
};
