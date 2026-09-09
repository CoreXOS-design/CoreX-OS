<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-forensics follow-up, 2026-09-09 — Johan asked who created "ZZ Deposit
 * Verify" and the table had no answer: no `created_by` column exists at
 * all. Adding it going forward. Nullable, no backfill — the 39 existing
 * rows (including the six seeded defaults every agency ships with) never
 * had a creator recorded and none is invented for them; the view shows
 * that honestly rather than papering over it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_highlighters', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('role_scope')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_highlighters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
