<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            // Nullable — null means "unknown", not "open". Values captured so
            // far: sole, exclusive, open, joint (whatever the source signal
            // says); the MIC canvass-pool filter only acts on sole/exclusive.
            $table->string('mandate_type', 20)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropColumn('mandate_type');
        });
    }
};
