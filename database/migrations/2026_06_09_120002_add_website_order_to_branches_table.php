<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — per-branch custom website ordering position (used when the
 * agency's website_branch_order_mode is 'custom'). Mirrors users.website_order.
 * Spec: agency-public-api.md §5 (branches).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'website_order')) {
                $table->unsignedInteger('website_order')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'website_order')) {
                $table->dropColumn('website_order');
            }
        });
    }
};
