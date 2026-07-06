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
    /**
     * P24's production statistics endpoint reliably answers narrow date ranges in
     * ~1-2s but intermittently fails the SSL/connection handshake on wide ranges
     * (observed: ≤14 days fast; 30/60-day single calls time out). We therefore
     * split every requested lookback into ≤14-day windows and page through them.
     */
    private const MAX_WINDOW_DAYS = 14;

    /** Default pace between P24 calls to avoid tripping connection throttling (µs). */
    private const INTER_CALL_PAUSE_US = 250000;

    /** One connection retry per chunk (P24 handshake is intermittently flaky). */
    private const CHUNK_RETRIES = 1;

    /** Live inter-call pause; raise it for a gentler bulk backfill (see setPacing). */
    private int $interCallPauseUs = self::INTER_CALL_PAUSE_US;

    public function __construct(
        private readonly Property24ApiClient $api,
    ) {
    }

    /**
     * Override the inter-call pause (milliseconds) for a gentler bulk backfill —
     * the nightly job keeps the fast default; long historical sweeps space calls
     * out so P24 is never hammered. Returns $this for fluent use.
     */
    public function setPacing(int $milliseconds): self
    {
        $this->interCallPauseUs = max(0, $milliseconds) * 1000;
        return $this;
    }

    /**
     * Pull statistics for every agency that has P24 credentials configured.
     * Returns counts per agency for the calling job to log.
     */
    public function pullForAllAgencies(int $lookbackDays = 10): array
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
    public function pullForAgency(?Agency $agency, int $lookbackDays = 10): array
    {
        $api = $agency ? new Property24ApiClient($agency) : $this->api;

        // endDate is EXCLUSIVE and P24 publishes next-day, so [today - lookback, today)
        // captures the last $lookbackDays of finalised daily stats (through yesterday).
        // Split into ≤14-day windows — see MAX_WINDOW_DAYS.
        $chunks = $this->buildDateChunks($lookbackDays);

        // Only currently-active syndications get polled — a sold/withdrawn/errored
        // listing accrues no new views and must not burn a P24 call each night.
        $query = Property::withoutGlobalScopes()
            ->whereNotNull('p24_ref')
            ->where('p24_ref', '!=', '')
            ->whereRaw('LOWER(p24_syndication_status) = ?', ['active']);

        if ($agency) {
            $query->where('agency_id', $agency->id);
        }

        $listings = 0;
        $upserted = 0;
        $skipped  = 0;
        $errors   = 0;

        $query->chunkById(200, function ($properties) use ($api, $chunks, &$listings, &$upserted, &$skipped, &$errors) {
            foreach ($properties as $property) {
                $listingNumber = $this->resolveListingNumber($property);
                if ($listingNumber === null) {
                    $skipped++;
                    continue;
                }

                $listings++;

                foreach ($chunks as [$startDate, $endDate]) {
                    $response = $this->fetchChunk($api, $listingNumber, $startDate, $endDate, $property->id);

                    if (! ($response['success'] ?? false)) {
                        $errors++;
                        Log::channel('property24')->warning('P24 stats pull failed for listing', [
                            'property_id'    => $property->id,
                            'listing_number' => $listingNumber,
                            'window'         => "{$startDate}..{$endDate}",
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
            }
        });

        return compact('listings', 'upserted', 'skipped', 'errors');
    }

    /**
     * Pull statistics for a single property (targeted refresh / seed). Returns the
     * same counts shape as pullForAgency. Uses the property's agency credentials.
     */
    public function pullForProperty(Property $property, int $lookbackDays = 30): array
    {
        $listingNumber = $this->resolveListingNumber($property);
        if ($listingNumber === null) {
            return ['listings' => 0, 'upserted' => 0, 'skipped' => 1, 'errors' => 0];
        }

        $agency = $property->agency_id ? Agency::find($property->agency_id) : null;
        $api = $agency ? new Property24ApiClient($agency) : $this->api;

        $upserted = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($this->buildDateChunks($lookbackDays) as [$startDate, $endDate]) {
            $response = $this->fetchChunk($api, $listingNumber, $startDate, $endDate, $property->id);
            if (! ($response['success'] ?? false)) {
                $errors++;
                Log::channel('property24')->warning('P24 stats pull failed for listing', [
                    'property_id'    => $property->id,
                    'listing_number' => $listingNumber,
                    'window'         => "{$startDate}..{$endDate}",
                    'message'        => $response['message'] ?? null,
                ]);
                continue;
            }
            foreach ($this->extractRows($response['data'] ?? []) as $row) {
                $this->upsertRow($property, $listingNumber, $row) ? $upserted++ : $skipped++;
            }
        }

        return ['listings' => 1, 'upserted' => $upserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Split a lookback window into consecutive ≤MAX_WINDOW_DAYS ranges, oldest
     * first, each expressed as [startDate, endDate] Y-m-d with endDate EXCLUSIVE.
     * e.g. lookback 30 → [[t-30,t-16],[t-16,t-2],[t-2,t]].
     */
    private function buildDateChunks(int $lookbackDays): array
    {
        $lookbackDays = max(1, $lookbackDays);
        $chunks = [];
        $cursor = now()->startOfDay()->subDays($lookbackDays);
        $end    = now()->startOfDay();

        while ($cursor < $end) {
            $chunkEnd = (clone $cursor)->addDays(self::MAX_WINDOW_DAYS);
            if ($chunkEnd > $end) {
                $chunkEnd = clone $end;
            }
            $chunks[] = [$cursor->format('Y-m-d'), $chunkEnd->format('Y-m-d')];
            $cursor = clone $chunkEnd;
        }

        return $chunks;
    }

    /**
     * Fetch one date chunk with pacing and a single connection retry — P24's
     * production statistics host intermittently drops the SSL handshake.
     */
    private function fetchChunk(
        Property24ApiClient $api,
        int $listingNumber,
        string $startDate,
        string $endDate,
        int $propertyId
    ): array {
        $response = ['success' => false];

        for ($attempt = 0; $attempt <= self::CHUNK_RETRIES; $attempt++) {
            usleep($this->interCallPauseUs);
            $response = $api->getListingStatistics($listingNumber, $startDate, $endDate, $propertyId);
            if ($response['success'] ?? false) {
                break;
            }
        }

        return $response;
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
