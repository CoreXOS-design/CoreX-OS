<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_settings', function (Blueprint $table) {
            $table->id();

            // Simple key/value store for performance ratios and model settings
            // Example keys:
            // - listings_per_sale
            $table->string('key')->unique();
            $table->string('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_settings');
    }
};
