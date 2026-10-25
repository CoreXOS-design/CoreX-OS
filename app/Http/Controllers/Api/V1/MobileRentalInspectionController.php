<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AuthorizesRentalInspectionProperty;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CoreX\RentalInspectionAttendanceController;
use App\Http\Controllers\CoreX\RentalInspectionController;
use App\Http\Controllers\CoreX\RentalInspectionRecordingController;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAttendance;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionRoomNote;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use App\Services\Rentals\RentalInspectionAttendanceService;
use App\Services\Rentals\RentalInspectionPhotoCaptureTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * AT-508 P5 / P6 — the staff inspection API for Andre's app. .ai/specs/rental-inspections.md §14.2 (the route table), at-508-mobile-rentals.md
 * §4/§5/§6/§7, handoff contract in .ai/specs/at-508-P5P6-build-notes.md.
 *
 * NO RULE IS DECIDED HERE. Every write validates the app's request shape and then calls the SAME controller action the web recording screen
 * calls (RentalInspectionRecordingController / RentalInspectionAttendanceController), which in turn calls the one model method for the rule
 * (RentalInspectionObservation::record(), RentalInspectionSignature::capture(), RentalInspection::markCompleted(), ...). This class adds only
 * what a phone on a poor line needs on top:
 *   - client_idempotency_key on every queueable write: a repeat returns the SAME record, HTTP 200, `duplicate: true`, never a second row;
 *   - captured_at honoured (late / out-of-order arrival), stored on the record's own time column;
 *   - 410 {"code":"archived"} when the inspection / photo was archived while the write sat in the queue;
 *   - POST .../complete takes the app's own count of unsent items and refuses (409 items_pending) while it is above zero;
 *   - absolute photo URLs, and one full-resync detail payload (GET /mobile/rental-inspections/{id}).
 *
 * Scoping is two layers, as the job-card mobile controller: global AgencyScope 404s another agency's id; guardRentalRecordScope() then 403s a
 * same-agency id outside the user's own/branch ceiling. Lists use RentalInspection::scopeVisibleTo() at the query layer.
 */
class MobileRentalInspectionController extends Controller
{
    use AuthorizesRentalInspectionProperty;
    use AuthorizesRentalRecordScope;

    private const PER_PAGE = 25;
    private const STATUSES = [
        RentalInspection::STATUS_DRAFT, RentalInspection::STATUS_IN_PROGRESS, RentalInspection::STATUS_AWAITING_SIGNATURE,
        RentalInspection::STATUS_COMPLETED, RentalInspection::STATUS_CANCELLED,
    ];
    private const TYPES = [RentalInspection::TYPE_IN, RentalInspection::TYPE_OUT, RentalInspection::TYPE_INTERIM, RentalInspection::TYPE_AD_HOC];

    // ═══ checklist ═══════════════════════════════════════════════════════════════════

    /** GET /mobile/properties/{property}/rental-inspection-items — the property's rooms and items, never hardcoded in the app (§14.3). */
    public function checklist(Request $request, Property $property): JsonResponse
    {
        $this->authorizePropertyForInspections($property, forEdit: false);
        $request->validate(['include_retired' => ['nullable', 'boolean']]);
        $includeRetired = $request->boolean('include_retired');
        $agencyId = (int) $property->agency_id;

        $checklist = $this->checklistFor($property, $includeRetired);

        return response()->json($checklist + [
            'property' => ['id' => $property->id, 'address' => $property->buildDisplayAddress()],
            'condition_states' => RentalInspectionSetting::conditionStatesFor($agencyId),
            'refusal_reason_presets' => RentalInspectionSetting::refusalReasonPresetsFor($agencyId),
            'meta' => [
                'empty_reason' => ($checklist['rooms'] === [] && $checklist['meters'] === [] && $checklist['other_items'] === [])
                    ? 'This property has no rooms or items to inspect yet. They are added on the property\'s Inspections tab on the web.'
                    : null,
            ],
        ]);
    }

    // ═══ list / due ══════════════════════════════════════════════════════════════════

