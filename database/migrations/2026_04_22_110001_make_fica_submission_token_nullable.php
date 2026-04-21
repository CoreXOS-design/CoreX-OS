<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Make token nullable for wet-ink submissions (no client-facing link)
        DB::statement('ALTER TABLE fica_submissions MODIFY token VARCHAR(64) NULL');
        DB::statement('ALTER TABLE fica_submissions MODIFY token_expires_at DATETIME NULL');
    }

    public function down(): void
    {
        // Keep nullable — no reverse
    }
};
