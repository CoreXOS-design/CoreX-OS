<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Johan, 2026-09-29 — "Johan wants the inventory to be one of the documents
 * an agent can select for a viewing pack." The Viewing Pack feature
 * (config/corex-features.php `viewing-packs`,
 * app/Services/ViewingPack/ViewingPackDocumentService::eligibleDocumentsFor())
 * already exists and already filters on `document_types.buyer_pack_eligible`
 * (2026_06_28_120000_add_buyer_pack_eligible_to_document_types.php) — the
 * catalogue already has a slug for this exact document, `inventory_list`
 * (DocumentTypesCatalogueSeeder.php), already used by
 * RentalInventoryRecordingController::fileInventoryWetInkScan() to stamp a
 * wet-ink scan's own `document_type_id`. This migration flips that ONE
 * existing catalogue row's `buyer_pack_eligible` to true rather than
 * inventing a new slug — a completed inventory report and a wet-ink scan of
 * one are the same kind of document either way.
 *
 * Idempotent (`update` on a `where` that may already match zero rows is a
 * safe no-op). No per-agency override touched — an agency that has
 * explicitly opted this type out via `agency_document_type_compliance`
 * keeps that choice; this only changes the catalogue DEFAULT.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')
            ->where('slug', 'inventory_list')
            ->update(['buyer_pack_eligible' => true]);
    }

    public function down(): void
    {
        DB::table('document_types')
            ->where('slug', 'inventory_list')
            ->update(['buyer_pack_eligible' => false]);
    }
};
