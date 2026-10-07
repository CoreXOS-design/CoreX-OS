<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalInspectionPlannedDate;
use App\Models\RentalInventory;
use App\Models\RentalJobCard;
use App\Models\RentalTakeOnImportRun;
use App\Models\RentalWorkOrder;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * A user whose role has NO scope row for a rentals module used to crash every rentals
 * scopeVisibleTo() with a TypeError (HTTP 500): PermissionService::getDataScope() answers null
 * for "no access" and clampScope() demands a string ceiling. No scope means no access — a plain
 * 403, never a 500, and never a silent fall-open.
 */
final class RentalDataScopeNoGrantTest extends TestCase
{
    use RefreshDatabase;

    private User $noGrantUser;
    private User $grantedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $agency = Agency::create(['name' => 'No Grant Agency', 'slug' => 'ng-' . uniqid()]);
        $branch = Branch::forceCreate(['agency_id' => $agency->id, 'name' => 'Branch A']);

        // The grants table is provisioned (so the unseeded test-suite fallback is OFF), but the
        // 'viewer' role has no row for any rentals module — the real-world "no scope rows" shape.
        Role::create(['name' => 'viewer', 'label' => 'Viewer', 'agency_id' => $agency->id]);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);
        foreach (['viewer' => 'contacts.view', 'agent' => 'rental_inspections.view'] as $role => $key) {
            RolePermission::updateOrCreate(
                ['role' => $role, 'permission_key' => $key, 'agency_id' => $agency->id],
                ['scope' => 'own'],
            );
        }
        PermissionService::clearCache();

        $this->noGrantUser = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'viewer']);
        $this->grantedUser = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    /** @return array<string, array{0: class-string}> */
    public static function visibleToModels(): array
    {
        return [
            'inspections' => [RentalInspection::class],
            'planned inspection dates' => [RentalInspectionPlannedDate::class],
            'leases' => [Lease::class],
            'work orders' => [RentalWorkOrder::class],
            'fault reports' => [RentalFaultReport::class],
            'job cards' => [RentalJobCard::class],
            'inventories' => [RentalInventory::class],
            'take-on import runs' => [RentalTakeOnImportRun::class],
        ];
    }

    #[DataProvider('visibleToModels')]
    public function test_a_role_with_no_scope_row_is_refused_with_403_not_a_500(string $model): void
    {
        $this->actingAs($this->noGrantUser);

        try {
            $model::query()->visibleTo($this->noGrantUser);
            $this->fail("{$model}::scopeVisibleTo() must refuse a user whose role has no scope row.");
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_a_role_that_has_a_scope_row_still_gets_its_normal_scoped_query(): void
    {
        $this->actingAs($this->grantedUser);

        // 'own' for rental_inspections — a real query comes back, not a refusal.
        $sql = RentalInspection::query()->visibleTo($this->grantedUser)->toSql();

        $this->assertStringContainsString('created_by_user_id', $sql);
    }

    public function test_the_inspection_page_by_direct_url_is_a_clean_403_for_a_role_with_no_scope_row(): void
    {
        $response = $this->actingAs($this->noGrantUser)->get(route('corex.rental-inspections.index'));

        $this->assertContains($response->getStatusCode(), [302, 403], 'Never a 500 for a role with no rentals scope.');
    }
}
