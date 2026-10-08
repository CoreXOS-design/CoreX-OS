<?php

namespace App\Http\Middleware;

use App\Models\ClientUser;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/rental-portal-access.md §22 — one press of a portal button does its work ONCE.
 *
 * Every state-changing request (POST/PUT/PATCH/DELETE) in the tenant / owner portal passes through here. The page sends a
 * key per form (`X-Submission-Key` header or `submission_key` field); the first request with a key does the work and the
 * answer it gave is stored in `portal_submissions`; a second request with the same key — a double tap, a retry on a slow
 * phone, the browser resending — gets that stored answer back (header `X-Portal-Replay: 1`) and NOTHING is done again.
 * A request that arrives while the first is still running waits for it (up to ~15 s) instead of racing it.
 *
 * A client that sends no key (an older page, the mobile app) is still guarded: the key is then a fingerprint of the
 * request content, and the same content from the same person within AUTO_WINDOW_SECONDS is the same press.
 *
 * Only a successful (2xx) answer is kept as "done"; a refused or failed attempt is marked `failed` and the same key may try
 * again — a validation error must never lock someone out of their own form. Rows are never deleted.
 */
class EnsurePortalSubmissionOnce
{
    /** The same content, from the same person, to the same place, within this window is one press (keyless clients only). */
    public const AUTO_WINDOW_SECONDS = 20;
    /** A "processing" row older than this is a crashed attempt and may be taken over. */
    private const STALE_PROCESSING_SECONDS = 60;
    private const WAIT_STEP_MICROSECONDS = 200000;
    private const WAIT_STEPS = 75;
    private const MAX_STORED_BODY = 200000;

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $client = $request->user();
        if (!$client instanceof ClientUser) {
            return $next($request);
        }

        [$key, $auto] = $this->keyFor($request);
        $hash = sha1($request->method() . ' ' . $request->path());

        $claim = $this->claim($client, $hash, $key, $auto);
        if ($claim instanceof Response) {
            return $claim;
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->finish($claim, 'failed', null);
            throw $e;
        }

        $this->finish($claim, $response->isSuccessful() ? 'done' : 'failed', $response);

        return $response;
    }

    /** @return array{0:string,1:bool} [key, isAutoFingerprint] */
    private function keyFor(Request $request): array
    {
        $given = $request->header('X-Submission-Key') ?? $request->input('submission_key');
        if (is_string($given) && preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $given)) {
            return [$given, false];
        }

        $files = [];
        foreach ($request->allFiles() as $name => $set) {
            foreach ((array) $set as $f) {
                $files[] = $name . ':' . ($f?->getClientOriginalName() ?? '') . ':' . ($f?->getSize() ?? 0);
            }
        }
        sort($files);

        return ['auto:' . sha1(json_encode([$request->except(['submission_key', '_token']), $files])), true];
    }

    /** @return int|Response the claimed row id, or a replay / wait-timeout response */
    private function claim(ClientUser $client, string $hash, string $key, bool $auto): int|Response
    {
        $where = ['client_user_id' => $client->id, 'request_hash' => $hash, 'submission_key' => $key];

        for ($i = 0; $i < self::WAIT_STEPS; $i++) {
            try {
                return (int) DB::table('portal_submissions')->insertGetId($where + [
                    'agency_id' => $client->current_agency_id,
                    'status' => 'processing',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }
            }

            $row = DB::table('portal_submissions')->where($where)->first();
            if (!$row) {
                continue; // vanished between the insert and the read — try to claim again
            }

            $age = now()->diffInSeconds(\Carbon\Carbon::parse($row->updated_at), true);

            if ($row->status === 'done') {
                if (!$auto || $age <= self::AUTO_WINDOW_SECONDS) {
                    return $this->replay($row);
                }
                // an old keyless fingerprint: the same content again, long enough later, is a new press
                if ($this->takeOver($row)) {
                    return (int) $row->id;
                }
                continue;
            }

            if ($row->status === 'failed' || ($row->status === 'processing' && $age > self::STALE_PROCESSING_SECONDS)) {
                if ($this->takeOver($row)) {
                    return (int) $row->id;
                }
                continue;
            }

            usleep(self::WAIT_STEP_MICROSECONDS); // the first press is still running — wait for its answer
        }

        return response()->json(['message' => 'That is still being sent — please wait a moment before trying again.'], 409);
    }

    private function takeOver(object $row): bool
    {
        return DB::table('portal_submissions')
            ->where('id', $row->id)
            ->where('status', $row->status)
            ->where('updated_at', $row->updated_at)
            ->update(['status' => 'processing', 'response_status' => null, 'response_body' => null, 'response_type' => null, 'updated_at' => now()]) === 1;
    }

    private function replay(object $row): Response
    {
        return response((string) $row->response_body, (int) ($row->response_status ?: 200), [
            'Content-Type' => $row->response_type ?: 'application/json',
            'X-Portal-Replay' => '1',
        ]);
    }

    private function finish(int $id, string $status, ?Response $response): void
    {
        $body = null;
        if ($status === 'done' && $response !== null) {
            $content = (string) $response->getContent();
            $body = strlen($content) <= self::MAX_STORED_BODY ? $content : null;
            // A success whose answer is too large to keep cannot be replayed faithfully — treat it as not-repeatable and let it run again.
            if ($body === null) {
                $status = 'failed';
            }
        }

        DB::table('portal_submissions')->where('id', $id)->update([
            'status' => $status,
            'response_status' => $response?->getStatusCode(),
            'response_body' => $body,
            'response_type' => $response?->headers->get('Content-Type'),
            'updated_at' => now(),
        ]);
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
