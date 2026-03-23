<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add sections JSON to signature_templates (signing-time section config, copied from template)
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->json('sections_json')->nullable()->after('flattened_pages_json');
        });
    }

    public function down(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropColumn('sections_json');
        });
    }
};
