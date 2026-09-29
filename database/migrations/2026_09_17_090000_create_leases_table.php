<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md — leases as the spine of rentals. Johan: "a tenant is
 * not linked to a property, a tenant is linked to a LEASE, and the lease is
 * linked to the property. A property has many tenancies over its life."
 *
 * REPLACE, NOT EXTEND (Johan's ruling): four existing schemas held pieces of
 * this fact (rentals, lease_records, rental_properties, properties' own
 * lease columns) — none of them property-required + agency-scoped +
 * tenancy-shaped at once. See the data migration
 * (2026_09_17_090400_migrate_rentals_and_lease_records_into_leases.php) for
 * how the 58 legacy `rentals` rows and 2 `lease_records` rows migrate
 * across. Nothing is deleted — non-negotiable #1.
 *
 * previous_lease_id/renewed_lease_id: every lease TERM (including a renewal
 * of the same tenant) is its own row, chained, never the same record
 * extended in place — see leases.md §3.1 for the full argument (precise
 * per-term attribution, meaningful status transitions, matches the
 * already-proven renewal-chain shape in the existing lease_records table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default('draft');
            // draft | active | expired | cancelled

            $table->decimal('rental_amount', 12, 2);
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_month_to_month')->default(false);
            $table->string('lease_type', 40)->nullable();
            // reuses properties.lease_type's shape (Net/Gross/Modified Gross/Percentage) —
            // not a foreign key, just the same free-form convention, kept consistent.

            $table->string('source', 30)->default('manual');
            // manual | rental_application | esign_document | migrated_legacy
            $table->foreignId('rental_application_id')->nullable()
                ->constrained('rental_applications')->nullOnDelete();
            $table->unsignedBigInteger('source_document_id')->nullable();
            // docuperfect_documents.id — no FK constraint across the Docuperfect boundary,
            // matching the existing convention that table already uses for cross-module
            // references (e.g. lease_records.property_id has none either); reserved for
            // when the e-sign extraction (§1.2) is pointed at Lease directly.

            $table->string('migrated_from_table', 40)->nullable();
            $table->unsignedBigInteger('migrated_from_id')->nullable();
            // leases.md §6 audit trail — which legacy row (rentals/lease_records) this was
            // migrated from, and by what address-match confidence (see
            // App\Console\Commands\MigrateLegacyLeases). Never used for lookups, only for
            // "where did this come from" traceability. NULL for anything created fresh
            // through the new Lease screen after this build lands.

            $table->foreignId('previous_lease_id')->nullable()
                ->constrained('leases')->nullOnDelete();
            $table->foreignId('renewed_lease_id')->nullable()
                ->constrained('leases')->nullOnDelete();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();
            // Gated at the application layer per leases.md §2 (same principle as
            // rental-inspections.md §3.3): deletable only while nothing has attached to
            // it yet (no escalation, no inspection, no work order). Once evidence exists,
            // only 'cancelled' — never destroyed. The column exists because a genuinely
            // empty draft, abandoned before anything happened, is ordinary CRUD noise and
            // the full-CRUD floor (BUILD_STANDARD §1a) requires an archive/restore path.

            $table->index(['agency_id', 'property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
