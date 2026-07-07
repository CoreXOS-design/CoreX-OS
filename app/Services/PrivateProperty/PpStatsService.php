<?php

namespace App\Services\PrivateProperty;

use App\Models\Agency;
use App\Models\Property;
use App\Models\PropertyPortalMetric;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Nightly Private Property engagement snapshot. Calls the Agency Feed Service op
 * ListingPerformanceStats (Views, Messages, TelLeads, Alerts) per date and upserts
 * one row per (property, portal='pp', date) into property_portal_metrics — the same
 * store the P24 stats sweep writes, portal-discriminated. Mirrors P24StatsService's
 * contract so the engagement panel reads both series identically.
 *
 * PP exposes NO historical backfill, so the series ACCUMULATES from the day the
 * toggle is switched on — each night snapshots the last few finalised days
 * (idempotent per (listing, date), so re-runs correct late figures without dupes).
 *
 * DORMANT unless the agency's pp_stats_pull_enabled flag is ON. A SOAP fault is
 * logged + skipped — it never throws, so a PP outage can't touch the P24 sweep or
 * the nightly schedule. The call is branch-wide per date (a handful of calls a
 * night, not one per listing) — courteous by construction.
 */
class PpStatsService
{
    /** Refs per ListingPerformanceStats call — keep the SOAP envelope sane. */
    private const REF_BATCH = 100;

    /** Finalised days to (re)snapshot each run — catches late-corrected figures. */
    private const DEFAULT_LOOKBACK_DAYS = 3;

    public function pullForAllAgencies(int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS): array
    {
        $results = [];

        $agencies = Agency::query()
            ->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('pp_stats_pull_enabled', true)
            ->get();

        foreach ($agencies as $agency) {
            $cfg = PrivatePropertyConfig::for($agency);
            if (empty($cfg['username']) || empty($cfg['password']) || empty($cfg['branch_guid'])) {
                Log::channel('private_property')->warning('PP stats: toggle on but credentials incomplete — skipping', [
                    'agency_id' => $agency->id,
                ]);
                $results[$agency->id] = ['skipped_no_creds' => true];
                continue;
            }
            $results[$agency->id] = $this->pullForAgency($agency, $lookbackDays);
        }

        return $results ?: ['dormant' => true];
    }

    /**
     * Snapshot the last $lookbackDays finalised days for one agency's active PP
     * listings. Failure-contained: a fault on one date logs and moves on.
     */
    public function pullForAgency(Agency $agency, int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS): array
    {
        // ACTIVE STOCK ONLY (Johan's scope ruling) — on-market listings actively
        // syndicated to PP. scopeOnMarket() is the codebase's single source of truth
        // (status NOT IN OFF_MARKET_STATUSES); off-market listings keep their frozen
        // history but are no longer snapshotted. Map pp_ref (PP's T-number) → property.
        $refMap = Property::withoutGlobalScopes()
            ->onMarket()
            ->where('agency_id', $agency->id)
            ->whereNotNull('pp_ref')->where('pp_ref', '!=', '')
            ->whereRaw('LOWER(pp_syndication_status) = ?', ['active'])
            ->get(['id', 'pp_ref', 'agency_id'])
            ->keyBy(fn ($p) => (string) $p->pp_ref);

        if ($refMap->isEmpty()) {
            return ['listings' => 0, 'upserted' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $client   = app(PrivatePropertySoapClient::class)->forAgency($agency);
        $refChunks = array_chunk($refMap->keys()->all(), self::REF_BATCH);

        $upserted = 0;
        $skipped  = 0;
        $errors   = 0;

        // endDate exclusive: snapshot [today - lookback, today) finalised days.
        for ($i = $lookbackDays; $i >= 1; $i--) {
            $date = now()->subDays($i)->format('Y-m-d\TH:i:s');
            $metricDate = now()->subDays($i)->format('Y-m-d');

            foreach ($refChunks as $refs) {
                try {
                    $response = $client->listingPerformanceStats($refs, $date);
                } catch (\Throwable $e) {
                    $errors++;
                    Log::channel('private_property')->warning('PP stats call threw (contained)', [
                        'agency_id' => $agency->id, 'date' => $metricDate, 'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                if (($response['error'] ?? false) === true) {
                    $errors++;
                    Log::channel('private_property')->warning('PP stats SOAP fault (skipped)', [
                        'agency_id' => $agency->id, 'date' => $metricDate, 'message' => $response['message'] ?? null,
                    ]);
                    continue;
                }

                foreach ($this->extractRows($response) as $row) {
                    $ref = (string) ($row['PropertyRef'] ?? $row['PropertyRefs'] ?? '');
                    $property = $refMap->get($ref);
                    if (! $property) {
                        $skipped++;
                        continue;
                    }
                    $this->upsertRow($property, $ref, $metricDate, $row);
                    $upserted++;
                }
            }
        }

        return ['listings' => $refMap->count(), 'upserted' => $upserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function upsertRow(Property $property, string $ref, string $metricDate, array $row): void
    {
        PropertyPortalMetric::withoutGlobalScopes()->updateOrCreate(
            [
                'property_id' => $property->id,
                'portal'      => PropertyPortalMetric::PORTAL_PP,
                'metric_date' => $metricDate,
            ],
            [
                'agency_id'             => $property->agency_id,
                'portal_listing_number' => $ref,
                'view_count'            => (int) ($row['Views'] ?? 0),
                'alert_count'           => (int) ($row['Alerts'] ?? 0),
                'tel_leads'             => (int) ($row['TelLeads'] ?? 0),
                // PP "Messages" = enquiry messages — the PP analogue of P24's lead count.
                'total_leads'           => (int) ($row['Messages'] ?? 0),
                'synced_at'             => now(),
            ]
        );
    }

    /**
     * Unwrap the ListingPerformanceStats envelope to a flat list of per-listing
     * rows. Handles the *Result wrapper and the single-vs-array shape PHP's
     * SoapClient produces.
     */
    private function extractRows(array $response): array
    {
        $node = $response;
        foreach (['ListingPerformanceStatsResult', 'ListingPerformanceStats', 'ArrayOfListingPerformanceStatsOnDate'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                $node = $node[$key];
            }
        }

        $list = $node['ListingPerformanceStatsOnDate'] ?? $node;
        if (! is_array($list) || empty($list)) {
            return [];
        }
        // Single row → assoc; many → list.
        if (array_keys($list) !== range(0, count($list) - 1)) {
            return [$list];
        }
        return array_values(array_filter($list, 'is_array'));
    }
}
