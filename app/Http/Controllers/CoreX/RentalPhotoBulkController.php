<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\RentalInspection;
use App\Models\RentalInventory;
use App\Services\Rentals\RentalPhotoBulkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-inventory.md section 29 - the bulk photo manager on the inventory screen and the inspection screen: a page of photos, tick /
 * "select all on this page" / "select all N", delete selected, delete all, restore, and move (inventory <-> inspection, or room to room).
 *
 * Gating: reading needs the screen's own view permission (the route group); every change needs the screen's own edit permission
 * (rental_inventories.create / rental_inspections.create - the same keys as uploading and archiving a single photo), and a move into the OTHER
 * module needs that module's edit permission too. Own / branch / agency scoping is the models' own scopeVisibleTo(), applied to the bound record
 * (a record the user may not see answers 404) and to every photo query (a photo of another inventory/inspection or agency can never be named).
 * A signed / completed / cancelled inventory or inspection answers 409 through the existing not-editable exceptions.
 */
class RentalPhotoBulkController extends Controller
{
    use EnforcesRecordVisibility;

    public function __construct(private readonly RentalPhotoBulkService $photos)
    {
    }

    // ----------------------------------------------------------------------------------------------- inventory

    public function inventoryIndex(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        $f = $this->filters($request);
        $page = $this->photos->page($this->photos->inventoryQuery($rentalInventory, $f), (int) $request->query('page', 1), 'inventory');

        return response()->json($page + $this->context($rentalInventory->property_id, $rentalInventory->isDraft(), $request, 'rental_inventories.create', $rentalInventory->isDraft() ? null : 'This inventory is signed or closed - its photos can no longer be changed.')
            + ['trashed_total' => $this->photos->inventoryQuery($rentalInventory, ['deleted' => true])->count()]);
    }

    public function inventoryTargets(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        $user = $request->user();

        return response()->json([
            'inspections' => $user->hasPermission('rental_inspections.view') && $user->hasPermission('rental_inspections.create') ? $this->photos->inspectionTargets($rentalInventory->property_id, $user) : [],
            'rooms' => $this->photos->rooms($rentalInventory->property_id),
        ]);
    }

    public function inventoryAct(Request $request, RentalInventory $rentalInventory): JsonResponse
    {
        $this->assertVisible($request, $rentalInventory);
        $rentalInventory->assertEditable();
        $in = $this->validated($request);
        $user = $request->user();

        $q = $this->photos->inventoryQuery($rentalInventory, $in['filters'] + ['deleted' => $in['action'] === 'restore']);
        $sel = $this->photos->resolve($q, $in['scope'], $in['ids']);
        $this->guardSelection($sel, $in);

        if ($in['action'] === 'delete') {
            $n = $this->photos->deleteInventory($rentalInventory, $sel['ids'], $user);

            return $this->done("{$n} photo(s) deleted. You can bring them back with Show deleted.", $n);
        }
        if ($in['action'] === 'restore') {
            $n = $this->photos->restoreInventory($rentalInventory, $sel['ids'], $user);

            return $this->done("{$n} photo(s) restored.", $n);
        }

        // move to an inspection of the same property
        abort_unless($user->hasPermission('rental_inspections.create'), 403);
        $target = RentalInspection::query()->visibleTo($user)->whereKey((int) ($in['target_id'] ?? 0))->first();
        abort_if($target === null || (int) $target->property_id !== (int) $rentalInventory->property_id || (int) $target->agency_id !== (int) $rentalInventory->agency_id, 404);
        $this->contentEditable($target);
        $r = $this->photos->moveInventoryToInspection($rentalInventory, $target, $sel['ids'], $in['room'], $user);

        return $this->done($r['moved'] . ' photo(s) moved to ' . RentalInspection::typeName($target->type) . ' #' . $target->id . '.' . ($r['skipped_shared'] ? ' ' . $r['skipped_shared'] . ' shared with an inspection already stayed where they are.' : ''), $r['moved']);
    }

    // ----------------------------------------------------------------------------------------------- inspection

