<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_match_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_match_id')->constrained('contact_matches')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('reaction', 20); // interested | not_interested | saved
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['contact_match_id', 'property_id'], 'cmf_match_property_unique');
            $table->index(['property_id', 'reaction'], 'cmf_property_reaction_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_match_feedback');
    }
};
