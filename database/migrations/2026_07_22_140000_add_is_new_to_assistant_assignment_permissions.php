<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-267 — mark a matrix row as genuinely NEW (drift).
 *
 * The "N new permissions available" banner used to count EVERY off row, so an
 * assistant seeded with admin-default-off permissions (or trimmed by the agent)
 * showed a large, permanent "new" count that never cleared. `is_new` is set only
 * when the agent GAINS a permission after setup (a drift top-up), counted for the
 * banner, and cleared the moment the agent visits the matrix — so the notice
 * shows once and then goes away. The row itself stays off until the agent turns
 * it on.
 *
 * Existing rows backfill to false (default), so any stale "new" banner clears at
 * once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_assignment_permissions', function (Blueprint $table) {
            $table->boolean('is_new')->default(false)->after('is_locked');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_assignment_permissions', function (Blueprint $table) {
            $table->dropColumn('is_new');
        });
    }
};
