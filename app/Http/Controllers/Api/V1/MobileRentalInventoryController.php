<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CoreX\RentalInventoryCaptureController;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryPhoto;
use App\Models\RentalInventorySetting;
use App\Services\Rentals\RentalInspectionPhotoCaptureTime;
use App\Services\Rentals\RentalRoomPhotoSet;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AT-508 P6 — staff inventories for the app: list, detail, add a photo. .ai/specs/rental-inventory.md, at-508-mobile-rentals.md §4 P6 / §5 / §6,
 * handoff contract .ai/specs/at-508-P5P6-build-notes.md.
 *
 * Same two-layer scoping as the inspection API: global AgencyScope 404s another agency's id, guardRentalRecordScope() 403s a same-agency id
 * outside the user's own/branch ceiling (the web screen answers that case with a 404; the app gets the clearer 403, as on every other rentals
 * route), and the list uses RentalInventory::scopeVisibleTo() at the query layer.
 *
 * The photo add is a thin call into RentalInventoryCaptureController::storePhotos() (the web upload pipeline: private store, HEIC, EXIF
 * orientation, GPS stripped, the draft-only edit rule), plus what a phone on a poor line needs: client_idempotency_key (replay = 200 +
 * duplicate:true), captured_at, and 410 when the inventory or photo was archived while the upload sat in the queue.
 */
class MobileRentalInventoryController extends Controller
{
    use AuthorizesRentalRecordScope;

    private const PER_PAGE = 25;
    private const STATUSES = [
        RentalInventory::STATUS_DRAFT, RentalInventory::STATUS_AWAITING_SIGNATURE, RentalInventory::STATUS_COMPLETED, RentalInventory::STATUS_CANCELLED,
    ];

    /** GET /mobile/rental-inventories — search, sort, filter, 25 per page. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(['created', 'property', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'scope' => ['nullable', Rule::in(['own', 'branch', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $base = fn () => RentalInventory::query()->visibleTo($user, $v['scope'] ?? null);
        $query = $base()->with(['property', 'lease.tenants.contact', 'createdBy'])->withCount('lines');

        $search = trim((string) ($v['q'] ?? $v['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('property', fn ($p) => $p->searchAddress($search))
                    ->orWhereHas('lease.tenants.contact', function ($c) use ($search) {
                        $c->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) like ?", ["%{$search}%"]);
                    })
                    ->orWhereHas('createdBy', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }
        if (! empty($v['status'])) {
            $query->where('rental_inventories.status', $v['status']);
        }
        if (! empty($v['date_from'])) {
            $query->whereDate('rental_inventories.created_at', '>=', $v['date_from']);
        }
        if (! empty($v['date_to'])) {
            $query->whereDate('rental_inventories.created_at', '<=', $v['date_to']);
        }

        // newest first unless the app asks otherwise (same default as the web list)
        $direction = ($v['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        match ($v['sort'] ?? 'created') {
            'property' => $query->orderBy(\App\Models\Property::withoutGlobalScopes()->select('title')->whereColumn('properties.id', 'rental_inventories.property_id')->limit(1), $direction),
            'status' => $query->orderBy('rental_inventories.status', $direction),
            default => $query->orderBy('rental_inventories.created_at', $direction),
        };
        $query->orderBy('rental_inventories.id', $direction);

        $page = $query->paginate(self::PER_PAGE, ['rental_inventories.*'], 'page', (int) ($v['page'] ?? 1));

        $filtered = $search !== '' || ! empty($v['status']) || ! empty($v['date_from']) || ! empty($v['date_to']);
        $emptyReason = null;
        if ($page->total() === 0) {
            $emptyReason = ($filtered && $base()->exists())
                ? 'No inventories match your search or filters.'
                : 'There are no inventories for you to see yet.';
        }

        return response()->json([
            'data' => $page->getCollection()->map(fn (RentalInventory $i) => $this->listRow($i))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => self::PER_PAGE, 'total' => $page->total(), 'last_page' => $page->lastPage(), 'empty_reason' => $emptyReason],
        ]);
    }

    /** GET /mobile/rental-inventories/{inventory} — the whole inventory: rooms, lines, photos, signatures. */
    public function show(Request $request, int $inventory): JsonResponse
    {
        return response()->json($this->detailPayload($this->findInventory($inventory)));
    }

