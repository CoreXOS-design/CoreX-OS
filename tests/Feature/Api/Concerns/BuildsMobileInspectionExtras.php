<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Concerns;

use App\Models\Branch;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * AT-508 P5/P6 — extras on top of BuildsMobileRentalsWorld for the inspection / inventory tests: a checklist (one room, two items, one
 * meter), colleagues for the own / branch / other-agency scoping cases, a real JPEG for the photo routes, and the permission grants.
 * Needs BuildsMobileRentalsWorld on the same test class (and buildWorld() already called).
 */
trait BuildsMobileInspectionExtras
{
    protected const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected const INSPECTION_KEYS = [
        'rental_inspections.view', 'rental_inspections.create', 'rental_inspections.resolve_discrepancy', 'rental_inspections.record_attendance',
        'rental_inspections.sign_on_behalf', 'rental_inventories.view', 'rental_inventories.create', 'leases.view', 'properties.view', 'access_properties',
    ];

    protected PropertyRoom $room;
    protected RentalInspectionItem $ceiling;
    protected RentalInspectionItem $floor;
    protected RentalInspectionItem $meter;
    protected User $colleague;
    protected User $otherBranchManager;
    protected User $outsider;

    protected function buildInspectionExtras(): void
    {
        config(['app.url' => 'https://corex.test']);
        Storage::fake('local');
        Storage::fake('public');
        \App\Models\DocumentType::firstOrCreate(['slug' => 'inspection_report'], ['label' => 'Inspection Report', 'is_active' => true]);
        // keep the Routine inspection the light-weight check the tests lean on; the full In/Out rules are exercised separately
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false]);

        $this->room = PropertyRoom::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'bedroom', 'label' => 'Bedroom 1', 'source' => 'manual', 'sort_order' => 1]);
        $this->ceiling = $this->item('Ceiling', 1, $this->room);
        $this->floor = $this->item('Floor', 2, $this->room);
        $this->meter = RentalInspectionItem::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'kind' => RentalInspectionItem::KIND_METER, 'label' => 'Electricity meter', 'sort_order' => 3]);

        $this->colleague = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Other branch', 'code' => 'O-' . $this->agency->id, 'is_active' => true]);
        $this->otherBranchManager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $otherBranch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $this->outsider = User::factory()->create(['agency_id' => $this->other->id, 'role' => 'agent', 'is_active' => true]);
    }

    protected function item(string $label, int $order, ?PropertyRoom $room = null): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room?->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => $label, 'space_type' => $room?->type, 'sort_order' => $order,
        ]);
    }

    /** Real permission rows, so own / branch scoping and "permission denied" are in force (an unseeded test DB holds nobody to a permission). */
    protected function grantEveryone(): void
    {
        $this->grantScope($this->agency, 'agent', 'own', self::INSPECTION_KEYS);
        $this->grantScope($this->agency, 'branch_manager', 'branch', self::INSPECTION_KEYS);
        $this->grantScope($this->other, 'agent', 'all', self::INSPECTION_KEYS);
        // a role that may look but not record
        $this->grantScope($this->agency, 'viewer', 'all', ['rental_inspections.view', 'rental_inventories.view', 'properties.view', 'access_properties', 'leases.view']);
    }

    /** An inspection written straight to the table (not via the API) so a test controls who created it and when. */
    protected function inspectionRow(string $type = RentalInspection::TYPE_AD_HOC, array $attrs = []): RentalInspection
    {
        return RentalInspection::create($attrs + [
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type, 'created_by_user_id' => $this->agent->id,
        ]);
    }

    protected function uuid(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    protected function jpeg(string $name = 'p.jpg'): UploadedFile
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        $path = tempnam(sys_get_temp_dir(), 'ri') . '.jpg';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    protected function base(string $suffix = ''): string
    {
        return '/api/v1/mobile/rental-inspections' . $suffix;
    }
}
