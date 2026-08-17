<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users need a distinct WhatsApp number IN ADDITION to their cell number
 * (often the same, but not always — e.g. a shared work-WhatsApp line). Adds a
 * nullable `whatsapp_number` alongside the existing `cell`.
 *
 * Idempotent (hasColumn guard). No data backfill: existing users get NULL, so
 * nothing that currently reads `cell` changes. A cell→whatsapp backfill is a
 * SEPARATE, explicit step (see the accompanying report) — deliberately not done
 * here so we never silently copy a number a user may not use on WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'whatsapp_number')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('whatsapp_number')->nullable()->after('cell');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'whatsapp_number')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('whatsapp_number');
            });
        }
    }
};
