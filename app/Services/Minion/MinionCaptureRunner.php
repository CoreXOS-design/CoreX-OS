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
 * last_seen_at deactivation feed) — no parallel ingester. Node script fetches EVERY PUBLIC
 * results page for a suburb (pagination); this service POSTs each page to our own
 * /portal-captures/ingest with the extension's service token, exactly as the extension does.
 */
class MinionCaptureRunner
{
    /** Capture every ticked suburb in one town (city), paced. Returns the run rows. */
    public function captureTown(int $agencyId, string $townName, string $triggeredBy = 'manual', ?int $userId = null): array
    {
        return $this->captureMany($agencyId, $this->tickedSuburbIdsForTown($agencyId, $townName), $triggeredBy, $userId);
    }

    /** Nightly slice: the least-recently-captured N ticked suburbs (cadence is a setting). */
    public function captureCycle(int $agencyId, string $triggeredBy = 'schedule'): array
    {
        $s   = MinionCaptureSettings::resolved($agencyId);
        $ids = MinionCaptureArea::where('agency_id', $agencyId)
            ->orderByRaw('last_captured_at IS NULL DESC')
            ->orderBy('last_captured_at')
            ->limit((int) $s['targets_per_night'])
            ->pluck('p24_suburb_id')->all();

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
                sleep(random_int((int) $s['pace_min_seconds'], max((int) $s['pace_min_seconds'], (int) $s['pace_max_seconds'])));
            }
        }
        return $runs;
    }

    /** Capture ALL results pages of one suburb (paginated) and ingest each through the existing pipeline. */
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
            'pages_attempted'      => 0,
        ]);

        if (! $sub) {
            return $this->fail($run, $started, 'p24 suburb not found: ' . $p24SuburbId);
        }

        $baseUrl = P24SearchUrlBuilder::forSale(
            (string) config('minion_capture.p24_base'),
            (string) ($sub->province_name ?? ''), (string) ($sub->city_name ?? ''),
            (string) ($sub->slug ?: $sub->suburb_name), (int) $sub->p24_id
        );

        $outDir = storage_path('app/minion/' . $run->id);
        @mkdir($outDir, 0775, true);

        $proc = Process::path(base_path())->timeout(3600)->run([
            (string) config('minion_capture.node_binary'),
            base_path((string) config('minion_capture.node_script')),
            json_encode([
                'baseUrl'      => $baseUrl,
                'outDir'       => $outDir,
                'navTimeoutMs' => (int) config('minion_capture.nav_timeout_ms'),
                'chromiumPath' => (string) config('minion_capture.chromium_path'),
                'maxPages'     => (int) config('minion_capture.max_pages'),
                'paceMinMs'    => (int) config('minion_capture.pace_page_min_ms'),
                'paceMaxMs'    => (int) config('minion_capture.pace_page_max_ms'),
            ]),
        ]);

        $meta = json_decode(trim($proc->output()), true) ?: [];
        if (! $proc->successful() || ! ($meta['ok'] ?? false)) {
            if ($meta['blocked'] ?? false) {
                return $this->fail($run, $started, 'P24 served a block/challenge — stopped (no bypass). ' . ($meta['error'] ?? ''));
            }
            return $this->fail($run, $started, 'node fetch failed: ' . ($meta['error'] ?? ($proc->errorOutput() ?: 'unknown')));
        }

        $ingestUrl   = (string) config('minion_capture.ingest_url');
        $ingestToken = (string) config('minion_capture.ingest_token');
        if ($ingestUrl === '' || $ingestToken === '') {
            return $this->fail($run, $started, 'MINION_INGEST_URL / MINION_INGEST_TOKEN not configured (.env)');
        }

        $cap = 0; $new = 0; $upd = 0; $pagesOk = 0; $fails = [];
        foreach (($meta['results'] ?? []) as $r) {
            if (! empty($r['blocked'])) { $fails[] = 'blocked p' . ($r['page'] ?? '?'); continue; }
            $file = $r['file'] ?? null;
            if (! $file || ! is_file($file)) { $fails[] = 'missing page file p' . ($r['page'] ?? '?'); continue; }
            $payload = json_decode((string) @file_get_contents($file), true) ?: [];
            @unlink($file);
            try {
                $resp = Http::withToken($ingestToken)->acceptJson()->timeout(60)->post($ingestUrl, [
                    'source_site'       => (string) config('minion_capture.source_site'),
                    'page_type'         => 'search',
                    'source_url'        => $payload['source_url'] ?? $baseUrl,
                    'final_url'         => $payload['final_url'] ?? ($r['finalUrl'] ?? $baseUrl),
                    'page_title'        => $payload['page_title'] ?? ($r['title'] ?? null),
                    'captured_at'       => now()->toIso8601String(),
                    'extractor_version' => 'minion-v1',
                    'parse_status'      => 'parsed',
                    'html'              => $payload['html'] ?? '',
                ]);
            } catch (\Throwable $e) { $fails[] = 'ingest p' . ($r['page'] ?? '?') . ' err: ' . $e->getMessage(); continue; }
            if (! $resp->successful()) { $fails[] = 'ingest p' . ($r['page'] ?? '?') . ' HTTP ' . $resp->status(); continue; }
            $b = $resp->json(); $t = $b['tracking'] ?? [];
            $cap += (int) ($b['extraction']['items_on_page'] ?? ($t['processed'] ?? 0));
            $new += (int) ($t['new'] ?? 0); $upd += (int) ($t['updated'] ?? 0); $pagesOk++;
        }
        @rmdir($outDir);

        $status = $pagesOk > 0 ? ($fails ? 'partial' : 'ok') : 'failed';
        $run->update([
            'finished_at'      => now(), 'status' => $status,
            'captured'         => $cap, 'listings_new' => $new, 'listings_updated' => $upd,
            'pages_attempted'  => (int) ($meta['pagesFetched'] ?? $pagesOk),
            'failures'         => count($fails), 'failures_json' => $fails ?: null,
            'duration_ms'      => (int) $started->diffInMilliseconds(now()),
        ]);

        MinionCaptureArea::where('agency_id', $agencyId)->where('p24_suburb_id', $p24SuburbId)->update(['last_captured_at' => now()]);
        return $run->refresh();
    }

    private function fail(MinionCaptureRun $run, \Illuminate\Support\Carbon $started, string $msg): MinionCaptureRun
    {
        Log::warning('AT-284 minion capture failed', ['run' => $run->id, 'msg' => $msg]);
        $run->update([
            'finished_at' => now(), 'status' => 'failed', 'failures' => 1,
            'failures_json' => [$msg], 'duration_ms' => (int) $started->diffInMilliseconds(now()),
        ]);
        return $run->refresh();
    }

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
            ->whereNull('a.deleted_at')->where('a.agency_id', $agencyId)->where('c.name', $townName)
            ->pluck('a.p24_suburb_id')->map(fn ($v) => (int) $v)->all();
    }
}
