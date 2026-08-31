<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobilePhotoEvent;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ingest for the mobile photo-upload telemetry log.
 *
 * Spec: .ai/specs/mobile-photo-upload-telemetry.md
 *
 * Design rule that governs every decision in here: THIS ENDPOINT MUST NEVER COST
 * AN AGENT A PHOTO. It is diagnostics. A malformed row, an unknown phase, a
 * property the user cannot see — none of those may fail the batch, because the
 * client flushes this log opportunistically alongside real uploads and a 4xx/5xx
 * that makes it retry forever is a worse bug than the one we are trying to see.
 * Bad rows are counted and skipped; the response is 200 with a tally.
 */
class MobilePhotoEventController extends Controller
{
    /** Hard cap per call — the client batches, but must not be able to flood. */
    private const MAX_EVENTS = 200;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $events = $request->input('events');
        if (! is_array($events)) {
            return response()->json([
                'message'  => 'Nothing to record.',
                'recorded' => 0,
                'skipped'  => 0,
            ]);
        }

        $events = array_slice($events, 0, self::MAX_EVENTS);

        // Resolve each property ONCE per batch, not per event: a 40-photo shoot
        // is 40+ events against the same listing.
        $propertyCache = [];
        $recorded = 0;
        $skipped  = 0;

        foreach ($events as $raw) {
            if (! is_array($raw)) {
                $skipped++;
                continue;
            }

            $propertyId     = (int) ($raw['property_id'] ?? 0);
            $clientUploadId = trim((string) ($raw['client_upload_id'] ?? ''));
            $phase          = trim((string) ($raw['phase'] ?? ''));

            // `received` is the server's own word for "the bytes landed". Accepting
            // it from a client would let the log claim an arrival that never
            // happened — which is exactly the question this table exists to settle.
            if ($propertyId <= 0
                || $clientUploadId === ''
                || ! in_array($phase, MobilePhotoEvent::CLIENT_PHASES, true)) {
                $skipped++;
                continue;
            }

            if (! array_key_exists($propertyId, $propertyCache)) {
                $property = Property::find($propertyId); // agency-scoped globally
                $propertyCache[$propertyId] = ($property && $property->agency_id === $user->agency_id)
                    ? $property
                    : null;
            }
            $property = $propertyCache[$propertyId];

            if (! $property) {
                $skipped++;
                continue;
            }

            MobilePhotoEvent::recordQuietly([
                'agency_id'        => $property->agency_id,
                'user_id'          => $user->id,
                'property_id'      => $property->id,
                'client_upload_id' => mb_substr($clientUploadId, 0, 191),
                'batch_id'         => ($b = trim((string) ($raw['batch_id'] ?? ''))) !== ''
                    ? mb_substr($b, 0, 191)
                    : null,
                'phase'            => $phase,
                'occurred_at'      => $this->parseOccurredAt($raw['occurred_at'] ?? null),
                'meta'             => is_array($raw['meta'] ?? null) ? $raw['meta'] : null,
            ]);

            $recorded++;
        }

        return response()->json([
            'message'  => "Recorded {$recorded} event(s).",
            'recorded' => $recorded,
            'skipped'  => $skipped,
        ]);
    }

    /**
     * Accept either epoch milliseconds or an ISO-8601 string, and never throw on
     * junk — an unparseable timestamp costs us one column, not the whole event.
     */
    private function parseOccurredAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $ms = (float) $value;
                // Anything past ~2001 in seconds is milliseconds here; the app
                // already generates ms-precision ids, so both shapes turn up.
                $seconds = $ms > 100000000000 ? $ms / 1000 : $ms;

                return date('Y-m-d H:i:s', (int) $seconds);
            }

            return \Carbon\Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
