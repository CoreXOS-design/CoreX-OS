<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key store for mobile RENTAL-inspection photo uploads — the sibling
 * of gallery_upload_keys (see 2026_08_06_000001). When the rental upload endpoint
 * accepts one photo per request with a client_upload_id, this map
 * { client_upload_id => stored_url } lets a retried photo return the existing
 * record instead of duplicating. Consulted/updated under a row lock in
 * MobileRentalImagesController::upload.
 *
 * Nullable JSON, no default. Write-only from the rental upload endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('rental_upload_keys')->nullable()->after('gallery_upload_keys');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('rental_upload_keys');
        });
    }
};
