<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Score;

use App\Models\MicAgencySetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MIC step 5.7 (.ai/specs/mic-work-tab.md section 3 + contract 11.1): the opportunity score of a property file, 0-100.
 *
 *   score = sum(weight_i x part_i) / 100,   weights = the agency's settings (default seller 30 / buyer 25 / pocket 20 / known 15 / reachable 10)
 *   seller    cuts, re-listed, long days-on-market, owner advertising privately
 *   buyer     strong / mid buyers waiting (cold + lost buyers are already excluded in the stored counts)
 *   pocket    fast-selling pocket for us, buyers waiting in the pocket (stock short), leads in the pocket
 *   known     address, deeds owner, P24 alert history, several adverts agreeing, full facts
 *   reachable owner contact held, nobody in our agency working it
 * Each row shows its top two reasons (the largest weighted contributions). The stored score is VIEWER-NEUTRAL: the colleague-claimed /
 * not-on-market / do-not-contact rules are applied per viewer by the screen (cc6) from the claim and work record. Reads the stored file
 * and pocket stats (5.5); writes only the score columns of mic_file_stats.
 */
final class OpportunityScoreService
{
    private const LABELS = [
        'price_cuts'     => 'Price cut',
        'relisted'       => 'Re-listed',
        'long_dom'       => 'Long on the market',
        'owner_selling'  => 'Owner selling privately',
        'mandate_ended'  => 'Mandate ended elsewhere',
        'strong_buyers'  => 'Strong buyers waiting',
        'buyers_waiting' => 'Buyers waiting in this pocket',
        'fast_pocket'    => 'Fast-selling pocket',
        'lead_pocket'    => 'Leads in this pocket',
        'pocket_activity' => 'Activity in this pocket',
        'well_known'     => 'Well-known property',
        'deeds_owner'    => 'Owner known from deeds',
        'alert_history'  => 'P24 alert history',
        'reachable'      => 'Owner contact on file',
        'back_on_market' => 'Back on the market',
        'unworked'       => 'Nobody working it',
    ];

    private const CHUNK = 1000;

    /** Score every file of an agency from the stored stats; returns the number of files scored. */
    public function scoreAgency(int $agencyId, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $ctx = $this->context($agencyId);
        $n = 0;
        DB::table('mic_file_stats')->where('agency_id', $agencyId)->orderBy('tracked_property_id')->select('tracked_property_id')
            ->chunk(self::CHUNK, function ($rows) use ($agencyId, $ctx, $now, &$n) {
                $n += $this->scoreChunk($agencyId, $rows->pluck('tracked_property_id')->all(), $ctx, $now);
            });

        return $n;
    }

    /** Recompute one file's score from its stored stats (used on change); null if the file has no stats row. */
    public function score(int $agencyId, int $trackedPropertyId): ?ScoreResult
    {
        $now = Carbon::now();
        if ($this->scoreChunk($agencyId, [$trackedPropertyId], $this->context($agencyId), $now) === 0) {
            return null;
        }
        $r = DB::table('mic_file_stats')->where('agency_id', $agencyId)->where('tracked_property_id', $trackedPropertyId)->first();

        return new ScoreResult((float) $r->score, [
            'seller' => (int) $r->score_seller, 'buyer' => (int) $r->score_buyer, 'pocket' => (int) $r->score_pocket,
            'known' => (int) $r->score_known, 'reachable' => (int) $r->score_reachable,
        ], array_values(array_filter(array_map(fn ($c) => $c ? ['code' => $c, 'label' => self::LABELS[$c] ?? $c] : null, [$r->reason_1, $r->reason_2]))), $now);
    }

    /** @return array{settings: MicAgencySetting, weights: array, date: ?string} */
    private function context(int $agencyId): array
    {
        $settings = MicAgencySetting::for($agencyId);

        return ['settings' => $settings, 'weights' => $settings->scoreWeights(), 'date' => DB::table('mic_pocket_stats')->where('agency_id', $agencyId)->max('stat_date')];
    }

