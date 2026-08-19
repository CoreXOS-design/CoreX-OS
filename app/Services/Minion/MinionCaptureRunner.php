<?php

namespace App\Services\Minion;

use App\Models\MinionCaptureArea;
use App\Models\MinionCaptureRun;
use App\Models\MinionCaptureSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * AT-284 — orchestrates one nightly/manual P24 capture.
 * Reuses the EXISTING portal-capture ingest (dedup + cross-portal match + agency stamp +
 * last_seen_at deactivation feed) — no parallel ingester. Node script only fetches the
 * PUBLIC search page; this service POSTs it to our own /portal-captures/ingest with the
 * extension's service token, exactly as the extension does.
 */
class MinionCaptureRunner
{
    /** Capture every ticked suburb in one town (city), paced. Returns the run rows. */
    public function captureTown(int $agencyId, string $townName, string $triggeredBy = 'manual', ?int $userId = null): array
    {
        $ids = $this->tickedSuburbIdsForTown($agencyId, $townName);
        return $this->captureMany($agencyId, $ids, $triggeredBy, $userId);
    }

    /** Nightly slice: the least-recently-captured N ticked suburbs (cadence is a setting). */
    public function captureCycle(int $agencyId, string $triggeredBy = 'schedule'): array
    {
        $s   = MinionCaptureSettings::resolved($agencyId);
        $ids = MinionCaptureArea::where('agency_id', $agencyId)
            ->orderByRaw('last_captured_at IS NULL DESC')
            ->orderBy('last_captured_at')
            ->limit((int) $s['targets_per_night'])
            ->pluck('p24_suburb_id')
            ->all();

        return $this->captureMany($agencyId, $ids, $triggeredBy, null);
    }

    /** @param int[] $p24SuburbIds */
    public function captureMany(int $agencyId, array $p24SuburbIds, string $triggeredBy, ?int $userId): array
    {
        $s    = MinionCaptureSettings::resolved($agencyId);
        $runs = [];
        $last = count($p24SuburbIds) - 1;

        foreach (array_values($p24SuburbIds) as $i => $suburbId) {
            $runs[] = $this->captureSuburb($agencyId, (int) $suburbId, $triggeredBy, $userId);
            if ($i < $last) {
                // Polite pacing between page loads (politeness, not evasion).
                $gap = random_int((int) $s['pace_min_seconds'], max((int) $s['pace_min_seconds'], (int) $s['pace_max_seconds']));
                sleep($gap);
            }
        }

        return $runs;
    }

