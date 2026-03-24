<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('docuperfect_templates', 'category')) {
            Schema::table('docuperfect_templates', function (Blueprint $table) {
                $table->enum('category', ['sales', 'rentals'])->nullable()->after('document_type_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('docuperfect_templates', 'category')) {
            Schema::table('docuperfect_templates', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
