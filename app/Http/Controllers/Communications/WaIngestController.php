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
            // AT-135 — agency-configurable read-only body backfill toggle (default
            // ON). The extension gates its idle backfill sweep on this.
            'backfill_enabled' => (bool) optional($device->agency)->wa_history_backfill ?? true,
        ]);
    }

    /**
     * AT-135 — numbers (last-9, SA-core) of this agency's contacts that have at
     * least one WhatsApp message archived with body_status='unreadable' (envelope
     * captured, body not yet rendered). The read-only backfill sweep opens ONLY
     * these chats to scrape + fill the missing bodies. The contact list never
     * reaches the browser — only the set of numbers already in the agent's WA.
     * GET /communications/wa/backfill-targets
     */
    public function backfillTargets(Request $request, \App\Services\Communications\WaBodyBackfillService $svc): JsonResponse
    {
        $device = $request->attributes->get('wa_device');
        if (! $device) {
            return response()->json(['error' => 'No device context'], 401);
        }

        return response()->json([
            'success' => true,
            'numbers' => $svc->pendingBodyNumbers((int) $device->agency_id),
            // AT-135 — @lid digit-keys so the sweep matches @lid chats directly
            // (WhatsApp Web lists chats by @lid; no reverse-resolution needed).
            'lids'    => $svc->pendingBodyLids((int) $device->agency_id),
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
            // AT-133 — extension-resolved real phone jid (…@c.us) + the original
            // @lid (audit) + resolution flag. Optional (older extensions omit them).
            'messages.*.counterpart_phone' => 'nullable|string|max:64',
            'messages.*.counterpart_lid'   => 'nullable|string|max:64',
            'messages.*.resolved'          => 'nullable|boolean',
            // AT-135 — body could not be rendered (encrypted IDB + bubble absent);
            // archive the envelope with body_status=unreadable, never a silent blank.
            'messages.*.body_unreadable'   => 'nullable|boolean',
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

        // AT-137 — batch-stats visibility (FICA): a history backfill pass shows up as
        // body_filled/duplicate/archived counts, so it's verifiable from the log
        // (previously only drops were logged → backfill activity was invisible).
        if (($stats['archived'] ?? 0) + ($stats['duplicate'] ?? 0) + ($stats['body_filled'] ?? 0) + ($stats['reconciled'] ?? 0) > 0) {
            \Log::info('Communication archive: WA ingest batch', [
                'device_id' => $device->id,
                'agency_id' => $device->agency_id,
                'count'     => count($validated['messages']),
                'stats'     => $stats,
            ]);
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
