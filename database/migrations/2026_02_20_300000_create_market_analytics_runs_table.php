<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_analytics_runs', function (Blueprint $table) {
            $table->id();

            $table->string('model_version', 32)->comment('Semver-style model identifier e.g. v1.0.0');
            $table->string('inputs_hash', 64)->comment('SHA-256 of canonical inputs JSON');

            // TEXT columns (SQLite-safe; array cast handles JSON encode/decode)
            $table->text('inputs_json')->comment('Canonical serialised input parameters');
            $table->text('outputs_json')->nullable()->comment('Flat key-value of computed metrics');
            $table->text('breakdown_json')->nullable()->comment('Detailed per-metric breakdown');
            $table->text('data_sources_json')->nullable()->comment('Records of data sources consulted');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            // Composite index to detect duplicate/cached runs
            $table->index(['model_version', 'inputs_hash'], 'mar_version_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_analytics_runs');
    }
};
