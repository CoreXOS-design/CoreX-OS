<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TvaContactCapture;
use App\Models\Prospecting\TvaContactCaptureItem;
use App\Services\ContactDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TVA (The Virtual Agent) KYC contact capture ingest. Mirrors
 * Api\DeedsCaptureController::store() — Sanctum-authed, batch payload, never
 * hard-fails the whole batch on one bad row.
 *
 * A capture is staged (tva_contact_captures + tva_contact_capture_items), NOT
 * written straight into Contact/ContactPhone/ContactEmail — an agent reviews
 * the Date/Link Date per number and TICKS which to ingest on the Deeds
 * Capture screen (TvaContactIngestController handles that separate step).
 *
 * Matching, at capture time (both optional, independent):
 *   - tracked_property_id: id_number == an owner on an un-promoted deeds-
 *     capture suspense record (tracked_property_owners).
 *   - matched_contact_id: id_number == an EXISTING Contact's id_number, via
 *     ContactDuplicateService (the agency's normal hard-dedup rule) — a
 *     confident match the ingest step enriches, never overwrites.
 *
 * Opted-out values: the extension operates in "safe mode" — it never sends a
 * value it detected as opted-out (red), so there is nothing to filter here.
 * captures.*.contacts.*.opted_out is accepted for forward-compat with a future
 * retain-but-admin-lock mode (config flag, not a schema/contract change) but
 * is not currently acted on.
 *
 * Payload contract: see .ai/specs/TVA_CONTACT_CAPTURE.md.
 */
final class TvaContactCaptureController extends Controller
{
    public function store(Request $request, ContactDuplicateService $dupes): JsonResponse
    {
        $validated = $request->validate([
            'source'                        => 'nullable|string|max:50',
            'people'                        => 'required|array|min:1',
            'people.*.id_number'            => 'required|string|max:20',
            'people.*.first_name'           => 'nullable|string|max:255',
            'people.*.surname'              => 'nullable|string|max:255',
            'people.*.consent_status'       => 'nullable|string|max:100',
            'people.*.contacts'             => 'required|array',
            'people.*.contacts.*.type'      => 'required|in:cell,tel,email',
            'people.*.contacts.*.value'     => 'required|string|max:255',
            'people.*.contacts.*.date'      => 'nullable|date',
            'people.*.contacts.*.link_date' => 'nullable|date',
            'people.*.contacts.*.opted_out' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId() ?? $user?->agency_id;
        abort_if($agencyId === null, 403, 'No agency context for this token.');

        $results = [];
        foreach ($validated['people'] as $person) {
            try {
                $results[] = $this->ingestOne($person, (int) $agencyId, $user, $dupes);
            } catch (\Throwable $e) {
                Log::warning('TVA contact capture ingest failed for one person', [
                    'id_number' => $person['id_number'] ?? null,
                    'error'     => $e->getMessage(),
                ]);
                $results[] = ['id_number' => $person['id_number'] ?? null, 'error' => $e->getMessage()];
            }
        }

        return response()->json(['ok' => true, 'results' => $results]);
    }

    private function ingestOne(array $person, int $agencyId, $user, ContactDuplicateService $dupes): array
    {
        $idNumber = preg_replace('/\s+/', '', (string) $person['id_number']);

        // COMPANY SCRAPING BLOCK (2026-08-13, interim rule per Johan — mirrors
        // DeedsCaptureController::isCompanyLikeOwner()). TVA is a natural-
        // person lookup by page design, so the extension's client-side check
        // is the primary defense, but the server is the authoritative gate —
        // a direct API call (or a stale extension build) must not be able to
        // bypass this. Nothing is staged (no TvaContactCapture / items row)
        // for a non-natural-person ID.
        if (! \App\Rules\SouthAfricanIdNumber::isValid($idNumber)) {
            return [
                'id_number' => $idNumber,
                'blocked'   => 'company',
                'error'     => 'Company scraping is not allowed at this time — coming soon.',
            ];
        }

        // Suspense-record match — an un-promoted deeds capture whose owners
        // include this ID. Only un-promoted: a promoted capture's owner is
        // already a real linked contact, so it belongs in the standalone flow
        // (still matched via matched_contact_id below, just not re-surfaced
        // under a suspense card that no longer needs review).
        $trackedPropertyId = TrackedProperty::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('capture_kind', 'deeds_capture')
            ->whereNull('promoted_to_property_id')
            ->whereHas('owners', fn ($q) => $q->where('id_number', $idNumber))
            ->value('id');

        // Existing-contact hard match — the agency's normal dedup rule
        // (default: phone/email/id_number, normalised). A hit here is a
        // confident match; the ingest step enriches it, never overwrites it.
        $matchedContactId = $dupes->findDuplicatesForIdentifiers([], [], $idNumber, $agencyId)->first()?->id;

        $capture = TvaContactCapture::create([
            'agency_id'            => $agencyId,
            'captured_by_user_id'  => $user->id,
            'tracked_property_id'  => $trackedPropertyId,
            'matched_contact_id'   => $matchedContactId,
            'id_number'            => $idNumber,
            'first_name'           => $person['first_name'] ?? null,
            'surname'              => $person['surname'] ?? null,
            'source'               => 'tva',
            'consent_status'       => $person['consent_status'] ?? null,
        ]);

        foreach ($person['contacts'] as $c) {
            // Safe model: the extension never sends an opted-out value, but a
            // defensive server-side drop costs nothing and closes the gap if
            // a future caller (or a bug) ever does send one.
            if (!empty($c['opted_out'])) {
                continue;
            }
            TvaContactCaptureItem::create([
                'tva_contact_capture_id' => $capture->id,
                'type'                   => $c['type'],
                'value'                  => trim((string) $c['value']),
                'date'                   => $c['date'] ?? null,
                'link_date'              => $c['link_date'] ?? null,
                'opted_out'              => false,
            ]);
        }

        return [
            'id_number'            => $idNumber,
            'tva_contact_capture_id' => $capture->id,
            'tracked_property_id'  => $trackedPropertyId,
            'matched_contact_id'   => $matchedContactId,
            'items_count'          => $capture->items()->count(),
        ];
    }
}
