<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('p24_syndication_logs')) {
            return;
        }

        Schema::table('p24_syndication_logs', function (Blueprint $table) {
            $table->index(['property_id', 'created_at'], 'p24_logs_property_created_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('p24_syndication_logs')) {
            return;
        }

        Schema::table('p24_syndication_logs', function (Blueprint $table) {
            $table->dropIndex('p24_logs_property_created_idx');
        });
    }
};