    public function captureSuburb(int $agencyId, int $p24SuburbId, string $triggeredBy = 'manual', ?int $userId = null): MinionCaptureRun
    {
        $started = now();
        $sub     = $this->suburbGeo($p24SuburbId);

        $run = MinionCaptureRun::create([
            'agency_id'            => $agencyId,
            'p24_suburb_id'        => $p24SuburbId,
            'area_label'           => $sub ? trim(($sub->suburb_name ?? '') . ' (' . ($sub->region ?? $sub->city_name ?? '') . ')') : ('suburb#' . $p24SuburbId),
            'started_at'           => $started,
            'status'               => 'running',
            'triggered_by'         => $triggeredBy,
            'triggered_by_user_id' => $userId,
            'pages_attempted'      => 1,
        ]);

        if (! $sub) {
            return $this->fail($run, $started, 'p24 suburb not found: ' . $p24SuburbId);
        }

        $url = P24SearchUrlBuilder::forSale(
            (string) config('minion_capture.p24_base'),
            (string) ($sub->province_name ?? ''),
            (string) ($sub->city_name ?? ''),
            (string) ($sub->slug ?: $sub->suburb_name),
            (int) $sub->p24_id
        );

        // --- Node fetch of the PUBLIC search page ---
        $outFile = storage_path('app/minion/' . $run->id . '.json');
        @mkdir(dirname($outFile), 0775, true);
        $node = [
            'url'          => $url,
            'outFile'      => $outFile,
            'navTimeoutMs' => (int) config('minion_capture.nav_timeout_ms'),
            'chromiumPath' => (string) config('minion_capture.chromium_path'),
        ];

        $proc = Process::path(base_path())
            ->timeout(120)
            ->run([
                (string) config('minion_capture.node_binary'),
                base_path((string) config('minion_capture.node_script')),
                json_encode($node),
            ]);

        $meta = json_decode(trim($proc->output()), true) ?: [];

        if (! $proc->successful() || ! ($meta['ok'] ?? false)) {
            if ($meta['blocked'] ?? false) {
                return $this->fail($run, $started, 'P24 served a block/challenge — stopped (no bypass). final=' . ($meta['finalUrl'] ?? $url));
            }
            return $this->fail($run, $started, 'node fetch failed: ' . ($meta['error'] ?? $proc->errorOutput() ?: 'unknown'));
        }

        // --- Route through the EXISTING ingest, exactly like the extension ---
        $payload = json_decode((string) @file_get_contents($outFile), true) ?: [];
        @unlink($outFile);

        $ingestUrl   = (string) config('minion_capture.ingest_url');
        $ingestToken = (string) config('minion_capture.ingest_token');
        if ($ingestUrl === '' || $ingestToken === '') {
            return $this->fail($run, $started, 'MINION_INGEST_URL / MINION_INGEST_TOKEN not configured (.env)');
        }

        try {
            $resp = Http::withToken($ingestToken)
                ->acceptJson()
                ->timeout(60)
                ->post($ingestUrl, [
                    'source_site'       => (string) config('minion_capture.source_site'),
                    'page_type'         => 'search',
                    'source_url'        => $payload['source_url'] ?? $url,
                    'final_url'         => $payload['final_url'] ?? ($meta['finalUrl'] ?? $url),
                    'page_title'        => $payload['page_title'] ?? ($meta['title'] ?? null),
                    'captured_at'       => now()->toIso8601String(),
                    'extractor_version' => 'minion-v1',
                    'parse_status'      => 'parsed',
                    'html'              => $payload['html'] ?? '',
                ]);
        } catch (\Throwable $e) {
            return $this->fail($run, $started, 'ingest POST error: ' . $e->getMessage());
        }

        if (! $resp->successful()) {
            return $this->fail($run, $started, 'ingest HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 300));
        }

        $body     = $resp->json();
        $tracking = $body['tracking'] ?? [];

        $run->update([
            'finished_at'      => now(),
            'status'           => 'ok',
            'captured'         => (int) ($body['extraction']['items_on_page'] ?? ($tracking['processed'] ?? 0)),
            'listings_new'     => (int) ($tracking['new'] ?? 0),
            'listings_updated' => (int) ($tracking['updated'] ?? 0),
            'duration_ms'      => (int) $started->diffInMilliseconds(now()),
        ]);

        MinionCaptureArea::where('agency_id', $agencyId)
            ->where('p24_suburb_id', $p24SuburbId)
            ->update(['last_captured_at' => now()]);

        return $run->refresh();
    }

    private function fail(MinionCaptureRun $run, \Illuminate\Support\Carbon $started, string $msg): MinionCaptureRun
    {
        Log::warning('AT-284 minion capture failed', ['run' => $run->id, 'msg' => $msg]);
        $run->update([
            'finished_at'   => now(),
            'status'        => 'failed',
            'failures'      => 1,
            'failures_json' => [$msg],
            'duration_ms'   => (int) $started->diffInMilliseconds(now()),
        ]);
        return $run->refresh();
    }

    /** p24 suburb joined to its city + province + agency town region (for URL + label). */
    private function suburbGeo(int $p24SuburbId): ?object
    {
        return DB::table('p24_suburbs as s')
            ->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')
            ->leftJoin('p24_provinces as p', 'p.id', '=', 'c.p24_province_id')
            ->where('s.id', $p24SuburbId)
            ->selectRaw('s.id, s.name as suburb_name, s.slug, s.p24_id, s.region, c.name as city_name, p.name as province_name')
            ->first();
    }

    /** @return int[] ticked suburb ids whose p24 city matches the town name for this agency. */
    private function tickedSuburbIdsForTown(int $agencyId, string $townName): array
    {
        return DB::table('minion_capture_areas as a')
            ->join('p24_suburbs as s', 's.id', '=', 'a.p24_suburb_id')
            ->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')
            ->whereNull('a.deleted_at')
            ->where('a.agency_id', $agencyId)
            ->where('c.name', $townName)
            ->pluck('a.p24_suburb_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
