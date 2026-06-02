<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — Phase 1a.
 *
 * Delivery log for webhooks pushed to agency websites. One row per send
 * attempt (event + payload + response), enabling retry-with-backoff and a
 * "delivery history" view per key. Surfaces dead endpoints in the UI.
 *
 * Spec: .ai/specs/agency-public-api.md §3.2, §6.2
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agency_webhook_deliveries')) {
            return;
        }

        Schema::create('agency_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('agency_api_key_id')->index();

            $table->string('event_name');          // e.g. listing.published
            $table->json('payload');

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('agency_api_key_id')->references('id')->on('agency_api_keys')->cascadeOnDelete();

            $table->index(['agency_api_key_id', 'event_name']);
            $table->index('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_webhook_deliveries');
    }
};
