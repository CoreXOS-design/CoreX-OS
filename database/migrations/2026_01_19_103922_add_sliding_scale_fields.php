<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sliding_enabled')->default(false);

            $table->decimal('sliding_tier1_cut_percent', 5, 2)->nullable();
            $table->decimal('sliding_tier2_cut_percent', 5, 2)->nullable();
            $table->decimal('sliding_tier3_cut_percent', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'sliding_enabled',
                'sliding_tier1_cut_percent',
                'sliding_tier2_cut_percent',
                'sliding_tier3_cut_percent',
            ]);
        });
    }
};