    /** GET /mobile/rental-inspections — my inspections: search, sort, filter, 25 per page. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(['due', 'type', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'scope' => ['nullable', Rule::in(['own', 'branch', 'all'])],
            'lease_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $base = fn () => RentalInspection::query()->visibleTo($user, $v['scope'] ?? null);
        $query = $base()
            ->with(['property', 'lease.tenants.contact', 'inspector'])
            ->withExists(['discrepancies as has_unresolved_discrepancy' => fn ($d) => $d->whereNull('resolved_at')]);

        $search = trim((string) ($v['q'] ?? $v['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($search))
                    ->orWhereHas('lease.tenants.contact', function ($c) use ($search) {
                        $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) like ?", ["%{$search}%"]);
                    })
                    ->orWhereHas('inspector', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }
        if (! empty($v['type'])) {
            $query->where('rental_inspections.type', $v['type']);
        }
        if (! empty($v['status'])) {
            $query->where('rental_inspections.status', $v['status']);
        }
        // An inspection started on the spot has no scheduled date; it is placed by the day it was created (same rule as the web list).
        if (! empty($v['date_from'])) {
            $query->whereRaw('DATE(COALESCE(rental_inspections.scheduled_for, rental_inspections.created_at)) >= ?', [$v['date_from']]);
        }
        if (! empty($v['date_to'])) {
            $query->whereRaw('DATE(COALESCE(rental_inspections.scheduled_for, rental_inspections.created_at)) <= ?', [$v['date_to']]);
        }
        if (! empty($v['lease_id'])) {
            $query->where('rental_inspections.lease_id', (int) $v['lease_id']);
        }

        $direction = ($v['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        match ($v['sort'] ?? 'due') {
            'type' => $query->orderBy('rental_inspections.type', $direction),
            'status' => $query->orderBy('rental_inspections.status', $direction),
            default => $query->orderByRaw("COALESCE(rental_inspections.scheduled_for, rental_inspections.created_at) {$direction}"),
        };
        $query->orderBy('rental_inspections.id', $direction);

        $page = $query->paginate(self::PER_PAGE, ['rental_inspections.*'], 'page', (int) ($v['page'] ?? 1));

        $filtered = $search !== '' || ! empty($v['type']) || ! empty($v['status']) || ! empty($v['date_from']) || ! empty($v['date_to']) || ! empty($v['lease_id']);
        $emptyReason = null;
        if ($page->total() === 0) {
            $emptyReason = ($filtered && $base()->exists())
                ? 'No inspections match your search or filters.'
                : 'There are no inspections for you to see yet.';
        }

        return response()->json([
            'data' => $page->getCollection()->map(fn (RentalInspection $i) => $this->listRow($i))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => self::PER_PAGE, 'total' => $page->total(), 'last_page' => $page->lastPage(), 'empty_reason' => $emptyReason],
        ]);
    }

    // ═══ start / detail ══════════════════════════════════════════════════════════════

    /** POST /mobile/rental-inspections — begin an inspection on a lease. A repeat of the same client_idempotency_key returns the same one. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'lease_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(self::TYPES)],
            'client_idempotency_key' => ['required', 'uuid'],
            'captured_at' => ['nullable'],
            'planned_date_id' => ['nullable', 'integer'],
        ]);

        // AgencyScope 404s another agency's lease; a lease in MY agency but outside my own/branch ceiling is a 403 (the property too — same as the web).
        $lease = Lease::query()->find($v['lease_id']);
        abort_if($lease === null, 404);
        abort_unless(Lease::query()->visibleTo($user, null)->whereKey($lease->id)->exists(), 403);
        $property = Property::query()->visibleTo($user)->find($lease->property_id);
        abort_if($property === null, 403);

        $key = $v['client_idempotency_key'];
        if ($existing = $this->startedWithKey((int) $lease->agency_id, $key)) {
            return $this->replayStart($existing, $lease, $user);
        }

        $capturedAt = $this->capturedAt($request, ['lease_id' => $lease->id, 'user_id' => $user->id]);

        try {
            $inspection = DB::transaction(function () use ($lease, $property, $v, $user, $key, $capturedAt) {
                // serialise two identical retries racing each other: the second waits here, then finds the first's row
                Lease::withoutGlobalScopes()->whereKey($lease->id)->lockForUpdate()->first();
                if ($again = $this->startedWithKey((int) $lease->agency_id, $key)) {
                    return $again;
                }

                $inspection = RentalInspection::start($property, $v['type'], $user, $lease);
                $inspection->forceFill(['client_idempotency_key' => $key])->saveQuietly();
                if ($capturedAt !== null) {
                    $inspection->forceFill(['created_at' => $capturedAt])->saveQuietly();
                }

                return $inspection;
            });
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cannot_start'], 409);
        }

        // "Book from this date" — the same re-checked link the web start makes
        if (! empty($v['planned_date_id'])) {
            app(RentalInspectionController::class)->linkPlannedDate($request, $inspection);
        }

        // two identical retries racing: the loser finds the winner's row inside the lock and answers as a replay
        if (! $inspection->wasRecentlyCreated) {
            return $this->replayStart($inspection, $lease, $user);
        }

        return response()->json(['duplicate' => false] + $this->detailPayload($inspection->fresh()), 201);
    }

    /** GET /mobile/rental-inspections/{inspection} — everything the app needs to rebuild its screen (also after a reinstall / new phone). */
    public function show(Request $request, int $inspection): JsonResponse
    {
        $insp = $this->findInspection($inspection);

        return response()->json($this->detailPayload($insp));
    }

