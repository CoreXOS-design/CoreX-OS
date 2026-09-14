<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, Task 6 — Buyer Pipeline "Lost" must take the buyer off
 * the core matches board. Not a delete, not a new `status` value (the
 * existing status enum is a sort key only, per the screen owner's own
 * finding — not touched here). A match is SET ASIDE while its buyer is
 * Lost, and comes back automatically the moment the buyer isn't.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->timestamp('set_aside_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->dropColumn('set_aside_at');
        });
    }
};
