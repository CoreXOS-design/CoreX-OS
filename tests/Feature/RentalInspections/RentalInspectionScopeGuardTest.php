<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionScan;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 item 2(b) + follow-on — RentalInspectionController and its four
 * sibling controllers (RentalInspectionRecordingController/
 * ComparisonController/ScanController/PhotoNoteController) all receive a
 * bound RentalInspection (or a record reached through one) with no
 * per-record scope re-check beyond the list screen's own
 * RentalInspection::scopeVisibleTo(). One test per controller, proving
 * AuthorizesRentalRecordScope::guardRentalRecordScope() now guards each.
 * The public, unauthenticated report link (RentalInspectionPublicController)
 * is deliberately NOT covered here — it takes no RentalInspection route
 * parameter at all (token-only lookup) and must keep working with no login.
 *
 * Staging/QA1 merge, 2026-10-04 (Johan's ruling) — expects 404, not 403, on
 * this specific model. RentalInspection::resolveRouteBinding() (Audit M1,
 * Staging's own pre-existing fix, deliberately kept: "nothing Staging
 * protected may become unprotected") already scopes the route-model-binding
 * itself and returns null for an out-of-scope id, which Laravel resolves to
 * a 404 BEFORE the controller method — and guardRentalRecordScope() — ever
 * runs. The record is exactly as unreachable either way; only the status
 * code differs, because the earlier layer already refused it. This is the
 * one rental model with its own resolveRouteBinding() override; Lease/
 * RentalFaultReport/RentalWorkOrder have none, so guardRentalRecordScope()
 * is the first and only guard there and really does answer 403 (see their
 * own *ScopeGuardTest siblings).
 */
final class RentalInspectionScopeGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $own;
    private User $other;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'RI Scope Guard Agency', 'slug' => 'risg-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Branch A']);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => 'rental_inspections.view', 'agency_id' => $this->agency->id],
            ['scope' => 'own'],
        );
        PermissionService::clearCache();

        $this->own = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->other->id,
            'title' => 'Scope guard property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->other->id,
        ]);
    }

    private function inspectionOwnedByOther(string $type = RentalInspection::TYPE_IN): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id,
            'lease_id' => $this->lease->id,
            'type' => $type,
            'created_by_user_id' => $this->other->id,
        ]);
    }

    public function test_inspection_controller_show_refuses_out_of_scope_user(): void
    {
        $inspection = $this->inspectionOwnedByOther();

        $this->actingAs($this->own)
            ->get(route('corex.rental-inspections.show', $inspection))
            ->assertNotFound();
    }

    public function test_recording_controller_guards_out_of_scope_user(): void
    {
        $inspection = $this->inspectionOwnedByOther();

        $this->actingAs($this->own)
            ->post(route('corex.rental-inspections.overall-notes.update', $inspection), ['overall_notes' => 'x'])
            ->assertNotFound();
    }

    public function test_comparison_controller_guards_out_of_scope_user(): void
    {
        $inspection = $this->inspectionOwnedByOther(RentalInspection::TYPE_OUT);

        $this->actingAs($this->own)
            ->get(route('corex.rental-inspections.deposit-comparison', $inspection))
            ->assertNotFound();
    }

    public function test_scan_controller_guards_out_of_scope_user(): void
    {
        $inspection = $this->inspectionOwnedByOther();
        $scan = RentalInspectionScan::create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'rental_inspection_id' => $inspection->id,
            'original_filename' => 'scan.pdf',
            'storage_path' => 'nonexistent/scan.pdf',
            'mime_type' => 'application/pdf',
            'status' => RentalInspectionScan::STATUS_PROCESSING,
            'uploaded_by_user_id' => $this->other->id,
        ]);

        $this->actingAs($this->own)
            ->get(route('corex.rental-inspections.scans.review', [$inspection, $scan]))
            ->assertNotFound();
    }

    public function test_photo_note_controller_guards_out_of_scope_user(): void
    {
        $inspection = $this->inspectionOwnedByOther();
        $photo = RentalInspectionPhoto::create([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'storage_path' => 'nonexistent/photo.jpg',
            'uploaded_by_user_id' => $this->other->id,
        ]);

        $this->actingAs($this->own)
            ->post(route('corex.rental-inspections.photos.notes.store', [$inspection, $photo]), [
                'classification_key' => 'defect',
                'note' => 'Cracked tile',
            ])
            ->assertNotFound();
    }

    public function test_own_scope_user_can_open_their_own_inspection(): void
    {
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id,
            'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $this->own->id,
        ]);

        $this->actingAs($this->own)
            ->get(route('corex.rental-inspections.show', $inspection))
            ->assertOk();
    }
}
