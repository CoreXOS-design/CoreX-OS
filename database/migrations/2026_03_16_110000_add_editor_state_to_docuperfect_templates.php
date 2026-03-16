<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->json('editor_state')->nullable()->after('fields_json');
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->dropColumn('editor_state');
        });
    }
};