    /** @param list<int> $ids */
    private function scoreChunk(int $agencyId, array $ids, array $ctx, Carbon $now): int
    {
        /** @var MicAgencySetting $settings */
        $settings = $ctx['settings'];
        $files = DB::table('mic_file_stats')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)->get()->keyBy('tracked_property_id');
        if ($files->isEmpty()) {
            return 0;
        }

        $pockets = $ctx['date'] === null ? collect() : DB::table('mic_pocket_stats')->where('agency_id', $agencyId)->where('stat_date', $ctx['date'])
            ->whereIn('p24_suburb_id', $files->pluck('p24_suburb_id')->filter()->unique()->all())->get()
            ->keyBy(fn ($p) => $p->p24_suburb_id . '|' . $p->type_key . '|' . $p->beds . '|' . $p->band);

        $tps = DB::table('tracked_properties')->where('agency_id', $agencyId)->whereIn('id', $ids)
            ->get(['id', 'street_name', 'owner_contact_id', 'deeds_captured_at', 'property_type', 'bedrooms'])->keyBy('id');
        $owners = DB::table('tracked_property_owners')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)->distinct()->pluck('tracked_property_id')->flip();
        $privately = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)->where('is_active', 1)->whereNull('deleted_at')
            ->where('advertiser_type', 'owner')->distinct()->pluck('tracked_property_id')->flip();
        $alerts = DB::table('p24_listings')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)->whereNull('deleted_at')->distinct()->pluck('tracked_property_id')->flip();
        $worked = DB::table('prospecting_claims as c')->join('prospecting_listings as pl', 'pl.id', '=', 'c.prospecting_listing_id')
            ->where('c.agency_id', $agencyId)->where('c.is_active', 1)->whereNull('c.released_at')->whereNull('c.deleted_at')->whereIn('pl.tracked_property_id', $ids)
            ->distinct()->pluck('pl.tracked_property_id')->flip();
        $work = DB::table('tracked_property_agency_work')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('tracked_property_id', $ids)
            ->get(['tracked_property_id', 'prospecting_status', 'status_set_at'])->keyBy('tracked_property_id');
        $newestAdvert = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)->where('is_active', 1)->whereNull('deleted_at')
            ->groupBy('tracked_property_id')->selectRaw('tracked_property_id as tp, MAX(first_seen_at) as newest')->pluck('newest', 'tp');

        $w = $ctx['weights'];
        $stuckDom = $settings->stuckDomDays();
        $rows = [];
        foreach ($files as $id => $f) {
            $tp = $tps->get($id);
            $pocket = $pockets->get($f->p24_suburb_id . '|' . $f->type_key . '|' . $f->beds . '|' . $f->band);
            $active = (int) $f->has_active_advert === 1;

            // seller
            $seller = [
                'price_cuts'    => min(40, ((int) $f->cuts_count > 0 ? 30 : 0) + ((int) $f->cuts_count >= 2 || (int) $f->cuts_90d > 0 ? 10 : 0)),
                'relisted'      => (int) $f->relisted === 1 ? 20 : 0,
                'long_dom'      => $f->dom_days === null ? 0 : ((int) $f->dom_days >= 2 * $stuckDom ? 30 : ((int) $f->dom_days >= $stuckDom ? 20 : 0)),
                'owner_selling' => isset($privately[$id]) ? 30 : 0,
            ];
            $sellerPart = $active ? min(100, array_sum($seller)) : 0;

            // buyer
            $buyerPart = min(100, (int) $f->buyers_strong * 20 + (int) $f->buyers_mid * 5);

            // pocket
            $pk = [
                'fast_pocket'    => $pocket && (int) $pocket->own_fast_sales > 0 ? 40 : 0,
                'buyers_waiting' => $pocket && (int) $pocket->buyers >= 3 ? (20 + ((int) $pocket->buyers > (int) $pocket->market_stock + (int) $pocket->own_on_market ? 15 : 0)) : 0,
                'lead_pocket'    => $pocket && (int) $pocket->leads_30d > 0 ? 25 : 0,
            ];
            $pocketPart = min(100, array_sum($pk));

            // known
            $deeds = $tp && ($tp->deeds_captured_at !== null || isset($owners[$id]));
            $facts = ($f->type_key && $f->type_key !== 'other' ? 5 : 0) + ((int) $f->beds > 0 ? 5 : 0) + ((int) $f->price > 0 ? 5 : 0) + ($f->p24_suburb_id ? 5 : 0);
            $kn = [
                'address'       => $tp && trim((string) $tp->street_name) !== '' ? 25 : 0,
                'deeds_owner'   => $deeds ? 25 : 0,
                'alert_history' => isset($alerts[$id]) ? 15 : 0,
                'agreeing'      => (int) $f->adverts_total >= 2 ? 15 : 0,
                'facts'         => $facts,
            ];
            $knownPart = min(100, array_sum($kn));

            // reachable
            $contact = $tp && $tp->owner_contact_id !== null;
            $unworked = ! isset($worked[$id]) && (int) $f->own_stock === 0;
            $reachablePart = ($contact ? 50 : 0) + ($unworked ? 40 : 0) + 10;   // +10 = no opt-out on file (registry check is an OFF setting)

            $score = round(($w['seller'] * $sellerPart + $w['buyer'] * $buyerPart + $w['pocket'] * $pocketPart + $w['known'] * $knownPart + $w['reachable'] * $reachablePart) / 100, 2);

            // reasons: the biggest weighted contributions
            $backOnMarket = false;
            $wk = $work->get($id);
            if ($active && $wk && $wk->prospecting_status === 'not_selling' && $wk->status_set_at && ($newestAdvert[$id] ?? null) && Carbon::parse($newestAdvert[$id])->gt(Carbon::parse($wk->status_set_at))) {
                $backOnMarket = true;
            }
            $cand = [
                'price_cuts'     => $w['seller'] * $seller['price_cuts'] / 100,
                'relisted'       => $w['seller'] * $seller['relisted'] / 100,
                'long_dom'       => $w['seller'] * $seller['long_dom'] / 100,
                'owner_selling'  => $w['seller'] * $seller['owner_selling'] / 100,
                'strong_buyers'  => (int) $f->buyers_strong > 0 ? $w['buyer'] * $buyerPart / 100 : 0,
                'fast_pocket'    => $w['pocket'] * $pk['fast_pocket'] / 100,
                'buyers_waiting' => $w['pocket'] * $pk['buyers_waiting'] / 100,
                'lead_pocket'    => $w['pocket'] * $pk['lead_pocket'] / 100,
                'deeds_owner'    => $w['known'] * $kn['deeds_owner'] / 100,
                'alert_history'  => $w['known'] * $kn['alert_history'] / 100,
                'well_known'     => $knownPart >= 60 ? $w['known'] * $knownPart / 100 : 0,
                'reachable'      => $contact ? $w['reachable'] * 50 / 100 : 0,
                'unworked'       => $unworked ? $w['reachable'] * 40 / 100 : 0,
                'back_on_market' => $backOnMarket ? 100 : 0,   // always leads: the spec's "Back on the market" chip
            ];
            arsort($cand);
            $top = array_keys(array_filter($cand, fn ($v) => $v > 0));

            $rows[] = [
                'agency_id' => $agencyId, 'tracked_property_id' => $id, 'score' => $score,
                'score_seller' => $sellerPart, 'score_buyer' => $buyerPart, 'score_pocket' => $pocketPart, 'score_known' => $knownPart, 'score_reachable' => min(100, $reachablePart),
                'reason_1' => $top[0] ?? null, 'reason_2' => $top[1] ?? null,
            ];
        }

        DB::table('mic_file_stats')->upsert($rows, ['agency_id', 'tracked_property_id'], ['score', 'score_seller', 'score_buyer', 'score_pocket', 'score_known', 'score_reachable', 'reason_1', 'reason_2']);

        return count($rows);
    }

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }
}
