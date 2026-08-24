<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 — Assistants, Prompt A.
 *
 * `assistants_enabled` is the kill switch and the safe default. It ships FALSE for
 * every existing agency — the code is live, the enforcement is dormant. It is also
 * the resolver's FIRST check (spec §7.1): flipping it off gives every assistant zero
 * permissions instantly, which is the safe direction to fail.
 *
 * Mirrors `agencies.split_branches_enabled` exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('assistants_enabled')->default(false)->after('split_branches_enabled');
            $table->boolean('assistant_fica_required_default')->default(true)->after('assistants_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['assistants_enabled', 'assistant_fica_required_default']);
        });
    }
};
