<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — per-agent custom website ordering position (used when the
 * agency's website_agent_order_mode is 'custom'). Spec: agency-public-api.md §3.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'website_order')) {
                $table->unsignedInteger('website_order')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'website_order')) {
                $table->dropColumn('website_order');
            }
        });
    }
};
