<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Part 2 — "Prospected" badge agency toggle. When ON (default), MIC rows show a
     * durable "Prospected · agent · outcome · date" badge for worked-and-closed
     * listings so colleagues don't re-canvass an owner already worked. Default ON.
     */
    public function up(): void
    {
        if (Schema::hasColumn('agencies', 'show_prospected_badge')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('show_prospected_badge')
                ->default(true)
                ->after('maintenance_mode');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agencies', 'show_prospected_badge')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('show_prospected_badge');
        });
    }
};