    public function inspectionIndex(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->assertVisible($request, $rentalInspection);
        $f = $this->filters($request);
        $page = $this->photos->page($this->photos->inspectionQuery($rentalInspection, $f), (int) $request->query('page', 1), 'inspection');
        $reason = null;
        try {
            $rentalInspection->assertContentEditable();
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        return response()->json($page + $this->context($rentalInspection->property_id, $reason === null, $request, 'rental_inspections.create', $reason)
            + ['trashed_total' => $this->photos->inspectionQuery($rentalInspection, ['deleted' => true])->count()]);
    }

    public function inspectionTargets(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->assertVisible($request, $rentalInspection);
        $user = $request->user();

        return response()->json([
            'inventories' => $user->hasPermission('rental_inventories.view') && $user->hasPermission('rental_inventories.create') ? $this->photos->inventoryTargets($rentalInspection->property_id, $user) : [],
            'rooms' => $this->photos->rooms($rentalInspection->property_id),
        ]);
    }

    public function inspectionAct(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->assertVisible($request, $rentalInspection);
        $this->contentEditable($rentalInspection);
        $in = $this->validated($request);
        $user = $request->user();

        $q = $this->photos->inspectionQuery($rentalInspection, $in['filters'] + ['deleted' => $in['action'] === 'restore']);
        $sel = $this->photos->resolve($q, $in['scope'], $in['ids']);
        $this->guardSelection($sel, $in);

        if ($in['action'] === 'delete') {
            $n = $this->photos->deleteInspection($rentalInspection, $sel['ids'], $user);

            return $this->done("{$n} photo(s) deleted. You can bring them back with Show deleted.", $n);
        }
        if ($in['action'] === 'restore') {
            $n = $this->photos->restoreInspection($rentalInspection, $sel['ids'], $user);

            return $this->done("{$n} photo(s) restored.", $n);
        }

        if (($in['target_type'] ?? 'room') === 'inventory') {
            abort_unless($user->hasPermission('rental_inventories.create'), 403);
            $target = RentalInventory::query()->visibleTo($user)->whereKey((int) ($in['target_id'] ?? 0))->first();
            abort_if($target === null || (int) $target->property_id !== (int) $rentalInspection->property_id || (int) $target->agency_id !== (int) $rentalInspection->agency_id, 404);
            $target->assertEditable();
            $r = $this->photos->moveInspectionToInventory($rentalInspection, $target, $sel['ids'], $in['room'], $user);

            return $this->done($r['moved'] . ' photo(s) moved to inventory #' . $target->id . '.', $r['moved']);
        }

        $n = $this->photos->moveInspectionToRoom($rentalInspection, $sel['ids'], $in['room'], $user);

        return $this->done("{$n} photo(s) moved.", $n);
    }

    // ----------------------------------------------------------------------------------------------- shared

    /** A signed / completed / cancelled / archived inspection takes no photo changes: the same 409 the inspection's own recording screen answers. */
    private function contentEditable(RentalInspection $inspection): void
    {
        try {
            $inspection->assertContentEditable();
        } catch (\App\Exceptions\RentalInspectionNotRecordableException $e) {
            abort(409, $e->getMessage());
        }
    }

    /** @return array{room:?string,from:?string,to:?string,deleted:bool,sort:string} */
    private function filters(Request $request): array
    {
        return [
            'room' => $request->query('room') !== null ? (string) $request->query('room') : null,
            'from' => $request->query('from') ?: null,
            'to' => $request->query('to') ?: null,
            'deleted' => $request->boolean('deleted'),
            'sort' => $request->query('sort') === 'oldest' ? 'oldest' : 'newest',
        ];
    }

    private function context(int $propertyId, bool $editable, Request $request, string $editPermission, ?string $lockedReason): array
    {
        return [
            'rooms' => $this->photos->rooms($propertyId),
            'can_edit' => $editable && $request->user()->hasPermission($editPermission),
            'locked_reason' => $lockedReason ?? ($request->user()->hasPermission($editPermission) ? null : 'You can look at these photos but not change them.'),
            'max_per_action' => RentalPhotoBulkService::MAX_PER_ACTION,
        ];
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $v = $request->validate([
            'action' => ['required', 'in:delete,restore,move'],
            'scope' => ['required', 'in:ids,filtered'],
            'ids' => ['nullable', 'array', 'max:' . RentalPhotoBulkService::MAX_PER_ACTION],
            'ids.*' => ['integer'],
            'expected_count' => ['nullable', 'integer', 'min:0'],
            'target_type' => ['nullable', 'in:inspection,inventory,room'],
            'target_id' => ['nullable', 'integer'],
            'room' => ['nullable', 'string', 'max:12'],
            'filters' => ['nullable', 'array'],
        ]);
        $f = (array) ($v['filters'] ?? []);

        return array_merge($v, [
            'ids' => (array) ($v['ids'] ?? []),
            'room' => $v['room'] ?? 'keep',
            'filters' => [
                'room' => isset($f['room']) ? (string) $f['room'] : null,
                'from' => $f['from'] ?? null,
                'to' => $f['to'] ?? null,
                'sort' => ($f['sort'] ?? '') === 'oldest' ? 'oldest' : 'newest',
            ],
        ]);
    }

    /** Nothing selected, or the matching set changed since the screen showed its count, stops here before anything is touched. */
    private function guardSelection(array $sel, array $in): void
    {
        if ($sel['ids'] === []) {
            abort(422, 'No photos selected.');
        }
        if ($in['scope'] === 'filtered' && isset($in['expected_count']) && (int) $in['expected_count'] !== $sel['total']) {
            abort(409, "The number of photos changed (now {$sel['total']}, you saw {$in['expected_count']}) - nothing was changed. Reload and try again.");
        }
    }

    private function done(string $message, int $n): JsonResponse
    {
        return response()->json(['message' => $message, 'affected' => $n]);
    }
}
