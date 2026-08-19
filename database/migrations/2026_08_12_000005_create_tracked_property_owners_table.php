<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_property_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tracked_property_id')->constrained('tracked_properties')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('id_number', 20)->nullable();
            $table->string('id_type')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['tracked_property_id']);
            $table->index(['id_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_property_owners');
    }
};
