<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->enum('render_type', ['pdf', 'web'])->default('pdf')->after('template_type');
            $table->string('blade_view', 255)->nullable()->after('render_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->dropColumn(['render_type', 'blade_view']);
        });
    }
};
