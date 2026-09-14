<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, share-history piece — Johan's ruling: the share record
 * is EVIDENCE and must be soft-delete only, like everything else in CoreX
 * (CLAUDE.md non-negotiable #1). cc4's original migration didn't carry
 * this — additive follow-up rather than editing their migration file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_match_shares', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('contact_match_shares', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
