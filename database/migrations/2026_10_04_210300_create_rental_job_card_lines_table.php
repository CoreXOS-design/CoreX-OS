<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-442 — a job card's parts/labour lines, picked from the agency catalogue
 * (rental_catalogue_items) or free text. Quantity/price are real decimal
 * columns — not a JSON blob — so a later money stage (AT-446) can turn a
 * completed job card's lines into charges without a schema change; no
 * invoicing is built here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_job_card_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_job_card_id')->constrained(indexName: 'rjcl_job_card_fk')->cascadeOnDelete();
            $table->foreignId('rental_catalogue_item_id')->nullable()
                ->constrained(indexName: 'rjcl_catalogue_item_fk')->nullOnDelete();
            // nullable — a free-text line (nothing in the catalogue fits)
            // is always allowed; the catalogue guides, it does not constrain.

            $table->string('type', 10);
            // labour | part — copied from the catalogue item at the time the
            // line was added (or picked directly for a free-text line), so a
            // later edit/archive of the catalogue item never changes a
            // historical line's own type.
            $table->string('description', 255);
            // pre-filled from the catalogue item's name when one is picked,
            // always editable, free text when none is picked.
            $table->string('unit', 30)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->nullable();
            // nullable — the agency setting capture_prices_on_job_cards can
            // be off, in which case this (and line_total) stays null and no
            // price column renders anywhere (rental_work_order_settings).
            $table->decimal('line_total', 10, 2)->nullable();
            // quantity * unit_price, recalculated server-side on every
            // save — never trusted from the client.

            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'rental_job_card_id'], 'rjcl_agency_job_card_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_job_card_lines');
    }
};
