<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-217 (DR2, Johan walk fix 2) — attorney respec: a supplier is a FIRM with
 * MULTIPLE CONTACT PERSONS. Real case: BBB Inc has attorney X (worked via his
 * assistant) and attorney Y (via his paralegal) — same firm, different working
 * contacts. So:
 *   - `agency_service_providers` becomes the FIRM (gains `address`).
 *   - NEW `agency_service_provider_contacts` = the 1..n contacts under a firm
 *     (each: attorney name/role + contact person + email + phone). Agency-scoped,
 *     soft-deleted (deactivating a contact preserves historic deal references).
 *   - A deal links the FIRM + the specific CONTACT (attorney_provider_id +
 *     attorney_contact_id on `deals`), with `attorney_name` kept as the display.
 * Additive on the same tables — DR1 ignores the new columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agency_service_providers', 'address')) {
            Schema::table('agency_service_providers', function (Blueprint $table) {
                $table->string('address', 500)->nullable()->after('phone');
            });
        }

        if (! Schema::hasTable('agency_service_provider_contacts')) {
            Schema::create('agency_service_provider_contacts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->unsignedBigInteger('service_provider_id'); // the FIRM
                $table->string('attorney_name')->nullable();       // the attorney/practitioner at the firm
                $table->string('contact_person')->nullable();      // the working contact (assistant/paralegal)
                $table->string('role')->nullable();                // e.g. attorney / assistant / paralegal
                $table->string('email')->nullable();
                $table->string('phone', 50)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('service_provider_id')->references('id')->on('agency_service_providers')->cascadeOnDelete();
                $table->index(['agency_id', 'service_provider_id'], 'aspc_agency_firm_idx');
            });
        }

        Schema::table('deals', function (Blueprint $table) {
            if (! Schema::hasColumn('deals', 'attorney_provider_id')) {
                $table->unsignedBigInteger('attorney_provider_id')->nullable()->after('attorney_name');
            }
            if (! Schema::hasColumn('deals', 'attorney_contact_id')) {
                $table->unsignedBigInteger('attorney_contact_id')->nullable()->after('attorney_provider_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            foreach (['attorney_provider_id', 'attorney_contact_id'] as $col) {
                if (Schema::hasColumn('deals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('agency_service_provider_contacts');

        if (Schema::hasColumn('agency_service_providers', 'address')) {
            Schema::table('agency_service_providers', function (Blueprint $table) {
                $table->dropColumn('address');
            });
        }
    }
};
