<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-508 (P1) — tenants and owners are ClientUsers, not Users, so they had no way to
 * register a phone for push. A device row now belongs to EITHER a staff user OR a
 * client user. Spec: .ai/specs/at-508-mobile-rentals.md §14.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->foreignId('client_user_id')->nullable()->after('user_id')->constrained('client_users')->cascadeOnDelete();
            $table->unique(['client_user_id', 'token'], 'device_tokens_client_token_unique');
        });

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique('device_tokens_client_token_unique');
            $table->dropConstrainedForeignId('client_user_id');
        });
    }
};
