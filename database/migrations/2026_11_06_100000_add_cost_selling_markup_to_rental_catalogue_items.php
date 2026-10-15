<?php

use App\Models\Agency;
use App\Models\RentalVatType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.38 — the catalogue item's cost / markup / selling block.
 *
 * New columns: cost_excl, cost_incl, selling_excl, selling_incl (12,2), markup_type (rand|percent), markup_value (12,4).
 * All nullable. The old `default_price` (= SELLING excl) and `default_cost` (= COST excl) stay, readable and kept in step
 * by RentalCatalogueItem's saving hook, until nothing reads them.
 *
 * Back-fill: selling_excl/cost_excl copy the old columns; the incl figures are worked out at the item's own VAT type rate
 * (no VAT type / agency not VAT registered = 0 %, exactly as the catalogue list has always read it). Where both a cost and a
 * selling price exist the markup is derived (percent; rand when the cost is nil) — it is never invented otherwise.
 * Idempotent: columns are guarded and only rows with a NULL new figure are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            foreach (['cost_excl', 'cost_incl', 'selling_excl', 'selling_incl'] as $col) {
                if (! Schema::hasColumn('rental_catalogue_items', $col)) {
                    $table->decimal($col, 12, 2)->nullable();
                }
            }
            if (! Schema::hasColumn('rental_catalogue_items', 'markup_type')) {
                $table->string('markup_type', 10)->nullable(); // rand | percent
            }
            if (! Schema::hasColumn('rental_catalogue_items', 'markup_value')) {
                $table->decimal('markup_value', 12, 4)->nullable();
            }
        });

        $agencies = Agency::withoutGlobalScopes()->get(['id', 'vat_registered'])->keyBy('id');
        $types = RentalVatType::withoutGlobalScopes()->withTrashed()->get()->keyBy('id');

        DB::table('rental_catalogue_items')
            ->where(fn ($q) => $q->whereNotNull('default_price')->orWhereNotNull('default_cost'))
            ->where(fn ($q) => $q->whereNull('selling_excl')->whereNull('cost_excl'))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($agencies, $types) {
                foreach ($rows as $row) {
                    $rate = 0.0;
                    $type = $row->default_rental_vat_type_id ? ($types[$row->default_rental_vat_type_id] ?? null) : null;
                    if ($type && ($agencies[$row->agency_id]->vat_registered ?? false)) {
                        $rate = $type->rate_mode === RentalVatType::RATE_MODE_CUSTOM_PER_LINE
                            ? (float) ($row->default_custom_vat_rate ?? 0)
                            : (float) $type->liveRate();
                    }
                    $incl = fn ($v) => $v === null ? null : round((float) $v * (1 + $rate / 100), 2);

                    $sell = $row->default_price !== null ? round((float) $row->default_price, 2) : null;
                    $cost = $row->default_cost !== null ? round((float) $row->default_cost, 2) : null;

                    $markupType = null;
                    $markupValue = null;
                    if ($sell !== null && $cost !== null) {
                        if ($cost > 0) {
                            $markupType = 'percent';
                            $markupValue = round(($sell - $cost) / $cost * 100, 4);
                        } else {
                            $markupType = 'rand';
                            $markupValue = round($sell - $cost, 2);
                        }
                    }

                    DB::table('rental_catalogue_items')->where('id', $row->id)->update([
                        'selling_excl' => $sell, 'selling_incl' => $incl($sell),
                        'cost_excl' => $cost, 'cost_incl' => $incl($cost),
                        'markup_type' => $markupType, 'markup_value' => $markupValue,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            foreach (['cost_excl', 'cost_incl', 'selling_excl', 'selling_incl', 'markup_type', 'markup_value'] as $col) {
                if (Schema::hasColumn('rental_catalogue_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
