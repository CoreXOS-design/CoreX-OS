<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.39 — which edit PRICES a catalogue item: `markup` (the selling price is cost + markup, worked
 * out on whatever cost a job card line carries) or `selling` (the catalogue's own fixed selling price). Until now only the screen
 * knew, so saving an item again quietly turned a markup item into a fixed-price one.
 *
 * Back-fill (only rows with no driver yet): a row with a selling price becomes `selling` — the exception being a "selling" of
 * R0.00 on an item with no real cost and a markup set, which was a derived zero and not a price: that becomes `markup` and
 * its selling figure is cleared. A row with a markup but no selling price becomes `markup`. Everything else stays NULL.
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_catalogue_items', 'pricing_driver')) {
                $table->string('pricing_driver', 10)->nullable(); // markup | selling
            }
        });

        // A derived zero: selling 0.00, cost nil/0, a markup set.
        DB::table('rental_catalogue_items')->whereNull('pricing_driver')
            ->whereNotNull('markup_value')
            ->where('selling_excl', 0)
            ->where(fn ($q) => $q->whereNull('cost_excl')->orWhere('cost_excl', 0))
            ->update(['pricing_driver' => 'markup', 'selling_excl' => null, 'selling_incl' => null, 'default_price' => null]);

        DB::table('rental_catalogue_items')->whereNull('pricing_driver')->whereNotNull('markup_value')->whereNull('selling_excl')
            ->update(['pricing_driver' => 'markup']);

        DB::table('rental_catalogue_items')->whereNull('pricing_driver')->whereNotNull('selling_excl')
            ->update(['pricing_driver' => 'selling']);
    }

    public function down(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            if (Schema::hasColumn('rental_catalogue_items', 'pricing_driver')) {
                $table->dropColumn('pricing_driver');
            }
        });
    }
};
