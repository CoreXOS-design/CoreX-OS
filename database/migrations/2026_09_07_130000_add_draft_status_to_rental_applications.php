<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Johan, QA1 — "have not even sent anything, yet top left shows sent?"
 * Root cause: the status enum had no value at all for "created, not yet
 * sent" — the column defaulted to 'sent' on insert, so every brand-new
 * application lied about its own state from the moment it was created.
 * Adds 'draft' as the true starting status; store() now uses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE rental_applications MODIFY COLUMN status ENUM('draft', 'sent', 'in_progress', 'returned', 'under_assessment', 'approved', 'declined', 'withdrawn') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::table('rental_applications')->where('status', 'draft')->update(['status' => 'sent']);
        DB::statement("ALTER TABLE rental_applications MODIFY COLUMN status ENUM('sent', 'in_progress', 'returned', 'under_assessment', 'approved', 'declined', 'withdrawn') NOT NULL DEFAULT 'sent'");
    }
};
