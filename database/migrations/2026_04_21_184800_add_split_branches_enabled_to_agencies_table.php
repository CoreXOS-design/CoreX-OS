<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-2 branch-isolation toggle. When false (default), the system
 * behaves as it does today — a single agency-wide pool of data.
 * When true, BranchScope enforces branch_id filtering on branch-scoped
 * models and the rest of the branch-isolation UX activates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agencies', 'split_branches_enabled')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('split_branches_enabled')
                ->default(false)
                ->after('dashboard_settings_mode');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('agencies', 'split_branches_enabled')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('split_branches_enabled');
        });
    }
};
