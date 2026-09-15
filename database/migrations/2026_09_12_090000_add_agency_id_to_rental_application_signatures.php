<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA1 multi-tenancy sweep, 2026-09-12 — RentalApplicationSignature had no
 * `agency_id` column at all and no `BelongsToAgency`, unlike every sibling
 * model on this same rental-application (RentalApplication itself,
 * RentalApplicationDocumentMark, RentalApplicationAssessment,
 * RentalApplicationGeneration, ...). Not reachable via any route today —
 * the only write site (RentalApplicationSigningController::storeSignature())
 * always scopes by an already-token-resolved application, and nothing binds
 * a signature id directly from a request — but a tenant-owned table with no
 * `agency_id` violates CLAUDE.md Non-negotiable #7 outright, and is exactly
 * the "unscoped by default" structural trap this sweep exists to close
 * before a future query forgets to filter by the parent explicitly.
 *
 * Idempotent guards throughout, matching the established style in
 * 2026_09_08_210100_add_generation_to_rental_application_signatures.php —
 * a mid-run DDL failure must not make a re-run collide with its own
 * earlier partial progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_application_signatures', 'agency_id')) {
            Schema::table('rental_application_signatures', function (Blueprint $table) {
                $table->foreignId('agency_id')->nullable()->after('rental_application_id')->constrained()->cascadeOnDelete();
            });
        }

        // Backfill every existing row from its own parent application — the
        // only source of truth for which agency a signature belongs to.
        DB::statement(
            'UPDATE rental_application_signatures ras '
            . 'JOIN rental_applications ra ON ra.id = ras.rental_application_id '
            . 'SET ras.agency_id = ra.agency_id '
            . 'WHERE ras.agency_id IS NULL'
        );

        // NOT NULL once backfilled — every signature has a real parent
        // application, so every signature has a real agency. A future
        // insert with no agency_id would be a genuine bug, not a
        // legitimate "shared/global" row (nothing about a signature is
        // ever global), so this should fail loudly, not silently orphan.
        if (Schema::hasColumn('rental_application_signatures', 'agency_id')) {
            Schema::table('rental_application_signatures', function (Blueprint $table) {
                $table->foreignId('agency_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('rental_application_signatures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
        });
    }
};
