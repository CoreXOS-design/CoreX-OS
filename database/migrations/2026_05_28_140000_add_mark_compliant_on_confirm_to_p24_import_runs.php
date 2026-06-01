<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p24_import_runs', function (Blueprint $table) {
            $table->boolean('mark_compliant_on_confirm')->default(false)->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('p24_import_runs', function (Blueprint $table) {
            $table->dropColumn('mark_compliant_on_confirm');
        });
    }
};
