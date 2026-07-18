<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agency feature enablement store (spec: corex-feature-registry.md §4.1).
 *
 * Stores only DEVIATIONS from the registry default — "no row => registry
 * default" — so the table stays small. One live override per (agency, feature).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('feature_key', 80);
            $table->boolean('enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('agency_id');
            $table->index('feature_key');
            // One LIVE override per feature per agency. deleted_at in the unique
            // key lets a soft-deleted row be superseded by a fresh one.
            $table->unique(['agency_id', 'feature_key', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_features');
    }
};
