<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A distinct terminal status for a displayed-but-never-signs party (deceased,
 * or collapsed out by a proxy elsewhere in their group) — deliberately NOT
 * reusing 'declined'/'deferred'/'cancelled': those all mean something
 * happened to stop a REQUIRED signature; 'not_required' means no signature
 * was ever needed from this row in the first place. Kept out of every
 * "still needs action" query by construction (nothing waits on it, nothing
 * reminds it, nothing expires it).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE signature_requests MODIFY COLUMN status ENUM('waiting','pending','viewed','partially_signed','completed','expired','declined','deferred','cancelled','not_required') NOT NULL DEFAULT 'waiting'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE signature_requests MODIFY COLUMN status ENUM('waiting','pending','viewed','partially_signed','completed','expired','declined','deferred','cancelled') NOT NULL DEFAULT 'waiting'");
    }
};
