<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_import_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('run_id')->constrained('listing_import_runs')->cascadeOnDelete();

            // Parsed + normalized fields (based on mapping)
            $table->string('external_id', 100)->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->string('property')->nullable();
            $table->string('status', 50)->nullable();
            $table->bigInteger('price_cents')->nullable();

            // Agent from file + resolved system user
            $table->string('file_agent')->nullable();
            $table->foreignId('resolved_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Matching result against system stock
            $table->foreignId('matched_listing_stock_id')->nullable()->constrained('listing_stocks')->nullOnDelete();
            $table->string('match_confidence', 20)->nullable(); // high|medium|low

            // Import decision (forced before apply)
            $table->string('decision', 30)->default('pending'); // pending|keep_system|use_import|create_new|skip

            // Full row snapshot
            $table->json('row_payload')->nullable();

            $table->timestamps();

            $table->index(['run_id', 'decision']);
            $table->index(['external_id']);
            $table->index(['external_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_import_rows');
    }
};
