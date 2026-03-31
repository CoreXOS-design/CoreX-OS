<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('city')->nullable()->change();
            $table->string('listing_type')->nullable()->change();
            $table->string('primary_price_display')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('city')->nullable(false)->default('')->change();
            $table->string('listing_type')->nullable(false)->default('')->change();
            $table->string('primary_price_display')->nullable(false)->default('')->change();
        });
    }
};
