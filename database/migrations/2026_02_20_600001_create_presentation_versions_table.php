<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->unsignedBigInteger('compiled_by')->nullable(); // FK → users
            $table->string('blueprint_version', 20)->default('v1');
            $table->unsignedBigInteger('analytics_run_id')->nullable();
            $table->unsignedBigInteger('probability_run_id')->nullable();
            $table->longText('data_snapshot_json');
            $table->timestamp('compiled_at')->useCurrent();
            $table->timestamps();

            $table->index(['presentation_id', 'compiled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_versions');
    }
};
