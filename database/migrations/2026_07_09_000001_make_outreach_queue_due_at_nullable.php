<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-117 (simplified) — due-time removed. A queued row is immediately READY; the
 * only send gate is the agency send-window at dispatch. due_at is no longer set,
 * so make it nullable (kept, vestigial — a non-destructive alter, no data lost).
 * surfaced_at is already nullable and likewise unused now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_queue', function (Blueprint $table) {
            $table->dateTime('due_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('outreach_queue', function (Blueprint $table) {
            $table->dateTime('due_at')->nullable(false)->change();
        });
    }
};
