<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick Links Wave 1 (.ai/specs/quick-links.md §8-4, Johan 2026-10-12) — two FICA numbers that were hard-coded
 * become per-agency settings. Defaults equal the old constants, so no agency changes behaviour on deploy.
 *
 *  - fica_link_days                 : how long the secure link in a FICA request email stays valid (was a fixed 14)
 *  - fica_expiring_after_months     : months after approval at which a contact's FICA shows "expiring" (was a fixed 11)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'fica_link_days')) {
                $table->unsignedSmallInteger('fica_link_days')->default(14);
            }
            if (! Schema::hasColumn('agencies', 'fica_expiring_after_months')) {
                $table->unsignedTinyInteger('fica_expiring_after_months')->default(11);
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['fica_link_days', 'fica_expiring_after_months'] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
