<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pull-all (v3.1.4): address-less P24/PP listings are now captured. Let
 * prospecting_listings.address hold a true NULL for them (was NOT NULL, which
 * would 500 a null insert). Non-destructive widening — raw ALTER to avoid a
 * doctrine/dbal dependency; existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE prospecting_listings MODIFY address VARCHAR(255) NULL');
    }

    public function down(): void
    {
        // Restore NOT NULL — blank any NULLs first so the tightening cannot fail.
        DB::statement("UPDATE prospecting_listings SET address = '' WHERE address IS NULL");
        DB::statement("ALTER TABLE prospecting_listings MODIFY address VARCHAR(255) NOT NULL");
    }
};
