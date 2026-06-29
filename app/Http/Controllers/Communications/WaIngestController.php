<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationWaDevice;
use App\Services\Communications\ContactIdentifierResolver;
use App\Services\Communications\WaArchiveIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WhatsApp capture ingest endpoint (AT-34). Authed by auth.wa_capture (per-device
 * Bearer token). Accepts a batch of read-only messages scraped from WhatsApp Web
 * and writes them into the spine via WaArchiveIngestor.
 */
class WaIngestController extends Controller
{
    /**
     * Liveness heartbeat (AT-44). The extension pings this on load and on an
     * interval. Reaching here means the whole pipe works — injection → CORS →
     * Bearer auth — and the auth.wa_capture middleware has already stamped
     * last_seen_at. Returns the device id so the client console can confirm
     * WHICH device row authenticated (catches the stale-token / multi-row case).
     */
    public function ping(Request $request): JsonResponse
    {
        /** @var CommunicationWaDevice|null $device */
        $device = $request->attributes->get('wa_device');
        if (! $device) {
            return response()->json(['error' => 'No device context'], 401);
        }

        return response()->json([
            'success'      => true,
            'device_id'    => $device->id,
            'last_seen_at' => optional($device->last_seen_at)->toIso8601String(),
        ]);
    }

    public function ingest(Request $request, WaArchiveIngestor $ingestor): JsonResponse
    {
        /** @var CommunicationWaDevice|null $device */
        $device = $request->attributes->get('wa_device');
        if (! $device) {
            return response()->json(['error' => 'No device context'], 401);
        }

        $validated = $request->validate([
            'messages'                 => 'required|array|min:1|max:500',
            'messages.*.message_id'    => 'required|string|max:255',
            'messages.*.chat_id'       => 'required|string|max:255',
            'messages.*.direction'     => 'nullable|string|max:20',
            'messages.*.sender'        => 'nullable|string|max:255',
            'messages.*.timestamp'     => 'nullable',
            'messages.*.text'          => 'nullable|string',
            'messages.*.has_media'     => 'nullable|boolean',
            'messages.*.media'         => 'nullable|array',
        ]);

        // AT-122 — 'dropped' = matched no contact, discarded (never stored).
        // 'pending' retained at 0 for client back-compat; ingest no longer parks.
        $stats = ['archived' => 0, 'dropped' => 0, 'pending' => 0, 'duplicate' => 0, 'invalid' => 0];

        foreach ($validated['messages'] as $msg) {
            try {
                $result = $ingestor->ingest($device, $msg);
                $stats[$result] = ($stats[$result] ?? 0) + 1;
            } catch (\Throwable $e) {
                // One bad message never fails the batch.
                $stats['invalid']++;
                \Log::error('WA ingest error (device ' . $device->id . '): ' . $e->getMessage());
            }
        }

        return response()->json(['success' => true, 'stats' => $stats]);
    }

    /**
     * Contact-aware history-sweep gate (AT-44). The read-only extension asks
     * "is each of these WhatsApp numbers a CoreX contact for my agency?" so it
     * can decide capture DEPTH per chat: known contact → backfill full visible
     * history; unknown → forward-only (POPIA data-minimisation at the source).
     *
     * The browser only ever learns yes/no about numbers it ALREADY sees in the
     * agent's own WhatsApp — the agency contact list is never shipped to the
     * client. This endpoint does NOT write the archive; the real ingestion gate
     * still runs server-side in WaArchiveIngestor on every POST.
     */
    public function contactCheck(Request $request, ContactIdentifierResolver $resolver): JsonResponse
    {
        /** @var CommunicationWaDevice|null $device */
        $device = $request->attributes->get('wa_device');
        if (! $device) {
            return response()->json(['error' => 'No device context'], 401);
        }

        $validated = $request->validate([
            'numbers'   => 'required|array|min:1|max:500',
            'numbers.*' => 'nullable|string|max:64',
        ]);

        $agencyId = (int) $device->agency_id;
        $matches = [];
        foreach ($validated['numbers'] as $number) {
            $number = is_string($number) ? trim($number) : '';
            if ($number === '' || array_key_exists($number, $matches)) {
                continue;
            }
            // Strip any WA jid suffix the extension may have left on (defensive).
            $bare = str_contains($number, '@') ? substr($number, 0, strpos($number, '@')) : $number;
            $matches[$number] = $resolver->resolve($bare, $agencyId) !== null;
        }

        return response()->json(['success' => true, 'matches' => $matches]);
    }
}
