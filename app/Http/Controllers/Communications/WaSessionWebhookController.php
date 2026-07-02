<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Scopes\AgencyScope;
use App\Services\Communications\WaArchiveIngestor;
use App\Services\Communications\WahaWebhookAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AT-149 — WAHA server-session webhook receiver. This is the last piece
 * connecting the 24/7 server-side WhatsApp session (AT-138/143) to the CoreX
 * Communication Archive.
 *
 * WAHA posts ONE message per webhook. This controller is thin: authenticate
 * (VerifyWahaWebhook middleware — HMAC/secret), attribute the WAHA session to a
 * CoreX device/agent, map the payload via WahaWebhookAdapter, and hand the
 * mapped item to the EXISTING WaArchiveIngestor. Ingestion, @lid resolution,
 * consent, matching, body, media download (AT-148) are all reused unchanged.
 *
 * ROBUSTNESS: anything that isn't a clean, attributable message is logged and
 * skipped with a 200 — never a 500 (a 500 makes WAHA retry-storm the same bad
 * payload). Auth failure is the ONLY non-200 (handled by the middleware).
 */
class WaSessionWebhookController extends Controller
{
    /** WAHA event types that carry a message we archive. */
    private const MESSAGE_EVENTS = ['message', 'message.any'];

    public function handle(Request $request, WahaWebhookAdapter $adapter, WaArchiveIngestor $ingestor): JsonResponse
    {
        $body = $request->json()->all();
        if (! is_array($body)) {
            return $this->skip('non-JSON body');
        }

        $event = strtolower((string) ($body['event'] ?? ''));
        if (! in_array($event, self::MESSAGE_EVENTS, true)) {
            // session.status, message.ack, presence, etc. — nothing to archive.
            return response()->json(['success' => true, 'ignored' => $event ?: 'unknown']);
        }

        $session = trim((string) ($body['session'] ?? ''));
        $payload = $body['payload'] ?? null;
        if ($session === '' || ! is_array($payload)) {
            return $this->skip('missing session or payload');
        }

        // Attribute the WAHA session to a CoreX device (→ agency + owning agent).
        // No auth user/agency context on a server webhook → bypass the AgencyScope,
        // exactly as the extension-capture middleware does.
        $device = CommunicationWaDevice::withoutGlobalScope(AgencyScope::class)
            ->forWahaSession($session)
            ->first();
        if (! $device) {
            return $this->skip("no CoreX device linked to WAHA session '{$session}'");
        }

        // Map ONE payload → the ingestor's messages[] item shape. Null = noise
        // (status@broadcast / group) or malformed — dropped, never archived.
        try {
            $item = $adapter->map($payload);
        } catch (\Throwable $e) {
            return $this->skip('adapter mapping failed: ' . $e->getMessage());
        }
        if ($item === null) {
            return response()->json(['success' => true, 'dropped' => true]);
        }

        // Hand to the EXISTING ingestor. One bad message never 500s the webhook.
        try {
            $device->forceFill(['last_seen_at' => now()])->save();
            $result = $ingestor->ingest($device, $item);
        } catch (\Throwable $e) {
            Log::error('AT-149 WAHA webhook ingest error', [
                'session'    => $session,
                'device_id'  => $device->id,
                'message_id' => $item['message_id'] ?? null,
                'error'      => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'error' => 'ingest_failed'], 200);
        }

        return response()->json(['success' => true, 'result' => $result]);
    }

    /** Log and 200 — a skip must never trigger a WAHA retry. */
    private function skip(string $reason): JsonResponse
    {
        Log::info('AT-149 WAHA webhook skipped', ['reason' => $reason]);

        return response()->json(['success' => true, 'skipped' => true]);
    }
}
