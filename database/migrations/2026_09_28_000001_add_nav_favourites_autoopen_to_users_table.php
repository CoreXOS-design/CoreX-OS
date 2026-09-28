<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sidebar Favourites — per-user switch for "open my Favourites automatically
 * when I sign in". Spec: .ai/specs/sidebar-favourites.md §5.2, §6.2.
 *
 * Default FALSE: the panel stays closed until a user opts in, so nothing about
 * the sidebar changes for anyone who never touches the feature.
 *
 * This is a PERSONAL preference on users, not an agency setting — it is
 * deliberately NOT in the onboarding wizard (spec §11, flagged for Johan).
 * Guarded so a re-run after a partial failure is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'nav_favourites_autoopen')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('nav_favourites_autoopen')->default(false)->after('daily_digest_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'nav_favourites_autoopen')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nav_favourites_autoopen');
        });
    }
};
