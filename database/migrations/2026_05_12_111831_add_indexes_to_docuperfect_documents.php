<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docuperfect_documents', function (Blueprint $table) {
            $table->index(['property_id', 'document_type', 'id'], 'idx_dpdocs_prop_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_documents', function (Blueprint $table) {
            $table->dropIndex('idx_dpdocs_prop_type_id');
        });
    }
};
