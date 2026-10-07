<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Http\Controllers\CoreX\LeaseController;
use App\Http\Controllers\CoreX\RentalApplicationController;
use App\Http\Controllers\CoreX\RentalFaultReportController;
use App\Http\Controllers\CoreX\RentalInspectionController;
use App\Http\Controllers\CoreX\RentalInspectionDueController;
use App\Http\Controllers\CoreX\RentalWorkOrderController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The CONTROLLER half of RentalDataScopeNoGrantTest. A user whose role has no scope row for a
 * rentals module used to crash the list screens' own controllers (index, the printed "Scope"
 * label, the Due tab's scope helpers) with a TypeError — PermissionService::getDataScope()
 * answers null and clampScope() takes a string ceiling. No scope row means no access: a plain
 * 403, never a 500.
 *
 * The controllers are called DIRECTLY: the route permission middleware would usually refuse
 * first, which is exactly why this crash only shows for a role that passes the route check but
 * has no scope row.
 */
final class RentalListControllersNoGrantTest extends TestCase
{
    use RefreshDatabase;

    private User $noGrantUser;
    private User $grantedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $agency = Agency::create(['name' => 'No Grant Agency', 'slug' => 'ng-' . uniqid()]);
        $branch = Branch::forceCreate(['agency_id' => $agency->id, 'name' => 'Branch A']);

        // Grants table provisioned (so the unseeded test-suite fallback is OFF); the 'viewer' role
        // has no row for any rentals module, the 'agent' role has an 'own' row for each.
        Role::create(['name' => 'viewer', 'label' => 'Viewer', 'agency_id' => $agency->id]);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'viewer', 'permission_key' => 'contacts.view', 'agency_id' => $agency->id],
            ['scope' => 'own'],
        );
        foreach (['leases', 'rental_inspections', 'rental_work_orders', 'rental_fault_reports', 'rental_applications'] as $module) {
            RolePermission::updateOrCreate(
                ['role' => 'agent', 'permission_key' => $module . '.view', 'agency_id' => $agency->id],
                ['scope' => 'own'],
            );
        }
        PermissionService::clearCache();

        $this->noGrantUser = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'viewer']);
        $this->grantedUser = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/rentals-list', 'GET');
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        return $request;
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function indexScreens(): array
    {
        return [
            'leases' => [LeaseController::class, 'index'],
            'inspections' => [RentalInspectionController::class, 'index'],
            'work orders' => [RentalWorkOrderController::class, 'index'],
            'fault reports' => [RentalFaultReportController::class, 'index'],
            'applications' => [RentalApplicationController::class, 'index'],
            'planned inspections (due tab)' => [RentalInspectionDueController::class, 'index'],
            'planned inspections print' => [RentalInspectionDueController::class, 'printList'],
            'planned inspections export' => [RentalInspectionDueController::class, 'export'],
        ];
    }

    #[DataProvider('indexScreens')]
    public function test_a_list_screen_refuses_a_role_with_no_scope_row_with_403_not_a_500(string $controller, string $method): void
    {
        $request = $this->requestFor($this->noGrantUser);

        try {
            app($controller)->{$method}($request);
            $this->fail("{$controller}::{$method}() must refuse a user whose role has no scope row.");
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function scopeLabelHelpers(): array
    {
        return [
            'leases' => [LeaseController::class, 'activeLeaseFiltersSummary'],
            'inspections' => [RentalInspectionController::class, 'activeInspectionFiltersSummary'],
            'work orders' => [RentalWorkOrderController::class, 'activeWorkOrderFiltersSummary'],
            'fault reports' => [RentalFaultReportController::class, 'activeFaultReportFiltersSummary'],
        ];
    }

    #[DataProvider('scopeLabelHelpers')]
    public function test_the_printed_scope_label_refuses_a_role_with_no_scope_row(string $controller, string $method): void
    {
        $request = $this->requestFor($this->noGrantUser);
        $helper = new ReflectionMethod($controller, $method);

        try {
            $helper->invoke(app($controller), $request);
            $this->fail("{$controller}::{$method}() must refuse a user whose role has no scope row.");
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    #[DataProvider('scopeLabelHelpers')]
    public function test_the_printed_scope_label_still_reads_own_for_a_role_that_has_a_scope_row(string $controller, string $method): void
    {
        $request = $this->requestFor($this->grantedUser);
        $helper = new ReflectionMethod($controller, $method);

        $out = $helper->invoke(app($controller), $request);

        $this->assertSame('Own', $out['Scope']);
    }

    public function test_the_due_tab_scope_helpers_refuse_a_role_with_no_scope_row_and_still_work_for_a_granted_role(): void
    {
        $controller = app(RentalInspectionDueController::class);

        $scopes = new ReflectionMethod($controller, 'scopes');
        $propertyScope = new ReflectionMethod($controller, 'propertyScope');

        // Granted: normal answer, own only.
        [$max, $resolved, $options] = $scopes->invoke($controller, $this->requestFor($this->grantedUser));
        $this->assertSame(['own', 'own', ['own']], [$max, $resolved, $options]);

        // Not granted: refused.
        foreach ([
            fn () => $scopes->invoke($controller, $this->requestFor($this->noGrantUser)),
            fn () => $propertyScope->invoke($controller, \App\Models\Property::query(), $this->noGrantUser, null),
        ] as $call) {
            try {
                $call();
                $this->fail('A no-scope role must be refused.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }
}
