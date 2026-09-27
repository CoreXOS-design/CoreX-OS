<?php

/**
 * Throwaway fixture creator/cleaner for the Inspections-tab section of
 * scripts/rental-click-through.mjs.
 *
 * Written 2026-09-27 (Johan's own instruction: "add the Inspections
 * screen's controls to it... a test proving the endpoint works is not the
 * same claim as an agent can reach it") after the same root cause that
 * shipped this file's original 2026-09-15 incident (a boolean-attribute
 * binding backed by `undefined`) shipped a second time on the Inspections
 * tab, unnoticed for five days, because nothing here ever clicked those
 * controls. Same discipline as rental-click-through-fixture.php: entirely
 * throwaway, created fresh per run, soft-deleted (never hard-deleted) in a
 * `finally` block regardless of pass/fail. Never touches rental-smoke.mjs's
 * persistent fixtures or any of Johan's own real properties/inspections.
 *
 * ROOM/ITEM LAYOUT — deliberately spread across separate rooms so each
 * checked control has its OWN unrecorded item to act on, and testing one
 * control does not consume the precondition a LATER check needs (the same
 * "App A vs App B vs App C" separation the rental-applications fixture
 * already uses for one-way transitions):
 *   - Bedroom 1 / Ceiling — recorded on BOTH predecessor and tail, each
 *     side with its own UNPAIRED photo tagged to the same room+item key —
 *     the one unambiguous auto-pair candidate this fixture provides.
 *   - Bedroom 1 / Walls   — unrecorded on the tail — for the per-room
 *     "All Good" check.
 *   - Bathroom 1 / Floor  — unrecorded on the tail — for the per-room
 *     "Mark room N/A" check (kept on a SEPARATE room from Walls so marking
 *     Bedroom 1 good does not also consume Bathroom 1's own precondition).
 *   - Garage / Door       — unrecorded on the tail, left untouched by both
 *     per-room checks above — for "All good — whole inspection", which
 *     must run AFTER the two per-room checks (it would otherwise have
 *     nothing left to prove once they'd already filled every other item).
 *
 * Usage:
 *   php8.2 rental-inspection-click-through-fixture.php --app-root=/corex-qa1 --create
 *     -> prints one JSON object to stdout with every id the .mjs script needs
 *   php8.2 rental-inspection-click-through-fixture.php --app-root=/corex-qa1 --cleanup=<json from --create>
 *     -> soft-deletes everything named in that JSON (idempotent)
 */

$opts = getopt('', ['app-root:', 'create', 'cleanup:']);
if (empty($opts['app-root']) || (!isset($opts['create']) && empty($opts['cleanup']))) {
    fwrite(STDERR, "Usage: --app-root=<path> (--create | --cleanup=<json>)\n");
    exit(2);
}

$appRoot = rtrim($opts['app-root'], '/');
chdir($appRoot);
require $appRoot . '/vendor/autoload.php';
$app = require $appRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\User;

