<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Match-card v2 — "Access / key arrangements" free-text field on a property,
 * e.g. "Keys kept with managing agency — contact Steve 011 011 0110". Set by
 * the creating/managing agent on the property record; surfaced ONLY on the
 * agent-facing Seller + Access popover (match-card.blade.php) — never on any
 * client-facing surface (shared/match.blade.php, buyer-portal). Nullable —
 * most listings won't need it filled immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('access_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('access_notes');
        });
    }
};
