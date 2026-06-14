<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationWaDevice;
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

        $stats = ['archived' => 0, 'pending' => 0, 'duplicate' => 0, 'invalid' => 0];

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
}
