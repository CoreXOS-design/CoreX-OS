<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Cross-cutting rentals audit, 2026-10-08 (qa1-cc2) — the permission / scoping / navigation gaps found in
 * the audit report /tmp/qa1-cc2-rentals-crosscut-2026-10-08.md and fixed in the same commit.
 */
final class RentalsCrosscutAuditTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Crosscut Agency', 'slug' => 'cc-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Main']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
    }

    private function agentWith(array $grants): User
    {
        foreach ($grants as $key => $scope) {
            RolePermission::updateOrCreate(
                ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
                ['scope' => $scope],
            );
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    private function leaseBy(User $creator): Lease
    {
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $creator->id,
            'title' => 'Crosscut property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);

        return Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subMonth()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $creator->id,
        ]);
    }

    public function test_rental_document_types_screen_needs_the_settings_permission(): void
    {
        $nobody = $this->agentWith(['rental_applications.view' => 'own']);
        $this->actingAs($nobody)->get(route('rental.settings.document-types.index'))->assertForbidden();
        $this->actingAs($nobody)->post(route('rental.settings.document-types.store'), ['name' => 'Anything'])->assertForbidden();

        $settings = $this->agentWith(['access_settings' => 'all']);
        $this->actingAs($settings)->get(route('rental.settings.document-types.index'))->assertOk();
    }

    public function test_docuperfect_lease_renew_and_terminate_need_the_lease_permissions_not_just_docuperfect_access(): void
    {
        // Asserted on the route table: model binding 404s before a permission middleware can answer a made-up id.
        $expected = ['docuperfect.leases.renew' => 'permission:leases.renew', 'docuperfect.leases.terminate' => 'permission:leases.cancel'];
        foreach ($expected as $name => $middleware) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains($middleware, $route->gatherMiddleware(), "{$name} must carry {$middleware}");
        }
    }

    public function test_mobile_job_card_writes_require_the_job_card_write_permission(): void
    {
        foreach (['update', 'tasks.tick', 'photos.store'] as $suffix) {
            $route = Route::getRoutes()->getByName('v1.mobile.rental-job-cards.' . $suffix);
            self::assertNotNull($route, $suffix);
            self::assertContains('permission:rental_job_cards.create', $route->gatherMiddleware(), "mobile job-card {$suffix} must be write-gated like the web screen");
        }
    }

    public function test_notices_follow_the_lease_own_scope_and_block_direct_url_access_by_id(): void
    {
        $me = $this->agentWith(['rental_notices.create' => 'all', 'leases.view' => 'own']);
        $someoneElse = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $mine = $this->leaseBy($me);
        $theirs = $this->leaseBy($someoneElse);

        $this->actingAs($me)->get(route('corex.leases.notices.create', $mine))->assertOk();
        $this->actingAs($me)->get(route('corex.leases.notices.create', $theirs))->assertForbidden();
        $this->actingAs($me)->post(route('corex.leases.notices.store', $theirs), [])->assertForbidden();
    }

    public function test_work_order_completion_and_emergency_approval_are_in_the_fresh_agency_defaults(): void
    {
        foreach (['branch_manager', 'agent'] as $role) {
            $include = config("corex-permissions.role_defaults.{$role}.include");
            foreach (['rental_work_orders.manage_completion', 'rental_work_orders.record_emergency_approval'] as $key) {
                self::assertContains($key, $include, "{$role} default must carry {$key} (existing agencies already got it by migration)");
            }
        }
    }
}
