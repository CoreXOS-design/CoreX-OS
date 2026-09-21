<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md — Stage 2 of the inspections rework. The
 * neutral room concept, deliberately NOT namespaced under rentals: Sales
 * needs "what rooms does this property have, how many" for inventory
 * (Johan: "its also used by sales, so it cannot be owned by the rentals
 * inspection"), while the periodic condition-tracking that reads/writes
 * this table stays rentals-owned (RentalInspectionItem, kind='space',
 * property_room_id below) — only the LIST is shared, per cc5's
 * investigation and cc4's agreement, 2026-09-21.
 *
 * `type` mirrors `rental_inspection_items.space_type` and
 * `properties.spaces_json`'s own space `type` string — the same free-text,
 * config('property-spaces.all_space_types')-informed-but-not-constrained
 * vocabulary already established for this feature. `label` is the specific
 * instance ("Bedroom 2"), matching `spaces_json`'s own unit labels exactly
 * when seeded from advertising.
 *
 * `is_retired`, not `deleted_at` — same reasoning as rental_inspection_items
 * §3.3: a room a real inspection or inventory item has ever referenced can
 * never become invisible to history-reading queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            $table->string('type', 60);
            $table->string('label', 191);
            // advertising_space | manual — traceability only, never gates behavior.
            $table->string('source', 30)->default('manual');
            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_retired')->default(false);
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['agency_id', 'property_id', 'is_retired']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_rooms');
    }
};
