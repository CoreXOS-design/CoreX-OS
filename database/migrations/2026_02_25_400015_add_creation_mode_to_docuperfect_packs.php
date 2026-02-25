<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docuperfect_packs', function (Blueprint $table) {
            $table->enum('creation_mode', ['individual', 'linked'])->default('linked')->after('is_global');
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_packs', function (Blueprint $table) {
            $table->dropColumn('creation_mode');
        });
    }
};
