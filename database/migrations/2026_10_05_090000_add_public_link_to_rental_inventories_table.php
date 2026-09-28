<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §41-follow-up (Job 3, 2026-09-28) — Inventory's own public share link, the
 * same mechanism `rental_inspections.public_token`/`public_token_expires_at`
 * already established (see that migration's own docblock for the full
 * reasoning: a stored, regenerable token rather than Laravel's
 * temporarySignedRoute(), because a per-record revoke/regenerate needs to
 * invalidate one link without rotating APP_KEY for every other signed URL
 * in the app). One active link per inventory; regenerating overwrites the
 * old token, which IS the revoke mechanism — no separate revoked_at column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('cancel_reason');
            $table->timestamp('public_token_expires_at')->nullable()->after('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'public_token_expires_at']);
        });
    }
};
