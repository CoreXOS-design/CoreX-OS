<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Services\Importer\P24ListingNumberReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Owner-only repair tool: upload the original Property24 CSV export and
 * backfill the correct listing number (p24_ref) onto matching CoreX
 * properties, so future pushes UPDATE the original P24 listing instead of
 * creating duplicates.
 *
 * Two endpoints drive a chunked, progress-bar UI:
 *   upload()  — parse the CSV, stash the rows in cache, return a token + total
 *   process() — match + apply one chunk, return running counts
 */
class P24ListingNumberFixController extends Controller
{
    public function __construct(private P24ListingNumberReconciler $reconciler)
    {
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:51200', // 50MB
        ]);

        $rows = $this->reconciler->parse($request->file('file')->getRealPath());
        $rows = array_values(array_filter($rows, fn ($r) => $r['ln'] !== null && $r['ln'] !== ''));

        if (empty($rows)) {
            return response()->json(['ok' => false, 'message' => 'No rows with a ListingNumber were found in that file.'], 422);
        }

        $token = (string) Str::uuid();
        $ttl = now()->addHour();
        Cache::put($this->rowsKey($token), $rows, $ttl);
        Cache::put($this->statsKey($token), $this->emptyStats(), $ttl);

        return response()->json(['ok' => true, 'token' => $token, 'total' => count($rows)]);
    }

    public function process(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'  => 'required|string',
            'offset' => 'required|integer|min:0',
            'limit'  => 'nullable|integer|min:1|max:100',
        ]);

        $rows = Cache::get($this->rowsKey($data['token']));
        if ($rows === null) {
            return response()->json(['ok' => false, 'message' => 'Upload session expired — please choose the file and run again.'], 410);
        }
        $stats = Cache::get($this->statsKey($data['token'])) ?? $this->emptyStats();

        $offset = (int) $data['offset'];
        $limit  = (int) ($data['limit'] ?? 25);
        $total  = count($rows);

        foreach (array_slice($rows, $offset, $limit) as $row) {
            $res = $this->reconciler->reconcileRow($row);
            $bucket = in_array($res['status'], ['applied', 'conflict', 'skipped', 'unmatched', 'invalid'], true)
                ? $res['status']
                : 'skipped';
            $stats[$bucket]++;

            // Keep a capped tail of everything that did NOT cleanly apply, so the
            // operator gets an actionable report at the end.
            if (in_array($res['status'], ['conflict', 'unmatched', 'skipped', 'invalid'], true)) {
                $stats['log'][] = [
                    'ln'          => $res['listing_number'] ?? null,
                    'status'      => $res['status'],
                    'reason'      => $res['reason'] ?? '',
                    'property_id' => $res['property_id'] ?? null,
                ];
                if (count($stats['log']) > 300) {
                    array_shift($stats['log']);
                }
            }
        }

        $processed = min($offset + $limit, $total);
        $done = $processed >= $total;
        Cache::put($this->statsKey($data['token']), $stats, now()->addHour());

        return response()->json([
            'ok'        => true,
            'processed' => $processed,
            'total'     => $total,
            'done'      => $done,
            'stats'     => [
                'applied'   => $stats['applied'],
                'conflict'  => $stats['conflict'],
                'skipped'   => $stats['skipped'],
                'unmatched' => $stats['unmatched'],
                'invalid'   => $stats['invalid'],
            ],
            'log' => $done ? $stats['log'] : [],
        ]);
    }

    private function emptyStats(): array
    {
        return ['applied' => 0, 'conflict' => 0, 'skipped' => 0, 'unmatched' => 0, 'invalid' => 0, 'log' => []];
    }

    private function rowsKey(string $token): string
    {
        return 'p24fix:rows:' . $token;
    }

    private function statsKey(string $token): string
    {
        return 'p24fix:stats:' . $token;
    }
}
