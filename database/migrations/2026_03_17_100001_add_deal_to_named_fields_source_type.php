<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Use the schema builder (not a raw MySQL "ALTER ... MODIFY") so this
        // runs on both MySQL (production) and SQLite (test DB). The column was
        // originally created via $table->enum() in
        // 2026_03_07_200004_add_source_mapping_to_named_fields.
        Schema::table('docuperfect_named_fields', function (Blueprint $table) {
            $table->enum('source_type', ['property', 'contact', 'agent', 'deal', 'static', 'computed', 'manual'])
                ->default('manual')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_named_fields', function (Blueprint $table) {
            $table->enum('source_type', ['property', 'contact', 'agent', 'static', 'computed', 'manual'])
                ->default('manual')
                ->change();
        });
    }
};
