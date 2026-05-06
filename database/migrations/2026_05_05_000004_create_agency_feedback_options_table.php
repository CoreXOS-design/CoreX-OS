<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agency_feedback_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category'); // outcome, concern, lost_reason
            $table->string('label');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_system_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'category', 'is_active'], 'afo_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_feedback_options');
    }
};
