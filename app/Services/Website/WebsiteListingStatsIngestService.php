<?php

namespace App\Services\Website;

use App\Models\AgencyApiKey;
use App\Models\Property;
use App\Models\WebsiteStatBatch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingests a batch of website engagement counters
 * (POST /api/v1/website/listings/stats) into the daily series CoreX renders on
 * the Intelligence tab, the listings index and the Today dashboard.
 *
 * The four behaviours this class exists to guarantee — each one is a bug the
 * website would otherwise inherit, not a nicety:
 *
 *  1. IDEMPOTENT. The batch row goes in FIRST, guarded by the database unique
 *     (agency_id, site, batch_id). A replay loses the insert race and is
 *     answered from the stored row — the counts are never applied twice. The
 *     website retries every non-2xx, so a reply lost in transit is normal
 *     traffic, not an edge case.
 *
 *  2. A STALE LISTING NEVER FAILS THE BATCH. A listing deleted in CoreX after
 *     the website counted a view still arrives. That one entry is skipped and
 *     named in the response; everything else applies and the call is still 200.
 *     A 4xx here would wedge the website's entire queue behind one dead id —
 *     it retries the whole batch and never advances its watermark.
 *
 *  3. ALL-OR-NOTHING DURABILITY. Batch row + every increment commit inside one
 *     transaction. 2xx therefore means "durably stored", which is precisely
 *     what the website's watermark trusts. Being briefly unavailable costs
 *     nothing — the next delta is just larger.
 *
 *  4. INCREMENTS, NEVER ASSIGNMENTS. metric_count = metric_count + n, so two
 *     batches covering the same day sum instead of the second erasing the first.
 *
 * Spec: .ai/specs/website-listing-stats.md §3.4
 */
class WebsiteListingStatsIngestService
{
    /** Rows per INSERT … ON DUPLICATE KEY UPDATE statement. */
    private const CHUNK = 400;

    /**
     * A day more than this far ahead of "now" is a clock/timezone fault, not a
     * real count — two days absorbs any Africa/Johannesburg vs UTC skew.
     */
    private const MAX_FUTURE_DAYS = 2;

    /** Nothing older than this is plausibly an outstanding delta. */
    private const MAX_PAST_YEARS = 5;

