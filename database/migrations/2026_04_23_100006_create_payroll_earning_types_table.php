<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_earning_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->string('code', 30);
            $table->string('label', 100);
            $table->string('sars_source_code', 4)->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_fringe_benefit')->default(false);
            $table->boolean('affects_uif_remuneration')->default(true);
            $table->boolean('affects_sdl_remuneration')->default(true);
            $table->tinyInteger('sort_order')->unsigned()->default(0);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_earning_types');
    }
};
