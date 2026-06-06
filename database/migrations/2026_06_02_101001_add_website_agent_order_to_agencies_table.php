<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — website agent ordering. 'alphabetical' (by name) or
 * 'custom' (by users.website_order). Spec: agency-public-api.md §3.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'website_agent_order_mode')) {
                $table->string('website_agent_order_mode')->default('alphabetical');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'website_agent_order_mode')) {
                $table->dropColumn('website_agent_order_mode');
            }
        });
    }
};
