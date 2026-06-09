<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — website branch ordering. 'alphabetical' (by name) or
 * 'custom' (by branches.website_order). Mirrors website_agent_order_mode.
 * Spec: agency-public-api.md §5 (branches).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'website_branch_order_mode')) {
                $table->string('website_branch_order_mode')->default('alphabetical');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'website_branch_order_mode')) {
                $table->dropColumn('website_branch_order_mode');
            }
        });
    }
};
