<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            // Map of property_id => reason text, captured when an agent hides a
            // property from a Core Match. Kept alongside hidden_property_ids.
            $table->json('hidden_property_reasons')->nullable()->after('hidden_property_ids');
        });
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->dropColumn('hidden_property_reasons');
        });
    }
};
