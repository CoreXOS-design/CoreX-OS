<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live bug: POST /api/prospecting/import threw a 500 "Column 'price' cannot
 * be null" for POA / vacant-land listings (Margate has many). A 500 aborts
 * the whole batch, so every listing in that capture failed to land — the
 * root cause of the "disappearing batches" and the false "removed" listings
 * in the Margate integrity test (siblings didn't sell; the batch died on a
 * priceless row).
 *
 * prospecting_listings.price and prospecting_price_history.old_price/new_price
 * must accept NULL so a price-less capture stores cleanly as "price on
 * application" instead of crashing the batch.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->integer('price')->nullable()->change();
        });

        Schema::table('prospecting_price_history', function (Blueprint $table) {
            $table->integer('old_price')->nullable()->change();
            $table->integer('new_price')->nullable()->change();
        });
    }

    public function down(): void
    {
        $nullPriceCount = \DB::table('prospecting_listings')->whereNull('price')->count();
        if ($nullPriceCount > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$nullPriceCount} prospecting_listings row(s) have NULL price. "
                . 'Backfill or delete them before re-attempting this rollback.'
            );
        }

        $nullHistoryCount = \DB::table('prospecting_price_history')
            ->whereNull('old_price')
            ->orWhereNull('new_price')
            ->count();
        if ($nullHistoryCount > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$nullHistoryCount} prospecting_price_history row(s) have NULL old_price/new_price. "
                . 'Backfill or delete them before re-attempting this rollback.'
            );
        }

        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->integer('price')->nullable(false)->change();
        });

        Schema::table('prospecting_price_history', function (Blueprint $table) {
            $table->integer('old_price')->nullable(false)->change();
            $table->integer('new_price')->nullable(false)->change();
        });
    }
};
