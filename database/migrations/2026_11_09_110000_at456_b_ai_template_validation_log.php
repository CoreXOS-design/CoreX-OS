<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 Part B audit item 9 — keep WHY the validator accepted, repaired or rejected each option
 * the model wrote, so a generation that ends short of three can be diagnosed afterwards.
 * Shape: [{attempt, option, name, outcome: accepted|repaired|rejected, repairs: [], errors: []}].
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_template_generations', function (Blueprint $table) {
            $table->json('validation_log_json')->nullable()->after('options_json');
        });
    }

    public function down(): void
    {
        Schema::table('ai_template_generations', function (Blueprint $table) {
            $table->dropColumn('validation_log_json');
        });
    }
};
