<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->longText('embedding')->nullable()->after('metadata');
            $table->boolean('has_embedding')->default(false)->after('embedding');
            $table->index('has_embedding');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->dropIndex(['has_embedding']);
            $table->dropColumn(['embedding', 'has_embedding']);
        });
    }
};