    // ═══ recording ═══════════════════════════════════════════════════════════════════

    /** POST …/observations — record one observation (and detect a discrepancy) through the one path. */
    public function storeObservation(Request $request, int $inspection): JsonResponse
    {
        $insp = $this->findInspection($inspection);
        $v = $request->validate([
            'item_id' => ['required_without:rental_inspection_item_id', 'nullable', 'integer'],
            'rental_inspection_item_id' => ['nullable', 'integer'],
            'client_idempotency_key' => ['required', 'uuid'],
            'captured_at' => ['nullable'],
        ]);
        $itemId = (int) ($v['item_id'] ?? $v['rental_inspection_item_id']);
        // an unknown item is reported against the field the app actually sent
        if (! RentalInspectionItem::query()->where('id', $itemId)->where('property_id', $insp->property_id)->exists()) {
            $message = 'That checklist item is not on this inspection\'s property.';

            return response()->json(['message' => $message, 'errors' => [(! empty($v['item_id']) ? 'item_id' : 'rental_inspection_item_id') => [$message]]], 422);
        }

        if ($replay = $this->observationReplay($insp, $v['client_idempotency_key'])) {
            return $replay;
        }

        $request->merge([
            'rental_inspection_item_id' => $itemId,
            'source' => $request->input('source') ?: $this->defaultSource($insp),
        ]);
        $response = $this->recording()->storeObservation($request, $insp);
        if ($response->getStatusCode() >= 300) {
            return $this->failure($response);
        }

        $observation = RentalInspectionObservation::withoutGlobalScopes()->where('client_idempotency_key', $v['client_idempotency_key'])->firstOrFail();
        $body = (array) $response->getData(true);
        $late = (bool) ($body['added_after_signing'] ?? false);

        return response()->json(['duplicate' => false] + $this->observationPayload($observation->load('item')) + ($late ? ['added_after_signing' => true, 'message' => $body['message'] ?? null] : []), 201);
    }

