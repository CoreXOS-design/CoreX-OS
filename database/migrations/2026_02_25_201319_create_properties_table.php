<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->uuid('external_id')->unique()->index();

            // Core listing details
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price')->default(0); // ZAR (not cents)

            // Location
            $table->string('suburb')->default('');
            $table->string('region')->nullable();

            // Property attributes
            $table->tinyInteger('beds')->default(0);
            $table->tinyInteger('baths')->default(0);
            $table->tinyInteger('garages')->default(0);
            $table->unsignedInteger('size_m2')->nullable();
            $table->unsignedInteger('erf_size_m2')->nullable();

            // Classification
            $table->string('property_type')->default('house'); // house/flat/townhouse/etc
            $table->string('mandate_type')->nullable();         // sole/joint/open

            // Workflow
            $table->string('status')->default('draft');        // draft/active/sold/withdrawn
            $table->json('images_json')->nullable();

            // Relationships
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();

            // Sync tracking
            $table->timestamp('published_at')->nullable(); // null = draft; set = published & will sync

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
