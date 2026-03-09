<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('listing_type')->default('sale'); // 'sale' | 'rental'
            $table->string('category')->nullable();
            $table->string('property_type')->nullable();
            $table->unsignedInteger('price_min')->nullable();
            $table->unsignedInteger('price_max')->nullable();
            $table->unsignedTinyInteger('beds_min')->nullable();
            $table->unsignedTinyInteger('baths_min')->nullable();
            $table->unsignedTinyInteger('garages_min')->nullable();
            $table->unsignedTinyInteger('parking_min')->nullable();
            $table->unsignedInteger('floor_size_min')->nullable();
            $table->unsignedInteger('floor_size_max')->nullable();
            $table->unsignedInteger('erf_size_min')->nullable();
            $table->unsignedInteger('erf_size_max')->nullable();
            $table->string('suburb')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_matches');
    }
};
