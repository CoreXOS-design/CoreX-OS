<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->string('country_code', 2)->default('ZA');
            $table->date('holiday_date');
            $table->string('name', 100);
            $table->boolean('is_movable')->default(false);
            $table->smallInteger('applies_to_year')->unsigned();
            $table->timestamps();

            $table->unique(['country_code', 'holiday_date']);
            $table->index(['country_code', 'applies_to_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