    /** POST …/observations/{observation}/photos — one photo (jpg/png/webp/heic) against one observation. */
    public function storePhoto(Request $request, int $inspection, int $observation): JsonResponse
    {
        $insp = $this->findInspection($inspection);
        $obs = RentalInspectionObservation::query()->find($observation);
        abort_if($obs === null || (int) $obs->rental_inspection_id !== (int) $insp->id, 404);

        $v = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'client_idempotency_key' => ['required', 'uuid'],
            'captured_at' => ['nullable'],
        ]);

        // looked up globally and trashed included, like the web: our own row replays, another inspection's key is a 422, an archived one is a 410
        $existing = RentalInspectionPhoto::withoutGlobalScopes()->withTrashed()->where('client_idempotency_key', $v['client_idempotency_key'])->first();
        if ($existing) {
            if ((int) $existing->rental_inspection_id !== (int) $insp->id) {
                return response()->json(['message' => 'That retry key was already used for a different inspection.'], 422);
            }
            if ($existing->trashed()) {
                return $this->archived('This photo was archived, so it was not added again.');
            }

            return response()->json(['duplicate' => true] + $this->photoPayload($existing), 200);
        }

        $response = $this->recording()->storePhoto($request, $insp, $obs);
        if ($response->getStatusCode() >= 300) {
            return $this->failure($response);
        }

        $photo = RentalInspectionPhoto::withoutGlobalScopes()->where('client_idempotency_key', $v['client_idempotency_key'])->firstOrFail();
        $body = (array) $response->getData(true);
        $late = ! empty($body['added_after_signing']);

        return response()->json(['duplicate' => false] + $this->photoPayload($photo) + ($late ? ['message' => $body['message'] ?? null] : []), 201);
    }

    /** POST …/rooms/{room}/notes — the note box under a room table. Immutable: a correction is a new note. */
    public function storeRoomNote(Request $request, int $inspection, int $room): JsonResponse
    {
        $insp = $this->findInspection($inspection);
        $roomModel = PropertyRoom::query()->find($room);
        abort_if($roomModel === null || (int) $roomModel->property_id !== (int) $insp->property_id, 404);

        $v = $request->validate([
            'note' => ['required', 'string', 'max:4000'],
            'client_idempotency_key' => ['required', 'uuid'],
            'captured_at' => ['nullable'],
        ]);

        if ($existing = RentalInspectionRoomNote::withoutGlobalScopes()->where('rental_inspection_id', $insp->id)->where('client_idempotency_key', $v['client_idempotency_key'])->first()) {
            return response()->json(['duplicate' => true] + $this->roomNotePayload($existing), 200);
        }

        $response = $this->recording()->storeRoomNote($request, $insp, $roomModel);
        if ($response->getStatusCode() >= 300) {
            return $this->failure($response);
        }

        $note = RentalInspectionRoomNote::withoutGlobalScopes()->where('rental_inspection_id', $insp->id)->where('client_idempotency_key', $v['client_idempotency_key'])->firstOrFail();
        if ($capturedAt = $this->capturedAt($request, ['rental_inspection_id' => $insp->id])) {
            // "the room's current note" is the latest by time, so a note typed offline earlier sorts where it belongs
            $note->forceFill(['created_at' => $capturedAt])->saveQuietly();
        }

        return response()->json(['duplicate' => false] + $this->roomNotePayload($note->fresh()), 201);
    }

    /** POST …/overall-notes — the one summary line at the foot. Last write wins, so a repeat is harmless. */
    public function updateOverallNotes(Request $request, int $inspection): JsonResponse
    {
        $insp = $this->findInspection($inspection);
        $request->validate(['overall_notes' => ['nullable', 'string', 'max:4000']]);

        $response = $this->recording()->updateOverallNotes($request, $insp);
        if ($response->getStatusCode() >= 300) {
            return $this->failure($response);
        }

        return response()->json(['overall_notes' => $insp->fresh()->overall_notes]);
    }

    /** POST …/attendance — who was there (needed before In/Out/Interim can be signed or completed). */
    public function storeAttendance(Request $request, int $inspection, RentalInspectionAttendanceController $web): JsonResponse
    {
        $insp = $this->findInspection($inspection);
        $v = $request->validate(['client_idempotency_key' => ['required', 'uuid']]);

        if ($existing = RentalInspectionAttendance::withoutGlobalScopes()->where('rental_inspection_id', $insp->id)->where('client_idempotency_key', $v['client_idempotency_key'])->first()) {
            return response()->json(['duplicate' => true, 'recorded_id' => $existing->id, 'attendance_board' => app(RentalInspectionAttendanceService::class)->board($insp)], 200);
        }

        $response = $web->store($request, $insp);
        if ($response->getStatusCode() >= 300) {
            return $this->failure($response);
        }

        return response()->json(['duplicate' => false] + (array) $response->getData(true), 201);
    }

    /** POST …/discrepancies/{discrepancy}/resolve — pick the observation that stands. */
    public function resolveDiscrepancy(Request $request, int $inspection, int $discrepancy): JsonResponse
    {
        $insp = $this->findInspection($inspection);
        $row = RentalInspectionDiscrepancy::query()->find($discrepancy);
        abort_if($row === null || (int) $row->rental_inspection_id !== (int) $insp->id, 404);

        $v = $request->validate([
            'accepted_observation_id' => ['required', 'integer'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ]);

        // a retry after the first attempt landed: the same decision is answered, not refused
        if ($row->isResolved() && (int) $row->accepted_observation_id === (int) $v['accepted_observation_id']) {
            return response()->json(['duplicate' => true] + $this->discrepancyPayload($row->load('observations')), 200);
        }

        $response = $this->recording()->resolveDiscrepancy($request, $insp, $row);
        if ($response->getStatusCode() >= 300) {
            return $this->failure($response);
        }

        return response()->json(['duplicate' => false] + $this->discrepancyPayload($row->fresh()->load('observations')), 200);
    }

    // ═══ resolution, scoping, replays ════════════════════════════════════════════════

    private function recording(): RentalInspectionRecordingController
    {
        return app(RentalInspectionRecordingController::class);
    }

    /** AgencyScope 404 -> own/branch/agency 403 -> archived 410. Every inspection route resolves its record here. */
    private function findInspection(int $id): RentalInspection
    {
        $inspection = RentalInspection::withTrashed()->find($id);
        abort_if($inspection === null, 404);
        $this->guardRentalRecordScope($inspection, 'rental_inspections', $inspection->property?->branch_id);
        if ($inspection->trashed()) {
            abort($this->archived('This inspection was archived, so nothing more can be added to it.'));
        }

        return $inspection;
    }

    private function archived(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => 'archived'], 410);
    }

    /** A refusal from the shared web action, passed through with the status it chose and a stable `code` the app can switch on. */
    private function failure(JsonResponse $response): JsonResponse
    {
        $body = (array) $response->getData(true);
        if (! isset($body['code'])) {
            $body['code'] = match (true) {
                isset($body['ungraded_items']) => 'items_ungraded',
                isset($body['missing_attendance']) => 'attendance_missing',
                isset($body['missing_required_notes']) => 'notes_missing',
                ! empty($body['requires_acknowledgement']) => 'needs_acknowledgement',
                ($body['reason'] ?? null) === 'signed_locked' => 'signed_locked',
                ($body['reason'] ?? null) === 'not_recordable' => 'not_recordable',
                $response->getStatusCode() === 409 => 'conflict',
                default => 'refused',
            };
        }

        return response()->json($body, $response->getStatusCode());
    }

    private function startedWithKey(int $agencyId, string $key): ?RentalInspection
    {
        return RentalInspection::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('client_idempotency_key', $key)->first();
    }

    private function replayStart(RentalInspection $existing, Lease $lease, User $user): JsonResponse
    {
        if ((int) $existing->lease_id !== (int) $lease->id) {
            return response()->json(['message' => 'That retry key was already used for a different inspection.'], 422);
        }
        if ($existing->trashed()) {
            return $this->archived('This inspection was archived, so it was not started again.');
        }

        return response()->json(['duplicate' => true] + $this->detailPayload($existing), 200);
    }

    private function observationReplay(RentalInspection $insp, string $key): ?JsonResponse
    {
        $existing = RentalInspectionObservation::withoutGlobalScopes()->where('client_idempotency_key', $key)->first();
        if (! $existing) {
            return null;
        }
        if ((int) $existing->rental_inspection_id !== (int) $insp->id) {
            return response()->json(['message' => 'That retry key was already used for a different inspection.'], 422);
        }

        return response()->json(['duplicate' => true] + $this->observationPayload($existing->load('item')), 200);
    }

    private function defaultSource(RentalInspection $insp): string
    {
        return match ($insp->type) {
            RentalInspection::TYPE_IN => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            RentalInspection::TYPE_OUT => RentalInspectionObservation::SOURCE_OUT_INSPECTION,
            default => RentalInspectionObservation::SOURCE_AD_HOC,
        };
    }

    /** The app's own capture time, or null when it sent none / sent nonsense / sent a future time (absorbed, never a 422 on evidence). */
    private function capturedAt(Request $request, array $context = []): ?Carbon
    {
        $raw = $request->input('captured_at');

        return app(RentalInspectionPhotoCaptureTime::class)->clientTime(is_string($raw) ? substr($raw, 0, 64) : null, $context);
    }

    // ═══ payloads ════════════════════════════════════════════════════════════════════

    /** Every image URL the API returns is absolute (rental-images.md 7a), so the device can load it. */
    private static function absoluteUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^(https?:)?//#i', $url)) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
    }

    private function iso($value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }

    /** @return array<string, mixed> */
    private function listRow(RentalInspection $i): array
    {
        return [
            'id' => $i->id,
            'type' => $i->type,
            'type_label' => RentalInspection::typeLabel($i->type),
            'status' => $i->status,
            'status_label' => ucfirst(str_replace('_', ' ', (string) $i->status)),
            'scheduled_for' => $i->scheduled_for?->toDateString(),
            'scheduled_time' => $i->scheduled_time ? substr((string) $i->scheduled_time, 0, 5) : null,
            'due_date' => ($i->scheduled_for ?? $i->created_at)?->toDateString(),
            'property' => ['id' => $i->property_id, 'address' => $i->property?->buildDisplayAddress()],
            'lease_id' => $i->lease_id,
            'tenant_names' => $i->lease?->tenantNames(),
            'inspector' => $i->inspector ? ['id' => $i->inspector->id, 'name' => $i->inspector->name] : null,
            'has_unresolved_discrepancy' => (bool) ($i->has_unresolved_discrepancy ?? false),
            'started_at' => $this->iso($i->created_at),
            'completed_at' => $this->iso($i->completed_at),
        ];
    }

    /** @return array{rooms: list<array<string,mixed>>, meters: list<array<string,mixed>>, other_items: list<array<string,mixed>>} */
    private function checklistFor(Property $property, bool $includeRetired): array
    {
        $rooms = PropertyRoom::query()->where('property_id', $property->id)
            ->when(! $includeRetired, fn ($q) => $q->where('is_retired', false))
            ->orderBy('sort_order')->orderBy('id')->get();
        $items = RentalInspectionItem::query()->where('property_id', $property->id)
            ->when(! $includeRetired, fn ($q) => $q->where('is_retired', false))
            ->orderBy('sort_order')->orderBy('id')->get();

        $row = fn (RentalInspectionItem $i) => [
            'id' => $i->id, 'label' => $i->label, 'kind' => $i->kind, 'space_type' => $i->space_type,
            'sort_order' => (int) $i->sort_order, 'is_retired' => (bool) $i->is_retired,
        ];
        $byRoom = $items->where('kind', RentalInspectionItem::KIND_SPACE)->whereNotNull('property_room_id')->groupBy('property_room_id');

        return [
            'rooms' => $rooms->map(fn (PropertyRoom $r) => [
                'id' => $r->id, 'label' => $r->label, 'type' => $r->type, 'sort_order' => (int) $r->sort_order, 'is_retired' => (bool) $r->is_retired,
                'items' => ($byRoom[$r->id] ?? collect())->map($row)->values()->all(),
            ])->values()->all(),
            'meters' => $items->where('kind', RentalInspectionItem::KIND_METER)->map($row)->values()->all(),
            // spaces captured before rooms existed (no room): still recordable, shown outside any room
            'other_items' => $items->where('kind', RentalInspectionItem::KIND_SPACE)->whereNull('property_room_id')->map($row)->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function observationPayload(RentalInspectionObservation $o): array
    {
        $discrepancy = RentalInspectionDiscrepancy::query()
            ->where('rental_inspection_id', $o->rental_inspection_id)->where('rental_inspection_item_id', $o->rental_inspection_item_id)
            ->whereNull('resolved_at')->whereHas('observations', fn ($q) => $q->where('rental_inspection_observations.id', $o->id))
            ->value('id');

        return [
            'id' => $o->id,
            'inspection_id' => $o->rental_inspection_id,
            'item_id' => $o->rental_inspection_item_id,
            'item_label' => $o->relationLoaded('item') ? $o->item?->label : null,
            'condition' => $o->condition,
            'notes' => $o->notes,
            'source' => $o->source,
            'observed_by_user_id' => $o->observed_by_user_id,
            'captured_at' => $this->iso($o->created_at),
            'received_at' => $this->iso($o->received_at),
            'added_after_signing' => $o->added_after_signing_at !== null,
            'client_idempotency_key' => $o->client_idempotency_key,
            // set when this observation conflicts with another author's for the same item: the app shows "resolve"
            'discrepancy_id' => $discrepancy ? (int) $discrepancy : null,
        ];
    }

    /** @return array<string, mixed> */
    private function photoPayload(RentalInspectionPhoto $p): array
    {
        return [
            'id' => $p->id,
            'inspection_id' => $p->rental_inspection_id,
            'observation_id' => $p->rental_inspection_observation_id,
            'room_id' => $p->property_room_id,
            'url' => self::absoluteUrl($p->storage_path),
            'taken_at' => $this->iso($p->taken_at),
            'taken_at_source' => $p->taken_at_source,
            'added_after_signing' => $p->added_after_signing_at !== null,
            'client_idempotency_key' => $p->client_idempotency_key,
            'archived' => $p->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    private function roomNotePayload(RentalInspectionRoomNote $n): array
    {
        return [
            'id' => $n->id, 'inspection_id' => $n->rental_inspection_id, 'room_id' => $n->property_room_id, 'note' => $n->note,
            'captured_at' => $this->iso($n->created_at), 'created_by_user_id' => $n->created_by_user_id, 'client_idempotency_key' => $n->client_idempotency_key,
        ];
    }

    /** @return array<string, mixed> */
    private function signaturePayload(RentalInspectionSignature $s): array
    {
        return [
            'id' => $s->id,
            'inspection_id' => $s->rental_inspection_id,
            'party_role' => $s->party_role,
            'party_contact_id' => $s->party_contact_id,
            'disposition' => $s->disposition,
            'signed_by_name' => $s->signed_by_name,
            'signed_via' => $s->signed_via,
            'refusal_reason_preset' => $s->refusal_reason_preset,
            'refusal_reason_note' => $s->refusal_reason_note,
            'has_signature_image' => ! empty($s->getRawOriginal('party_signature_path')),
            'has_wet_ink_scan' => ! empty($s->getRawOriginal('wet_ink_upload_path')),
            'captured_at' => $this->iso($s->disposition_recorded_at),
            'superseded' => $s->superseded_at !== null,
            'client_idempotency_key' => $s->client_idempotency_key,
        ];
    }

    /** @return array<string, mixed> */
    private function discrepancyPayload(RentalInspectionDiscrepancy $d): array
    {
        return [
            'id' => $d->id,
            'item_id' => $d->rental_inspection_item_id,
            'detected_at' => $this->iso($d->detected_at),
            'resolved' => $d->isResolved(),
            'resolved_at' => $this->iso($d->resolved_at),
            'accepted_observation_id' => $d->accepted_observation_id,
            'resolution_note' => $d->resolution_note,
            'observation_ids' => $d->relationLoaded('observations') ? $d->observations->pluck('id')->values()->all() : [],
        ];
    }

    /**
     * The full-resync payload: everything the app needs to rebuild its inspection screen from nothing.
     *
     * @return array<string, mixed>
     */
    private function detailPayload(RentalInspection $i): array
    {
        $i->loadMissing(['property', 'lease.tenants.contact', 'inspector', 'createdBy']);
        $property = $i->property;

        $observations = RentalInspectionObservation::withoutGlobalScopes()->where('rental_inspection_id', $i->id)
            ->with('item')->orderBy('created_at')->orderBy('id')->get();
        $photos = RentalInspectionPhoto::withoutGlobalScopes()->where('rental_inspection_id', $i->id)->whereNull('deleted_at')
            ->orderBy('taken_at')->orderBy('id')->get();
        $photosByObservation = $photos->groupBy('rental_inspection_observation_id');
        $discrepancies = RentalInspectionDiscrepancy::withoutGlobalScopes()->where('rental_inspection_id', $i->id)->with('observations')->orderBy('id')->get();
        $signatures = RentalInspectionSignature::withoutGlobalScopes()->where('rental_inspection_id', $i->id)->orderBy('id')->get();
        $notes = RentalInspectionRoomNote::withoutGlobalScopes()->where('rental_inspection_id', $i->id)->orderBy('created_at')->orderBy('id')->get();

        // the latest REAL assessment per item (a photo anchor with no condition is not one, nor is late evidence) — the app may also derive this itself
        $latestByItem = $observations->filter(fn ($o) => $o->condition !== RentalInspectionObservation::CONDITION_PENDING && $o->added_after_signing_at === null)
            ->groupBy('rental_inspection_item_id')->map(fn ($g) => $g->last()->id);

        $checklist = $this->checklistFor($property, includeRetired: true);
        foreach (['rooms' => true, 'meters' => false, 'other_items' => false] as $key => $nested) {
            $stamp = fn (array $item) => $item + ['latest_observation_id' => $latestByItem[$item['id']] ?? null];
            if ($nested) {
                $checklist['rooms'] = array_map(fn ($r) => ['items' => array_map($stamp, $r['items'])] + $r, $checklist['rooms']);
            } else {
                $checklist[$key] = array_map($stamp, $checklist[$key]);
            }
        }

        $landlord = $property?->landlordContact();
        $agencyId = (int) $i->agency_id;

        return [
            'id' => $i->id,
            'type' => $i->type,
            'type_label' => RentalInspection::typeLabel($i->type),
            'status' => $i->status,
            'status_label' => ucfirst(str_replace('_', ' ', (string) $i->status)),
            'is_recordable' => $i->isRecordable(),
            'signed_locked' => $i->isSignedLocked(),
            'started_at' => $this->iso($i->created_at),
            'completed_at' => $this->iso($i->completed_at),
            'scheduled_for' => $i->scheduled_for?->toDateString(),
            'scheduled_time' => $i->scheduled_time ? substr((string) $i->scheduled_time, 0, 5) : null,
            'signing_deadline_at' => $this->iso($i->signing_deadline_at),
            'fault_report_deadline_at' => $this->iso($i->fault_report_deadline_at),
            'inspector' => $i->inspector ? ['id' => $i->inspector->id, 'name' => $i->inspector->name] : null,
            'created_by' => $i->createdBy ? ['id' => $i->createdBy->id, 'name' => $i->createdBy->name] : null,
            'property' => ['id' => $property?->id, 'address' => $property?->buildDisplayAddress()],
            'lease_id' => $i->lease_id,
            'parties' => [
                'tenants' => $i->lease ? $i->lease->tenants->map(fn ($t) => ['contact_id' => $t->contact_id, 'name' => $t->contact?->full_name])->values()->all() : [],
                'landlord' => $landlord ? ['contact_id' => $landlord->id, 'name' => $landlord->full_name] : null,
            ],
            'header' => [
                'electricity_meter_reading' => $i->electricity_meter_reading, 'water_meter_reading' => $i->water_meter_reading,
                'furnished_status' => $i->furnished_status, 'property_type' => $i->property_type,
                'keys_count' => $i->keys_count, 'keys_description' => $i->keys_description,
                'remotes_count' => $i->remotes_count, 'remotes_description' => $i->remotes_description,
                'move_in_date_recorded' => $i->move_in_date_recorded?->toDateString(),
            ],
            'overall_notes' => $i->overall_notes,
            'checklist' => $checklist,
            'observations' => $observations->map(fn ($o) => $this->observationPayload($o) + [
                'photos' => ($photosByObservation[$o->id] ?? collect())->map(fn ($p) => $this->photoPayload($p))->values()->all(),
            ])->values()->all(),
            // every live photo on the inspection, including ones not attached to an observation
            'photos' => $photos->map(fn ($p) => $this->photoPayload($p))->values()->all(),
            'room_notes' => $notes->map(fn ($n) => $this->roomNotePayload($n))->values()->all(),
            'discrepancies' => $discrepancies->map(fn ($d) => $this->discrepancyPayload($d))->values()->all(),
            'signatures' => [
                'required' => $i->signaturesRequired(),
                'entries' => $signatures->map(fn ($s) => $this->signaturePayload($s))->values()->all(),
                'outstanding' => $i->outstandingSignatories()->map(fn ($o) => [
                    'party_role' => $o['party_role'], 'party_contact_id' => $o['party_contact_id'],
                    'name' => $o['party_role'] === RentalInspectionSignature::PARTY_LANDLORD ? $landlord?->full_name
                        : optional($i->lease?->tenants->firstWhere('contact_id', $o['party_contact_id']))->contact?->full_name,
                ])->values()->all(),
                'agent_signed' => $i->hasAgentSignature(),
            ],
            'attendance' => ['required' => ! $i->isRoutineExemptFromChecks(), 'board' => app(RentalInspectionAttendanceService::class)->board($i)],
            'readiness' => $i->signingReadiness(),
            'condition_states' => RentalInspectionSetting::conditionStatesFor($agencyId),
            'refusal_reason_presets' => RentalInspectionSetting::refusalReasonPresetsFor($agencyId),
        ];
    }
}
