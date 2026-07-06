<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal engagement metrics (views, alerts, per-day lead breakdown) pulled back
 * from the syndication portals, keyed to the Property pillar. One row per
 * (property, portal, metric_date). Property24 is the only portal that exposes a
 * statistics API today; the `pp` enum value is reserved for the day Private
 * Property (or any future portal) surfaces the same. See
 * .ai/specs/portal-metrics.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_portal_metrics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();

            $table->enum('portal', ['p24', 'pp']);
            $table->string('portal_listing_number', 64);
            $table->date('metric_date');

            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('alert_count')->default(0);
            $table->unsignedInteger('tel_leads')->default(0);
            $table->unsignedInteger('sms_leads')->default(0);
            $table->unsignedInteger('request_details_leads')->default(0);
            $table->unsignedInteger('total_leads')->default(0);
            $table->unsignedInteger('total_contact_leads')->default(0);
            $table->decimal('price', 15, 2)->nullable();

            $table->timestamp('synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'portal', 'metric_date'], 'ppm_property_portal_date_uq');
            $table->index(['agency_id', 'portal', 'metric_date'], 'ppm_agency_portal_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_portal_metrics');
    }
};
