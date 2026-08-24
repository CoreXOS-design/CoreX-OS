<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key store for mobile gallery uploads.
 *
 * The rebuilt mobile app retries each photo on timeout/network/5xx, sending a
 * stable `client_upload_id` per photo. Without a server-side record of which keys
 * have already been committed, a retry of an already-stored photo would insert a
 * SECOND gallery row (and orphan a second file). This column holds a compact
 * map { client_upload_id => stored_url } per property; MobilePropertyController
 * ::uploadImage consults and updates it under a row lock, so a retried POST
 * returns the existing record instead of duplicating.
 *
 * Nullable JSON, no default — existing rows and non-mobile ingress paths simply
 * carry null. Write-only from the upload endpoint; nothing else reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('gallery_upload_keys')->nullable()->after('gallery_custom_tags');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('gallery_upload_keys');
        });
    }
};
