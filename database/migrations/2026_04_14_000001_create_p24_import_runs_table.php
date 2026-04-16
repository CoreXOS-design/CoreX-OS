<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('p24_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->enum('kind', ['agents', 'listings_images']);
            $table->enum('status', ['parsing', 'pending_confirm', 'importing', 'completed', 'failed', 'cancelled'])
                  ->default('parsing');
            $table->string('agents_csv_path')->nullable();
            $table->string('listings_csv_path')->nullable();
            $table->string('images_csv_path')->nullable();
            $table->json('counts_json')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['agency_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p24_import_runs');
    }
};