if (isset($opts['create'])) {
    $stamp = 'insp-clickthrough-' . date('YmdHis') . '-' . substr(uniqid(), -5);

    $agency = Agency::create(['name' => 'Inspection Gate Co', 'slug' => $stamp]);
    $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
    $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'name' => 'Gate Agent']);

    $property = Property::forceCreate([
        'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
        'title' => 'Gate Fixture Property', 'status' => 'active', 'listing_type' => 'rental',
    ]);
    $lease = Lease::create([
        'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
        'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000,
        'start_date' => now()->subMonth(), 'created_by_user_id' => $agent->id,
    ]);

    $makeRoom = fn (string $type, string $label) => PropertyRoom::create([
        'agency_id' => $agency->id, 'property_id' => $property->id, 'type' => $type, 'label' => $label,
        'source' => 'manual', 'created_by_user_id' => $agent->id,
    ]);
    $bedroom = $makeRoom('Bedroom', 'Bedroom 1');
    $bathroom = $makeRoom('Bathroom', 'Bathroom 1');
    $garage = $makeRoom('Garage', 'Garage');

    $makeItem = fn (PropertyRoom $room, string $label) => RentalInspectionItem::create([
        'agency_id' => $agency->id, 'property_id' => $property->id, 'property_room_id' => $room->id,
        'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'created_by_user_id' => $agent->id,
    ]);
    $ceiling = $makeItem($bedroom, 'Ceiling');
    $walls = $makeItem($bedroom, 'Walls');
    $floor = $makeItem($bathroom, 'Floor');
    $door = $makeItem($garage, 'Door');

    // Predecessor (in, completed) -> tail (out, draft), a real explicit
    // chain link — the same shape RentalInspection::startNext() produces —
    // so chainTailFor()/previousInspection() resolve this fixture exactly
    // like a real property, with no date-inference fallback needed.
    $predecessor = RentalInspection::create([
        'agency_id' => $agency->id, 'lease_id' => $lease->id, 'type' => RentalInspection::TYPE_IN,
        'status' => RentalInspection::STATUS_COMPLETED, 'created_by_user_id' => $agent->id,
    ]);
    $tail = RentalInspection::create([
        'agency_id' => $agency->id, 'lease_id' => $lease->id, 'type' => RentalInspection::TYPE_OUT,
        'status' => RentalInspection::STATUS_DRAFT, 'previous_inspection_id' => $predecessor->id,
        'created_by_user_id' => $agent->id,
    ]);

    $recordCeiling = fn (RentalInspection $insp) => RentalInspectionObservation::record([
        'agency_id' => $agency->id, 'rental_inspection_id' => $insp->id, 'rental_inspection_item_id' => $ceiling->id,
        'observed_by_user_id' => $agent->id, 'condition' => RentalInspectionObservation::CONDITION_GOOD,
        'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
    ]);
    $predObs = $recordCeiling($predecessor);
    $tailObs = $recordCeiling($tail);

    // One UNPAIRED photo per side, same room+item key, nothing else on
    // either side sharing that key — the exact "exactly one candidate per
    // side" shape RentalInspectionPhotoAutoPairService requires to propose
    // a pair at all (.ai/specs/rental-inspections.md §24.5).
    $makePhoto = fn (RentalInspection $insp, RentalInspectionObservation $obs) => RentalInspectionPhoto::create([
        'agency_id' => $agency->id, 'rental_inspection_id' => $insp->id,
        'rental_inspection_observation_id' => $obs->id, 'property_room_id' => $bedroom->id,
        'storage_path' => '/gate-fixture/ceiling.jpg', 'uploaded_by_user_id' => $agent->id,
    ]);
    $predPhoto = $makePhoto($predecessor, $predObs);
    $tailPhoto = $makePhoto($tail, $tailObs);

    echo json_encode([
        'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_user_id' => $agent->id,
        'property_id' => $property->id, 'lease_id' => $lease->id,
        'bedroom_id' => $bedroom->id, 'bathroom_id' => $bathroom->id, 'garage_id' => $garage->id,
        'ceiling_item_id' => $ceiling->id, 'walls_item_id' => $walls->id,
        'floor_item_id' => $floor->id, 'door_item_id' => $door->id,
        'predecessor_id' => $predecessor->id, 'tail_id' => $tail->id,
        'predecessor_photo_id' => $predPhoto->id, 'tail_photo_id' => $tailPhoto->id,
    ]) . PHP_EOL;
    exit(0);
}

if (!empty($opts['cleanup'])) {
    $ids = json_decode($opts['cleanup'], true);
    if (!is_array($ids)) {
        fwrite(STDERR, "Could not parse --cleanup JSON\n");
        exit(2);
    }

    // Soft delete only (non-negotiable #1). Children first, parents last —
    // none of these deletes cascade, so order avoids leaving a child
    // pointed at an already-trashed parent id with no way to find it again
    // (harmless either way since everything here is agency-scoped throwaway
    // data, but this is the honest, deliberate order, not an accident).
    // The auto-pair check may have created a real match group linking the
    // two fixture photos — not named in --create's own output (it doesn't
    // exist until the check runs), so found here by property instead.
    if (!empty($ids['property_id'])) {
        \App\Models\RentalInspectionPhotoMatchGroup::withTrashed()->where('property_id', $ids['property_id'])
            ->get()->each(function ($group) {
                $group->members()->withTrashed()->get()->each->delete();
                $group->delete();
            });
    }
    if (!empty($ids['predecessor_photo_id'])) RentalInspectionPhoto::withTrashed()->find($ids['predecessor_photo_id'])?->delete();
    if (!empty($ids['tail_photo_id'])) RentalInspectionPhoto::withTrashed()->find($ids['tail_photo_id'])?->delete();
    // RentalInspectionObservation is deliberately NOT soft-deletable
    // anywhere in this module (§3.3 — immutable, append-only, evidentiary)
    // — left in place, same as every other test/fixture in this codebase
    // tolerates an orphaned observation against a since-removed inspection;
    // not a new problem this script introduces.
    foreach (['tail_id', 'predecessor_id'] as $key) {
        if (!empty($ids[$key])) RentalInspection::withTrashed()->find($ids[$key])?->delete();
    }
    // RentalInspectionItem and PropertyRoom are NOT soft-deletable anywhere
    // in this module (checked directly, not assumed — neither model uses
    // the SoftDeletes trait; items retire via is_retired, §3.3, and rooms
    // have no delete path at all) — left in place under the now-trashed
    // property/agency, same reasoning as the observations above.
    if (!empty($ids['lease_id'])) Lease::withTrashed()->find($ids['lease_id'])?->delete();
    if (!empty($ids['property_id'])) Property::withTrashed()->find($ids['property_id'])?->delete();
    if (!empty($ids['agent_user_id'])) {
        try {
            User::withTrashed()->find($ids['agent_user_id'])?->delete();
        } catch (\App\Exceptions\LastAdminException $e) {
            fwrite(STDERR, "Left in place (last admin guard): user {$ids['agent_user_id']}\n");
        }
    }
    if (!empty($ids['branch_id'])) Branch::withTrashed()->find($ids['branch_id'])?->delete();
    if (!empty($ids['agency_id'])) Agency::withTrashed()->find($ids['agency_id'])?->delete();

    echo "CLEANED\n";
    exit(0);
}
