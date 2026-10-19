<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pins every agency to the price list it joined on (spec agency-billing §3 D7, Johan 2026-10-19).
 *
 * Every agency already on CoreX keeps the Team / Agency tier prices it signed for ('tiers_2026_07');
 * agencies created after this runs are put on the band price list by AgencySubscription::forAgency().
 *
 * An existing agency that has no subscription row yet gets one here, on the tier list — otherwise its
 * first billing read would create the row on the NEW list and quietly re-price an agency that joined
 * on the old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agency_subscriptions', 'price_list')) {
            Schema::table('agency_subscriptions', function (Blueprint $table) {
                $table->string('price_list', 30)->nullable()->after('plan');
            });
        }

        DB::table('agency_subscriptions')->whereNull('price_list')->update(['price_list' => 'tiers_2026_07']);

        $maxTeamSeats = (int) config('corex-billing.team.max_seats', 10);
        $now = now();
        // Same seat definition as SubscriptionPricingService::billableSeats(): active, not archived, not an assistant.
        $seatCounts = DB::table('users')
            ->selectRaw('agency_id, COUNT(*) AS seats')
            ->whereNotNull('agency_id')
            ->where('is_active', 1)
            ->where('is_assistant', 0)
            ->whereNull('deleted_at')
            ->groupBy('agency_id')
            ->pluck('seats', 'agency_id');
        $rows = DB::table('agencies')
            ->whereNotIn('id', DB::table('agency_subscriptions')->select('agency_id'))
            ->pluck('id')
            ->map(fn ($agencyId) => [
                'agency_id'       => $agencyId,
                'plan'            => ((int) ($seatCounts[$agencyId] ?? 0)) > $maxTeamSeats ? 'agency' : 'team',
                'price_list'      => 'tiers_2026_07',
                'plan_changed_at' => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ])
            ->all();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('agency_subscriptions')->insert($chunk);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_subscriptions', 'price_list')) {
            Schema::table('agency_subscriptions', function (Blueprint $table) {
                $table->dropColumn('price_list');
            });
        }
    }
};
