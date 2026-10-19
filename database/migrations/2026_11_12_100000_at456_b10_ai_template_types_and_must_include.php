<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 §B.10 - what a "Create with AI" request was ASKED for, kept on the generation row (the usage record):
 *   property_types_json   the property types ticked (exact spelling from the agency's own Property Type list); null = none ticked.
 *   must_include_json     {"keys":[...ticked must-include keys...],"ribbon_text":"..."}; null = nothing ticked.
 * Old rows keep NULL (they were made before the pop-up had these options). Both are nullable JSON: no default, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_template_generations', function (Blueprint $table) {
            $table->json('property_types_json')->nullable()->after('property_type');
            $table->json('must_include_json')->nullable()->after('property_types_json');
        });
    }

    public function down(): void
    {
        Schema::table('ai_template_generations', function (Blueprint $table) {
            $table->dropColumn(['property_types_json', 'must_include_json']);
        });
    }
};
