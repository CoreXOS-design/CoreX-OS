<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-takeon-import.md §2.1 — the batch. Mirrors p24_import_runs
 * (app/Models/P24ImportRun.php) deliberately: same shape, new table, because
 * this is a different feature with a different schema, not a shared base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_take_on_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('parsing');
            $table->string('source_filename')->nullable();
            $table->string('source_file_path')->nullable();
            $table->json('counts_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_take_on_import_runs');
    }
};
