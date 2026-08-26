<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website listing statistics (AT-383) — engagement counted on an agency's own
 * website and pushed back to CoreX hourly via POST /api/v1/website/listings/stats.
 *
 * Three tables, one job each:
 *   website_stat_batches        — one row per accepted POST. Its unique
 *                                 (agency_id, site, batch_id) IS the idempotency
 *                                 guard: a replayed batch fails the insert and is
 *                                 answered from the stored row instead of being
 *                                 applied twice.
 *   listing_website_stats       — the daily time series. One row per
 *                                 (agency, site, listing, date, metric), incremented
 *                                 in place. Daily granularity keeps it small while
 *                                 still serving charts and any date range.
 *   listing_website_stat_totals — the website's own lifetime totals, as reported.
 *                                 Reconciliation surface (drift check vs our SUM)
 *                                 and the "all time" figure the UI shows.
 *
 * Column naming follows CoreX, not the wire contract: a listing is a `property`
 * here (as in property_portal_metrics), and the counter is `metric_count` rather
 * than the reserved-ish `count`. The HTTP contract is unchanged.
 *
 * Spec: .ai/specs/website-listing-stats.md §4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_stat_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('agency_api_key_id')->nullable()
                  ->constrained('agency_api_keys')->nullOnDelete();

            $table->string('site', 64);
            $table->string('batch_id', 64);
            $table->string('source', 32)->default('website');

            $table->unsignedInteger('listing_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('skipped_listing_ids')->nullable();
            $table->unsignedInteger('metric_row_count')->default(0);

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The idempotency guard. Enforced by the database so two concurrent
            // retries of the same batch can never both apply.
            $table->unique(['agency_id', 'site', 'batch_id'], 'wsb_agency_site_batch_uq');
            $table->index(['agency_id', 'site', 'received_at'], 'wsb_agency_site_received_idx');
        });

        Schema::create('listing_website_stats', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('site', 64);
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->date('stat_date');
            $table->string('metric', 40);
            $table->unsignedBigInteger('metric_count')->default(0);

            $table->timestamps();

            // Deliberately NO softDeletes: these rows are never user-deleted, and a
            // tombstoned row would still occupy the unique key below and silently
            // absorb every later increment for that day/metric.
            $table->unique(['agency_id', 'site', 'property_id', 'stat_date', 'metric'], 'lws_natural_uq');
            $table->index(['agency_id', 'stat_date', 'metric'], 'lws_agency_date_metric_idx');
            $table->index(['property_id', 'metric', 'stat_date'], 'lws_property_metric_date_idx');
        });

        Schema::create('listing_website_stat_totals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('site', 64);
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('metric', 40);
            $table->unsignedBigInteger('reported_total')->default(0);
            $table->timestamp('reported_at')->nullable();

            $table->timestamps();

            $table->unique(['agency_id', 'site', 'property_id', 'metric'], 'lwst_natural_uq');
            $table->index(['property_id', 'metric'], 'lwst_property_metric_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_website_stat_totals');
        Schema::dropIfExists('listing_website_stats');
        Schema::dropIfExists('website_stat_batches');
    }
};
