<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_ad_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('layout_json');   // array of element objects
            $table->boolean('is_global')->default(false);  // super_admin/admin can share
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_ad_templates');
    }
};
