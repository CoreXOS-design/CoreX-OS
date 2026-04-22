<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_document_type_configs', function (Blueprint $table) {
            $table->dropColumn('allows_branch_override');
        });
    }

    public function down(): void
    {
        Schema::table('agency_document_type_configs', function (Blueprint $table) {
            $table->boolean('allows_branch_override')->default(false)->after('required');
        });
    }
};
