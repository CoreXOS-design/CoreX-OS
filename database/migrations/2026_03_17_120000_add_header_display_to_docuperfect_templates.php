<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->string('header_display', 20)->default('first_page')->after('signing_parties');
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->dropColumn('header_display');
        });
    }
};
