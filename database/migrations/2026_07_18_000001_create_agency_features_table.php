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

            // One override row per (agency, feature). deleted_at is deliberately
            // NOT part of the unique key: MySQL treats NULL as distinct, so including
            // it would permit multiple LIVE rows for the same (agency, feature) and
            // the gate would then resolve nondeterministically. These rows are config
            // state that the app never soft-deletes (toggling flips `enabled`, it does
            // not delete), so a real two-column unique is both correct and safe.
            // (feature_key index kept for cross-agency "who has X on" queries; the
            // composite unique already covers agency_id-leading lookups.)
            $table->index('feature_key');
            $table->unique(['agency_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_features');
    }
};
