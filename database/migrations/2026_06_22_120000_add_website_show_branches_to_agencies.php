<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — per-agency toggle for exposing branches on the website.
 * Mirrors website_show_agents / website_show_listings. Off by default: a single
 * head-office agency stays a single brand on its site until it opts in.
 *
 * Spec: .ai/specs/agency-public-api.md §3.7 (Website settings), §5 (branches).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('website_show_branches')->default(false)->after('website_show_listings');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('website_show_branches');
        });
    }
};