    /**
     * @param  array<string,mixed>  $payload  the validated request body
     * @return array{batch_id:string, accepted:int, skipped:array<int,string>, replayed:bool}
     */
    public function ingest(AgencyApiKey $key, array $payload): array
    {
        $agencyId    = (int) $key->agency_id;
        $site        = $this->cleanString($payload['site'] ?? null, 64) ?? 'website';
        $batchId     = $this->cleanString($payload['batch_id'] ?? null, 64) ?? '';
        $source      = $this->cleanString($payload['source'] ?? null, 32) ?? 'website';
        $generatedAt = $this->parseTimestamp($payload['generated_at'] ?? null);
        $listings    = array_values(array_filter((array) ($payload['listings'] ?? []), 'is_array'));

        // Fast path: a replay we have already seen. The unique index below is
        // still what makes this correct under concurrency — this only saves the
        // work of resolving and building rows we would then throw away.
        $existing = $this->findBatch($agencyId, $site, $batchId);
        if ($existing) {
            return $this->replayResponse($existing);
        }

        [$resolved, $skipped] = $this->resolveListings($agencyId, $listings);

        // (property_id|date|metric) => count. Aggregated in PHP first so a
        // payload that repeats a day for one listing sums rather than issuing
        // two statements that race each other.
        $increments = [];
        $totals     = [];

        foreach ($listings as $i => $entry) {
            $propertyId = $resolved[$i] ?? null;
            if ($propertyId === null) {
                continue;
            }

            foreach ($this->dayDeltas($entry, $generatedAt) as $date => $metrics) {
                foreach ($metrics as $metric => $count) {
                    $increments["{$propertyId}|{$date}|{$metric}"] = ($increments["{$propertyId}|{$date}|{$metric}"] ?? 0) + $count;
                }
            }

            // Lifetime totals are ASSIGNED, never added — they are what the
            // website believes, kept beside our own sum so drift is visible.
            foreach ($this->normaliseMetricMap($entry['totals'] ?? null) as $metric => $count) {
                $totals["{$propertyId}|{$metric}"] = $count;
            }
        }

        $receivedAt = Carbon::now();

        try {
            $batch = DB::transaction(function () use (
                $agencyId, $key, $site, $batchId, $source, $generatedAt,
                $receivedAt, $listings, $resolved, $skipped, $increments, $totals
            ) {
                $batch = new WebsiteStatBatch([
                    'agency_id'           => $agencyId,
                    'agency_api_key_id'   => $key->id,
                    'site'                => $site,
                    'batch_id'            => $batchId,
                    'source'              => $source,
                    'listing_count'       => count($listings),
                    'accepted_count'      => count($resolved),
                    'skipped_count'       => count($skipped),
                    'skipped_listing_ids' => array_values($skipped),
                    'metric_row_count'    => count($increments),
                    'generated_at'        => $generatedAt,
                    'received_at'         => $receivedAt,
                ]);
                $batch->agency_id = $agencyId;
                $batch->save();

                $this->applyIncrements($agencyId, $site, $increments, $receivedAt);
                $this->applyTotals($agencyId, $site, $totals, $receivedAt);

                return $batch;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Lost the race with a concurrent retry of the SAME batch. The other
            // request stored it; answer from that row rather than double-applying.
            $existing = $this->findBatch($agencyId, $site, $batchId);
            if ($existing) {
                return $this->replayResponse($existing);
            }
            throw $e;
        }

        if ($skipped) {
            // Not an error — a listing removed in CoreX after the site counted a
            // view is expected. Logged so a site pointed at the WRONG agency
            // (every id skipped) is diagnosable instead of silently dropping.
            Log::info('Website stats batch skipped unknown listings', [
                'agency_id' => $agencyId,
                'site'      => $site,
                'batch_id'  => $batchId,
                'skipped'   => array_values($skipped),
                'accepted'  => count($resolved),
            ]);
        }

        return [
            'batch_id' => $batchId,
            'accepted' => count($resolved),
            'skipped'  => array_values($skipped),
            'replayed' => false,
        ];
    }

    // ── Listing resolution ──────────────────────────────────────────────────

    /**
     * Resolve every entry to a CoreX property id, agency-scoped.
     *
     * listing_id first (the website got it from GET /website/listings), then
     * `reference` → properties.external_id as a fallback — the same order and
     * the same columns WebsiteLeadService uses, so a listing that anchors a lead
     * anchors its stats too.
     *
     * Soft-deleted properties still resolve: the row exists, the history is real,
     * and discarding it would lose counts that a later restore should still show.
     *
     * @param  array<int,array<string,mixed>>  $listings
     * @return array{0:array<int,int>, 1:array<int,string>}  [index => property_id, index => raw listing id]
     */
    private function resolveListings(int $agencyId, array $listings): array
    {
        $ids  = [];
        $refs = [];
        foreach ($listings as $entry) {
            // "as a string. Cast it — do not assume int."
            $rawId = $entry['listing_id'] ?? null;
            if (is_scalar($rawId) && ctype_digit(ltrim((string) $rawId)) && (int) $rawId > 0) {
                $ids[] = (int) $rawId;
            }
            $ref = $this->cleanString($entry['reference'] ?? null, 64);
            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        $known = [];
        if ($ids) {
            $known = Property::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereIn('id', array_unique($ids))
                ->pluck('id')
                ->all();
            $known = array_flip(array_map('intval', $known));
        }

        $byRef = [];
        if ($refs) {
            $byRef = Property::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereIn('external_id', array_unique($refs))
                ->pluck('id', 'external_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $resolved = [];
        $skipped  = [];

        foreach ($listings as $i => $entry) {
            $rawId = $entry['listing_id'] ?? null;
            $intId = (is_scalar($rawId) && ctype_digit(ltrim((string) $rawId))) ? (int) $rawId : 0;

            if ($intId > 0 && isset($known[$intId])) {
                $resolved[$i] = $intId;
                continue;
            }

            $ref = $this->cleanString($entry['reference'] ?? null, 64);
            if ($ref !== null && isset($byRef[$ref])) {
                $resolved[$i] = $byRef[$ref];
                continue;
            }

            $skipped[$i] = is_scalar($rawId) ? (string) $rawId : ($ref ?? '');
        }

        return [$resolved, $skipped];
    }

    // ── Payload → increments ────────────────────────────────────────────────

    /**
     * The per-day deltas to append for one listing entry.
     *
     * `days[]` is authoritative — it is the time series, and the whole point of
     * storing daily granularity. `delta` is used ONLY when an entry carries no
     * usable days[], and is then attributed to the batch's generated_at date;
     * by the website's own construction delta == sum(days), so consuming both
     * would double every count.
     *
     * @return array<string,array<string,int>>  date => metric => count
     */
    private function dayDeltas(array $entry, Carbon $generatedAt): array
    {
        $out = [];

        foreach ((array) ($entry['days'] ?? []) as $day) {
            if (! is_array($day)) {
                continue;
            }
            $date = $this->normaliseDate($day['date'] ?? null);
            if ($date === null) {
                continue;
            }
            foreach ($this->normaliseMetricMap($day['metrics'] ?? null) as $metric => $count) {
                $out[$date][$metric] = ($out[$date][$metric] ?? 0) + $count;
            }
        }

        if ($out) {
            return $out;
        }

        $delta = $this->normaliseMetricMap($entry['delta'] ?? null);
        if ($delta) {
            // Africa/Johannesburg — the timezone the website's own day boundaries
            // use, so a fallback delta lands on the same day its days[] would have.
            $date = $generatedAt->copy()->setTimezone(config('app.timezone'))->format('Y-m-d');
            $date = $this->normaliseDate($date) ?? Carbon::now()->format('Y-m-d');
            $out[$date] = $delta;
        }

        return $out;
    }

    /**
     * Normalise a { metric: count } map.
     *
     * The metric key set is OPEN by contract — a new metric must not need a
     * CoreX deploy — so this is a storage-safety floor, not a whitelist:
     * anything that fits the column and can't confuse SQL is kept. A key that
     * fails is dropped on its own; the listing and the batch still succeed.
     *
     * @return array<string,int>
     */
    private function normaliseMetricMap($map): array
    {
        if (! is_array($map)) {
            return [];
        }

        $out = [];
        foreach ($map as $key => $value) {
            $metric = $this->normaliseMetric($key);
            if ($metric === null) {
                continue;
            }
            $count = $this->normaliseCount($value);
            if ($count <= 0) {
                continue;
            }
            $out[$metric] = ($out[$metric] ?? 0) + $count;
        }

        return $out;
    }

    private function normaliseMetric($key): ?string
    {
        if (! is_string($key) && ! is_int($key)) {
            return null;
        }
        $key = strtolower(trim((string) $key));

        return preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1 ? $key : null;
    }

    private function normaliseCount($value): int
    {
        if (is_bool($value) || ! is_numeric($value)) {
            return 0;
        }
        $n = (int) $value;

        return $n > 0 ? $n : 0;
    }

    /** Strict Y-m-d, inside a plausible window. Anything else is dropped. */
    private function normaliseDate($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
        if ($date->format('Y-m-d') !== $value) {
            return null;   // 2026-02-31 and friends
        }
        if ($date->greaterThan(Carbon::now()->addDays(self::MAX_FUTURE_DAYS))) {
            return null;
        }
        if ($date->lessThan(Carbon::now()->subYears(self::MAX_PAST_YEARS))) {
            return null;
        }

        return $value;
    }

    // ── Writes ──────────────────────────────────────────────────────────────

    /**
     * INSERT … ON DUPLICATE KEY UPDATE against the natural key. The += is the
     * whole contract: assignment would erase the earlier batch's counts for the
     * same day. Chunked so a 200-listing batch is a handful of statements, not
     * thousands of round trips.
     *
     * @param  array<string,int>  $increments  "propertyId|date|metric" => count
     */
    private function applyIncrements(int $agencyId, string $site, array $increments, Carbon $now): void
    {
        if (! $increments) {
            return;
        }

        $stamp = $now->toDateTimeString();

        foreach (array_chunk($increments, self::CHUNK, true) as $chunk) {
            $bindings = [];
            foreach ($chunk as $composite => $count) {
                [$propertyId, $date, $metric] = explode('|', $composite, 3);
                array_push($bindings, $agencyId, $site, (int) $propertyId, $date, $metric, $count, $stamp, $stamp);
            }

            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?)'));

            DB::statement(
                'INSERT INTO listing_website_stats'
                . ' (agency_id, site, property_id, stat_date, metric, metric_count, created_at, updated_at)'
                . " VALUES {$placeholders}"
                . ' ON DUPLICATE KEY UPDATE metric_count = metric_count + VALUES(metric_count), updated_at = VALUES(updated_at)',
                $bindings
            );
        }
    }

    /** @param array<string,int> $totals "propertyId|metric" => lifetime total */
    private function applyTotals(int $agencyId, string $site, array $totals, Carbon $now): void
    {
        if (! $totals) {
            return;
        }

        $rows = [];
        foreach ($totals as $composite => $total) {
            [$propertyId, $metric] = explode('|', $composite, 2);
            $rows[] = [
                'agency_id'      => $agencyId,
                'site'           => $site,
                'property_id'    => (int) $propertyId,
                'metric'         => $metric,
                'reported_total' => $total,
                'reported_at'    => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('listing_website_stat_totals')->upsert(
                $chunk,
                ['agency_id', 'site', 'property_id', 'metric'],
                ['reported_total', 'reported_at', 'updated_at']
            );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function findBatch(int $agencyId, string $site, string $batchId): ?WebsiteStatBatch
    {
        if ($batchId === '') {
            return null;
        }

        return WebsiteStatBatch::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('site', $site)
            ->where('batch_id', $batchId)
            ->first();
    }

    /** @return array{batch_id:string, accepted:int, skipped:array<int,string>, replayed:bool} */
    private function replayResponse(WebsiteStatBatch $batch): array
    {
        return [
            'batch_id' => (string) $batch->batch_id,
            'accepted' => (int) $batch->accepted_count,
            'skipped'  => array_values((array) ($batch->skipped_listing_ids ?? [])),
            'replayed' => true,
        ];
    }

    private function cleanString($value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function parseTimestamp($value): Carbon
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                // fall through — a malformed timestamp is not worth failing a
                // batch of real counts over.
            }
        }

        return Carbon::now();
    }
}
