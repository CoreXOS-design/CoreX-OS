<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('tertiary_color', 20)->default('#1a4a73')->after('secondary_color');
        });

        // Seed the default tertiary for the existing HFC Coastal agency
        DB::table('agencies')->update(['tertiary_color' => '#1a4a73']);
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('tertiary_color');
        });
    }
};
