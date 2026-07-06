<?php

namespace App\Services\Syndication\Property24;

use App\Models\Agency;
use App\Models\Property;
use App\Models\PropertyPortalMetric;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulls per-listing engagement statistics (views, alerts, lead breakdown) from
 * the Property24 v53 statistics API and persists them to property_portal_metrics,
 * one row per (property, portal, day). A rolling lookback window each run corrects
 * P24's next-day aggregation without a full backfill. Read back (summed over a
 * window) by PropertyIntelligenceService::getPortalPerformance for the Property
 * Intelligence Hub. See .ai/specs/portal-metrics.md.
 */
class P24StatsService
{
    public function __construct(
        private readonly Property24ApiClient $api,
    ) {
    }

    /**
     * Pull statistics for every agency that has P24 credentials configured.
     * Returns counts per agency for the calling job to log.
     */
    public function pullForAllAgencies(int $lookbackDays = 7): array
    {
        $results = [];

        $agencies = Agency::query()
            ->whereNotNull('p24_username')
            ->where('p24_username', '!=', '')
            ->get();

        if ($agencies->isEmpty()) {
            // Single-tenant / default-credential fallback — pull once with no agency override.
            $results['default'] = $this->pullForAgency(null, $lookbackDays);
            return $results;
        }

        foreach ($agencies as $agency) {
            $results[$agency->id] = $this->pullForAgency($agency, $lookbackDays);
        }

        return $results;
    }

    /**
     * Pull statistics for one agency (or default credentials when $agency is null),
     * across every live-syndicated Property that carries a numeric P24 listing number.
     */
    public function pullForAgency(?Agency $agency, int $lookbackDays = 7): array
    {
        $api = $agency ? new Property24ApiClient($agency) : $this->api;

        // endDate is EXCLUSIVE and P24 publishes next-day, so [today - lookback, today)
        // captures the last $lookbackDays of finalised daily stats (through yesterday).
        $startDate = now()->subDays($lookbackDays)->format('Y-m-d');
        $endDate   = now()->format('Y-m-d');

        $query = Property::withoutGlobalScopes()
            ->whereNotNull('p24_ref')
            ->where('p24_ref', '!=', '');

        if ($agency) {
            $query->where('agency_id', $agency->id);
        }

        $listings = 0;
        $upserted = 0;
        $skipped  = 0;
        $errors   = 0;

        $query->chunkById(200, function ($properties) use ($api, $startDate, $endDate, &$listings, &$upserted, &$skipped, &$errors) {
            foreach ($properties as $property) {
                $listingNumber = $this->resolveListingNumber($property);
                if ($listingNumber === null) {
                    $skipped++;
                    continue;
                }

                $listings++;
                $response = $api->getListingStatistics($listingNumber, $startDate, $endDate, $property->id);

                if (! ($response['success'] ?? false)) {
                    $errors++;
                    Log::channel('property24')->warning('P24 stats pull failed for listing', [
                        'property_id'    => $property->id,
                        'listing_number' => $listingNumber,
                        'message'        => $response['message'] ?? null,
                        'status'         => $response['status_code'] ?? null,
                    ]);
                    continue;
                }

                $rows = $this->extractRows($response['data'] ?? []);
                foreach ($rows as $row) {
                    if ($this->upsertRow($property, $listingNumber, $row)) {
                        $upserted++;
                    } else {
                        $skipped++;
                    }
                }
            }
        });

        return compact('listings', 'upserted', 'skipped', 'errors');
    }

    /**
     * The P24 listing number to query. `p24_ref` holds the P24-assigned number for
     * our activated syndicated stock; `p24_listing_number` is the inbound/CSV ref
     * used as a fallback. Must be purely numeric — the statistics endpoint takes an
     * integer listingNumber.
     */
    private function resolveListingNumber(Property $property): ?int
    {
        foreach ([$property->p24_ref, $property->p24_listing_number] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && ctype_digit($candidate)) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * Normalise the statistics payload to a list of daily rows. The v53 body is an
     * array of ListingStatistics; guard against the non-JSON `['raw' => ...]` wrap
     * and single-object responses.
     */
    private function extractRows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        if (isset($data['raw'])) {
            return [];
        }
        // Single object (has a scalar date) vs a list of daily rows.
        if (array_key_exists('date', $data) || array_key_exists('viewCount', $data)) {
            return [$data];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * Upsert one day's metrics. Idempotent on the unique (property, portal, date)
     * key so re-running the rolling window overwrites late-corrected figures.
     */
    private function upsertRow(Property $property, int $listingNumber, array $row): bool
    {
        $date = $row['date'] ?? null;
        if (empty($date)) {
            return false;
        }

        try {
            $metricDate = Carbon::parse($date)->format('Y-m-d');
        } catch (\Throwable $e) {
            return false;
        }

        PropertyPortalMetric::withoutGlobalScopes()->updateOrCreate(
            [
                'property_id' => $property->id,
                'portal'      => PropertyPortalMetric::PORTAL_P24,
                'metric_date' => $metricDate,
            ],
            [
                'agency_id'             => $property->agency_id,
                'portal_listing_number' => (string) $listingNumber,
                'view_count'            => (int) ($row['viewCount'] ?? 0),
                'alert_count'           => (int) ($row['alertCount'] ?? 0),
                'tel_leads'             => (int) ($row['telLeads'] ?? 0),
                'sms_leads'             => (int) ($row['smsLeads'] ?? 0),
                'request_details_leads' => (int) ($row['requestDetailsLeads'] ?? 0),
                'total_leads'           => (int) ($row['totalLeads'] ?? 0),
                'total_contact_leads'   => (int) ($row['totalContactLeads'] ?? 0),
                'price'                 => isset($row['price']) ? (float) $row['price'] : null,
                'synced_at'             => now(),
            ]
        );

        return true;
    }
}
