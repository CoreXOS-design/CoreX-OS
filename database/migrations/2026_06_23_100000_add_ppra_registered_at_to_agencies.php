<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9c (AT-16) — finding #3 follow-up.
 *
 * Phase 9c-1 added agencies.ppra_number (the per-agency permanent PPRA
 * registration identifier). This migration adds the companion date — when the
 * agency was registered with the PPRA — so the privacy policy and compliance
 * surfaces can state both the number and its registration date. Nullable: an
 * agency may know its number but not have the date to hand yet (renders
 * nothing when null — no empty label).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->date('ppra_registered_at')->nullable()->after('ppra_number');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->dropColumn('ppra_registered_at');
        });
    }
};
