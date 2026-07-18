<?php

use App\Console\Commands\BackfillAgencyFeatures;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Run the feature backfill on deploy (spec: corex-feature-registry.md §4.2).
 *
 * Delegates to the idempotent `agency:backfill-features` command so existing live
 * agencies keep every module they could reach pre-registry (default-OFF features
 * get an explicit ON row). Travels to every environment via `migrate --force`
 * (BUILD_STANDARD §8), and is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: only run once the table exists (it does — previous migration).
        if (!Schema::hasTable('agency_features')) {
            return;
        }

        Artisan::call(BackfillAgencyFeatures::class);
    }

    public function down(): void
    {
        // Backfill rows are all `enabled = true` for default-OFF features; on
        // rollback they are harmless (they only matched the registry intent).
        // Intentionally left in place — no destructive down.
    }
};