    /** POST /mobile/rental-inventories/{inventory}/photos — one photo (jpg/png/webp/heic), optionally filed to a room and / or tagged to a line. */
    public function storePhoto(Request $request, int $inventory, RentalInventoryCaptureController $web): JsonResponse
    {
        $inv = $this->findInventory($inventory);
        $v = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'client_idempotency_key' => ['required', 'uuid'],
            'captured_at' => ['nullable'],
            'property_room_id' => ['nullable', 'integer'],
            'rental_inventory_line_id' => ['nullable', 'integer'],
        ]);
        $key = $v['client_idempotency_key'];

        // looked up globally and trashed included (the unique index is global): our own row replays, another inventory's key is a 422, an archived photo a 410
        $existing = RentalInventoryPhoto::withoutGlobalScopes()->withTrashed()->where('client_idempotency_key', $key)->first();
        if ($existing) {
            if ((int) $existing->rental_inventory_id !== (int) $inv->id) {
                return response()->json(['message' => 'That retry key was already used for a different inventory.'], 422);
            }
            if ($existing->trashed()) {
                return $this->archived('This photo was archived, so it was not added again.');
            }

            return response()->json(['duplicate' => true] + $this->photoPayload($existing), 200);
        }

        // the web action takes a batch (photos[] + client_idempotency_keys[]); a phone sends one photo per request, which is a batch of one.
        // A fresh request object, because Laravel caches a request's converted uploaded files the first time they are read.
        $batch = Request::create($request->path(), 'POST', array_filter([
            'client_idempotency_keys' => [$key],
            'property_room_id' => $v['property_room_id'] ?? null,
            'rental_inventory_line_id' => $v['rental_inventory_line_id'] ?? null,
        ]), [], ['photos' => [$request->file('photo')]]);
        $batch->setUserResolver($request->getUserResolver());

        $response = $web->storePhotos($batch, $inv);
        if ($response->getStatusCode() >= 300) {
            $body = (array) $response->getData(true);

            return response()->json($body + ['code' => 'refused'], $response->getStatusCode());
        }

        $photo = RentalInventoryPhoto::withoutGlobalScopes()->where('client_idempotency_key', $key)->firstOrFail();
        $raw = $request->input('captured_at');
        $capturedAt = app(RentalInspectionPhotoCaptureTime::class)->clientTime(is_string($raw) ? substr($raw, 0, 64) : null, ['rental_inventory_id' => $inv->id, 'user_id' => $request->user()->id]);
        if ($capturedAt !== null) {
            // an inventory photo has no capture-time column of its own: created_at IS the captured time (updated_at keeps when it arrived)
            $photo->forceFill(['created_at' => $capturedAt])->saveQuietly();
        }

        return response()->json(['duplicate' => false] + $this->photoPayload($photo->fresh()), 201);
    }

    // ── resolution ────────────────────────────────────────────────────────────────────

    /** AgencyScope 404 -> own/branch/agency 403 -> archived 410. */
    private function findInventory(int $id): RentalInventory
    {
        $inventory = RentalInventory::withTrashed()->find($id);
        abort_if($inventory === null, 404);
        $this->guardRentalRecordScope($inventory, 'rental_inventories', $inventory->property?->branch_id);
        if ($inventory->trashed()) {
            abort($this->archived('This inventory was archived, so nothing more can be added to it.'));
        }

        return $inventory;
    }

    private function archived(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => 'archived'], 410);
    }

    // ── payloads ──────────────────────────────────────────────────────────────────────

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
    private function listRow(RentalInventory $i): array
    {
        return [
            'id' => $i->id,
            'status' => $i->status,
            'status_label' => ucfirst(str_replace('_', ' ', (string) $i->status)),
            'property' => ['id' => $i->property_id, 'address' => $i->property?->buildDisplayAddress()],
            'lease_id' => $i->lease_id,
            'tenant_names' => $i->lease?->tenantNames(),
            'created_by' => $i->createdBy ? ['id' => $i->createdBy->id, 'name' => $i->createdBy->name] : null,
            'lines_count' => (int) $i->lines_count,
            'created_at' => $this->iso($i->created_at),
            'completed_at' => $this->iso($i->completed_at),
        ];
    }

    /** @return array<string, mixed> */
    private function photoPayload(RentalInventoryPhoto $p): array
    {
        $p->loadMissing('lines', 'sourceInspectionPhoto');
        $payload = app(RentalRoomPhotoSet::class)->payload($p);

        return [
            'id' => $p->id,
            'inventory_id' => $p->rental_inventory_id,
            'room_id' => $payload['property_room_id'],
            'line_ids' => $payload['lines']->all(),
            'side' => $p->side,
            'from_inspection' => $payload['from_inspection'],
            'url' => self::absoluteUrl($p->storage_path),
            'captured_at' => $this->iso($p->created_at),
            'client_idempotency_key' => $p->client_idempotency_key,
        ];
    }

    /** @return array<string, mixed> */
    private function detailPayload(RentalInventory $i): array
    {
        $i->loadMissing(['property', 'lease.tenants.contact', 'createdBy']);

        $rooms = PropertyRoom::query()->where('property_id', $i->property_id)->where('is_retired', false)->orderBy('sort_order')->orderBy('id')->get();
        $markedEmpty = $i->roomMarks()->pluck('property_room_id')->all();
        $lines = $i->lines()->with('photos')->get();
        $photos = RentalInventoryPhoto::query()->where('rental_inventory_id', $i->id)->usable()->with(['lines', 'sourceInspectionPhoto'])->orderBy('created_at')->orderBy('id')->get();
        $signatures = $i->signatures()->orderBy('id')->get();

        return [
            'id' => $i->id,
            'status' => $i->status,
            'status_label' => ucfirst(str_replace('_', ' ', (string) $i->status)),
            'editable' => $i->isDraft(),
            'property' => ['id' => $i->property_id, 'address' => $i->property?->buildDisplayAddress()],
            'lease_id' => $i->lease_id,
            'tenant_names' => $i->lease?->tenantNames(),
            'created_by' => $i->createdBy ? ['id' => $i->createdBy->id, 'name' => $i->createdBy->name] : null,
            'created_at' => $this->iso($i->created_at),
            'completed_at' => $this->iso($i->completed_at),
            'rooms' => $rooms->map(fn (PropertyRoom $r) => [
                'id' => $r->id, 'label' => $r->label, 'type' => $r->type, 'sort_order' => (int) $r->sort_order, 'marked_empty' => in_array($r->id, $markedEmpty, true),
            ])->values()->all(),
            'unvisited_room_ids' => $i->unvisitedRooms()->pluck('id')->values()->all(),
            'lines' => $lines->map(fn ($l) => [
                'id' => $l->id, 'room_id' => $l->property_room_id, 'room_label' => $l->room_label, 'quantity' => $l->quantity,
                'description' => $l->description, 'condition_key' => $l->condition_key, 'photo_ids' => $l->photos->pluck('id')->values()->all(),
            ])->values()->all(),
            'photos' => $photos->map(fn ($p) => $this->photoPayload($p))->values()->all(),
            'signatures' => $signatures->map(fn ($s) => [
                'id' => $s->id, 'party_role' => $s->party_role, 'party_contact_id' => $s->party_contact_id, 'disposition' => $s->disposition,
                'captured_at' => $this->iso($s->disposition_recorded_at), 'superseded' => $s->superseded_at !== null,
            ])->values()->all(),
            'condition_states' => RentalInventorySetting::conditionStatesFor((int) $i->agency_id),
        ];
    }
}
