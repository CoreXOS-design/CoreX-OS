<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_columns', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // must match daily_activities column keys e.g. calls_made
            $table->string('label');
            $table->string('group')->nullable();
            $table->string('input_type')->default('number'); // future: select/checkbox/etc
            $table->boolean('default_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_columns');
    }
};
