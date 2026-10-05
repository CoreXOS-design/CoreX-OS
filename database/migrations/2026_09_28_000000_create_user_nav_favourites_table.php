<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sidebar Favourites — a user's own pinned pages, shown in a panel above their
 * name in the sidebar. Spec: .ai/specs/sidebar-favourites.md §5.1.
 *
 * NO agency_id / AgencyScope here, deliberately — same shape as the existing
 * per-user preference tables (calendar_user_preferences, user_dashboard_settings).
 * A favourite belongs to exactly one user and every query is scoped by user_id,
 * so the scope would add no security while breaking an owner's favourites the
 * moment they switch agency (the AgencyScope owner-switcher blind spot).
 *
 * UNIQUE(user_id, nav_key) + SoftDeletes is the documented collision path
 * (BUILD_STANDARD §5a): un-pinning trashes the row but the unique index still
 * sees it, so re-pinning MUST go through withTrashed()->restore() rather than a
 * naive create. NavFavouriteService::sync() does exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_nav_favourites')) {
            return;
        }

        Schema::create('user_nav_favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 'p:/corex/properties' — same key convention as demo sidebar curation.
            $table->string('nav_key', 191);

            // Sidebar label captured at pin time so the panel renders without
            // re-walking the DOM. Refreshed on every save of the picker.
            $table->string('label', 100);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'nav_key']);
            $table->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_nav_favourites');
    }
};
