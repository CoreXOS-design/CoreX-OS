<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P24 returns duplicate suburb names across cities (e.g. "Lotusville" exists
 * in multiple cities), but the legacy p24_suburbs table had a global UNIQUE
 * constraint on `slug`. p24_id is the real unique identifier — drop the
 * global slug unique, keep slug as a plain index for fast text lookup.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('p24_suburbs', function (Blueprint $t) {
            try { $t->dropUnique('p24_suburbs_slug_unique'); } catch (\Throwable $e) {}
            $t->index('slug', 'p24_suburbs_slug_index');
        });
    }

    public function down(): void
    {
        Schema::table('p24_suburbs', function (Blueprint $t) {
            try { $t->dropIndex('p24_suburbs_slug_index'); } catch (\Throwable $e) {}
        });
    }
};
