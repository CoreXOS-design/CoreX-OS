<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'p24_agent_id')) {
                $table->integer('p24_agent_id')->nullable()->index();
            }
            if (!Schema::hasColumn('users', 'source_reference')) {
                $table->string('source_reference')->nullable()->index();
            }
        });

        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'p24_listing_number')) {
                $table->string('p24_listing_number')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'p24_agent_id')) {
                $table->dropColumn('p24_agent_id');
            }
            if (Schema::hasColumn('users', 'source_reference')) {
                $table->dropColumn('source_reference');
            }
        });

        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'p24_listing_number')) {
                $table->dropColumn('p24_listing_number');
            }
        });
    }
};
