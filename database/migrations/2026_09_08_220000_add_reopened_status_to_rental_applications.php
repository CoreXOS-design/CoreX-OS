<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reopen/resubmit, 2026-09-08 — BUG, caught by RentalApplicationReopenTest
 * before it ever reached Johan: `rental_applications.status` is a real
 * MySQL ENUM column, not just the PHP-level RentalApplication::STATUSES
 * list. Adding 'reopened' to that PHP constant alone left the actual
 * database column unable to store it — every reopen() call 500'd with
 * "Data truncated for column 'status'" the moment it tried to save.
 * Same class of miss the draft-status migration above this one exists to
 * fix; same fix shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE rental_applications MODIFY COLUMN status ENUM('draft', 'sent', 'in_progress', 'returned', 'reopened', 'under_assessment', 'approved', 'declined', 'withdrawn') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::table('rental_applications')->where('status', 'reopened')->update(['status' => 'returned']);
        DB::statement("ALTER TABLE rental_applications MODIFY COLUMN status ENUM('draft', 'sent', 'in_progress', 'returned', 'under_assessment', 'approved', 'declined', 'withdrawn') NOT NULL DEFAULT 'draft'");
    }
};
