<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — Phase 1a.
 *
 * Per-agent website visibility flag. Agents are not part of the property
 * Syndication system, so they get a simple publish flag. Defaults to FALSE
 * — an agent is invisible to the public web until explicitly turned on,
 * never the reverse (no accidental PII egress).
 *
 * Spec: .ai/specs/agency-public-api.md §3.6, §2 (layer 3)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'show_on_website')) {
                $table->boolean('show_on_website')->default(false)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'show_on_website')) {
                $table->dropColumn('show_on_website');
            }
        });
    }
};
