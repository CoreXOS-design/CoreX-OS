<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_migration_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            // run_id groups all entries from a single dry-run or live-run invocation.
            // UUID v4 — strings, not foreign keys; the log is a forensic record.
            $table->string('run_id', 40);

            // Soft pointer (no FK) — buyer_preferences will be dropped in Phase 2.
            $table->unsignedBigInteger('source_buyer_preference_id');

            // Populated by the live migration only; NULL for dry-run entries.
            $table->unsignedBigInteger('target_contact_match_id')->nullable();

            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('agency_id');

            $table->enum('action', [
                'would_create', 'would_append', 'would_merge', 'would_skip', 'would_fail',
                'created',      'appended',      'merged',      'skipped',      'failed',
            ]);
            $table->enum('mode', ['dry_run', 'live']);

            $table->text('notes')->nullable();
            $table->json('field_mapping_snapshot')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('run_id', 'wml_run_idx');
            $table->index('contact_id', 'wml_contact_idx');
            $table->index(['action', 'mode'], 'wml_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_migration_log');
    }
};
