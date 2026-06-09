<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when an agent has reviewed the AI image suggestions for a property.
 * The web property workspace opens the "AI photo suggestions" modal only for
 * completed analyses whose suggestions have NOT yet been reviewed, so the
 * modal stops nagging once the agent has acted on (or dismissed) them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('property_image_analyses', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('processed_at')
                ->comment('When an agent reviewed/dismissed these AI suggestions in the property workspace');
        });
    }

    public function down(): void
    {
        Schema::table('property_image_analyses', function (Blueprint $table) {
            $table->dropColumn('reviewed_at');
        });
    }
};
