<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 §B.9-2 — what a generation was ASKED for, kept on the generation row (the usage record):
 *   style                       'auto' ("Let the AI choose") or one of the built-in style keys (config/ad-template-styles.php).
 *                               The style each option actually ended up with is stored inside options_json[n]['style'].
 *   match_existing              the "Match our existing templates" box was ticked.
 *   example_template_ids_json   the agency's OWN templates that were shown to the model as examples (null = box off).
 * Old rows keep NULL / false (they were made before the style library existed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_template_generations', function (Blueprint $table) {
            $table->string('style', 40)->nullable()->after('property_type');
            $table->boolean('match_existing')->default(false)->after('style');
            $table->json('example_template_ids_json')->nullable()->after('match_existing');
        });
    }

    public function down(): void
    {
        Schema::table('ai_template_generations', function (Blueprint $table) {
            $table->dropColumn(['style', 'match_existing', 'example_template_ids_json']);
        });
    }
};
