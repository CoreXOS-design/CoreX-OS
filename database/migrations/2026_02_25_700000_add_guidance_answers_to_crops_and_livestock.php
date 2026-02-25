<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_evaluation_crops', function (Blueprint $table) {
            $table->json('guidance_answers')->nullable()->after('notes');
        });

        Schema::table('commercial_evaluation_livestock', function (Blueprint $table) {
            $table->json('guidance_answers')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_evaluation_crops', function (Blueprint $table) {
            $table->dropColumn('guidance_answers');
        });

        Schema::table('commercial_evaluation_livestock', function (Blueprint $table) {
            $table->dropColumn('guidance_answers');
        });
    }
};
