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

    /**
     * Audit 2026-10-20 (L8) - a rollback that does not give back what up() took away is not a rollback. up() cleared the derived R0.00 selling (and default price) of
     * a markup item with no real cost; this puts the zero back on exactly the rows that shape identifies (driver `markup`, selling cleared, a markup set, no real cost).
     * It is the best the data allows: up() kept no list of the rows it changed, so a markup item that never had a selling price AND has no cost looks the same and gets the
     * same R0.00 - which is what the code before this migration always derived for it. up() itself is unchanged (it is already applied on Staging and live).
     * Public so a test can prove it without dropping the column.
     */
    public function restoreDerivedZeros(): int
    {
        if (! Schema::hasColumn('rental_catalogue_items', 'pricing_driver')) {
            return 0;
        }

        return DB::table('rental_catalogue_items')
            ->where('pricing_driver', 'markup')
            ->whereNotNull('markup_value')
            ->whereNull('selling_excl')
            ->where(fn ($q) => $q->whereNull('cost_excl')->orWhere('cost_excl', 0))
            ->update(['selling_excl' => 0, 'selling_incl' => 0, 'default_price' => 0]);
    }

    public function down(): void
    {
        $this->restoreDerivedZeros();

        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            if (Schema::hasColumn('rental_catalogue_items', 'pricing_driver')) {
                $table->dropColumn('pricing_driver');
            }
        });
    }
};
