<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC compose redesign (Johan 2026-08-14) — primary seller designation.
 *
 * A property can have several sellers (contact_property role=seller); one is the PRIMARY the
 * agent pitches to first. Persisted here as a per-link flag (default first-linked is primary until
 * the agent clicks another). Additive; other pivot roles are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_property', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('contact_property', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
