<?php

declare(strict_types=1);

use App\Models\Agency;
use App\Services\Billing\SubscriptionReconciler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every agency moves to the band price list (Johan 2026-10-20, decision 1 after the audit: "existing agencies move to the band
 * prices"). 2026_11_16_100700 first pinned existing agencies to the Team/Agency tiers; this moves them all to the bands and settles
 * each one's band from its headcount through the normal reconciler - silently (a move from a tier plan to a band is never emailed,
 * and above the last band is on quotation without an email: decision 3). A custom amount or discount an agency already has is left
 * alone - it still beats the list price. Spec: agency-billing.md D7.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agency_subscriptions', 'price_list')) {
            return;
        }

        DB::table('agency_subscriptions')->where('price_list', 'tiers_2026_07')->update(['price_list' => 'bands_2026_10', 'updated_at' => now()]);

        $reconciler = app(SubscriptionReconciler::class);
        Agency::query()->orderBy('id')->chunkById(100, function ($agencies) use ($reconciler) {
            foreach ($agencies as $agency) {
                $reconciler->reconcile($agency);
            }
        });
    }

    public function down(): void
    {
        // One-way: which agencies were on the tiers before is not recorded, and a price list is never guessed back.
    }
};
