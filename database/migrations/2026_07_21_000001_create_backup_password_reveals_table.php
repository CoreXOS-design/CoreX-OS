<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-163 (Backup status page). Append-only audit log for reveals of the OFF-BOX
 * backup encryption (restic repo) password. Every time the single root-only
 * password is decrypted-to-screen — even by an owner — one row lands here.
 * Append-only, never edited, no soft-deletes (an audit trail is immutable).
 *
 * NOT agency-scoped: the backup password is a single box-global secret, so a
 * reveal is a box-global event visible to any authorised (owner) viewer. The
 * actor's agency is stamped for context only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_password_reveals', function (Blueprint $table) {
            $table->id();
            // Who performed the reveal.
            $table->foreignId('revealed_by')->constrained('users', 'id', 'bkp_rvl_by_fk')->cascadeOnDelete();
            // Actor's agency at reveal time (context only; nullable for platform accounts).
            $table->unsignedBigInteger('revealed_by_agency_id')->nullable();
            $table->timestamp('revealed_at');
            $table->string('ip_address', 45)->nullable();   // 45 = max IPv6 literal
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index('revealed_at', 'bkp_rvl_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_password_reveals');
    }
};
