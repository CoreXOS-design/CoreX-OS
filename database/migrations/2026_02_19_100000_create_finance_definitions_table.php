<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_definitions', function (Blueprint $table) {
            $table->id();

            $table->string('key')->comment('e.g. deal.total_commission_ex_vat');
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft')->comment('draft|active|retired');

            $table->string('entity_type')->comment('e.g. deal');
            $table->string('value_type')->comment('money_ex_vat|money_inc_vat|percent|count|json');

            $table->text('expression')->nullable();
            $table->json('dependencies')->nullable();
            $table->unsignedSmallInteger('rounding_scale')->default(2);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_definitions');
    }
};
