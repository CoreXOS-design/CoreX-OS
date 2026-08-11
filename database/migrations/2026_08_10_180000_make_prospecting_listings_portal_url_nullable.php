<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Batch resilience: portal_url was NOT NULL, so a listing captured without a URL
 * insert-failed and aborted the whole import batch (dropping good rows too). Make
 * it nullable so an absent URL degrades gracefully. Non-destructive widening; raw
 * ALTER to avoid a doctrine/dbal dependency.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE prospecting_listings MODIFY portal_url VARCHAR(500) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE prospecting_listings SET portal_url = '' WHERE portal_url IS NULL");
        DB::statement("ALTER TABLE prospecting_listings MODIFY portal_url VARCHAR(500) NOT NULL");
    }
};
