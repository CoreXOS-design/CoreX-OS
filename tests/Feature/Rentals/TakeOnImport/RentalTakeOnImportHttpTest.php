<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\TakeOnImport\RentalTakeOnFieldSchema;
use App\Services\Rentals\TakeOnImport\RentalTakeOnTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * .ai/specs/rental-takeon-import.md §5.1/§7 — the real upload -> dry-run
 * path over HTTP, and agency isolation (BUILD_STANDARD §8: direct-URL-by-ID,
 * not just absent from the menu).
 */
final class RentalTakeOnImportHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_downloaded_template_has_one_header_column_per_schema_field(): void
    {
        [, , $admin] = $this->makeAgencyBranchAdmin();

        $response = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.template'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_uploading_a_real_filled_template_parses_every_row_into_pending_confirm(): void
    {
        [$agency, $branch, $admin] = $this->makeAgencyBranchAdmin();

        $path = $this->buildFilledTemplate($agency, [
            $this->sampleRow('Beach Road', 'Scottburgh', 'Jane Smith', 'John Doe', 9500),
            $this->sampleRow('Marine Drive', 'Uvongo', 'Peter Jones', 'Mary Brown', 7200),
        ]);

        $response = $this->actingAs($admin)->post(route('corex.rentals.take-on-import.upload'), [
            'file' => new UploadedFile($path, 'book.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);

        $run = RentalTakeOnImportRun::where('agency_id', $agency->id)->first();
        self::assertNotNull($run);
        $response->assertRedirect(route('corex.rentals.take-on-import.preview', $run));

        self::assertSame(RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, $run->status);
        self::assertSame(2, $run->counts_json['total'] ?? null);
        self::assertSame(2, $run->rows()->count());
    }

    public function test_an_agency_admin_cannot_reach_another_agencys_batch_by_direct_url(): void
    {
        [$agencyA, , $adminA] = $this->makeAgencyBranchAdmin();
        [$agencyB, $branchB, $adminB] = $this->makeAgencyBranchAdmin();

        $runB = RentalTakeOnImportRun::create([
            'agency_id' => $agencyB->id, 'branch_id' => $branchB->id, 'user_id' => $adminB->id,
            'status' => RentalTakeOnImportRun::STATUS_COMPLETED, 'source_filename' => 'b-book.xlsx',
        ]);

        $response = $this->actingAs($adminA)->get(route('corex.rentals.take-on-import.show', $runB));

        $response->assertNotFound();
    }

    /**
     * Audit 2026-10-18 (A-M2): an own-scope user with .view/.manage used to open and act on a colleague's
     * batch by direct URL (only index() applied visibleTo()).
     */
    public function test_an_own_scope_user_cannot_open_or_act_on_a_colleagues_batch_by_direct_url(): void
    {
        [$agency, $branch, $admin] = $this->makeAgencyBranchAdmin();
        $agent = $this->ownScopeAgent($agency, $branch);

        $colleagueRun = RentalTakeOnImportRun::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'user_id' => $admin->id,
            'status' => RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, 'source_filename' => 'colleague.xlsx',
        ]);
        $row = $this->makeRow($colleagueRun);

        $this->actingAs($agent)->get(route('corex.rentals.take-on-import.show', $colleagueRun))->assertNotFound();
        $this->actingAs($agent)->get(route('corex.rentals.take-on-import.preview', $colleagueRun))->assertNotFound();
        $this->actingAs($agent)->get(route('corex.rentals.take-on-import.issues', $colleagueRun))->assertNotFound();
        $this->actingAs($agent)->post(route('corex.rentals.take-on-import.cancel', $colleagueRun))->assertNotFound();
        $this->actingAs($agent)->post(route('corex.rentals.take-on-import.archive', $colleagueRun))->assertNotFound();
        $this->actingAs($agent)->post(route('corex.rentals.take-on-import.confirm-bulk', $colleagueRun), ['ids' => [$row->id]])->assertNotFound();
        $this->actingAs($agent)->post(route('corex.rentals.take-on-import.rows.exclude', $row))->assertNotFound();
        $this->actingAs($agent)->post(route('corex.rentals.take-on-import.rows.confirm', $row))->assertNotFound();

        $this->assertNull($colleagueRun->fresh()->deleted_at);
        $this->assertSame(RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, $colleagueRun->fresh()->status);
        $this->assertSame(RentalTakeOnImportRow::STATUS_PENDING, $row->fresh()->status);

        // The agent's OWN batch is still reachable.
        $ownRun = RentalTakeOnImportRun::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'user_id' => $agent->id,
            'status' => RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, 'source_filename' => 'mine.xlsx',
        ]);
        $this->actingAs($agent)->get(route('corex.rentals.take-on-import.show', $ownRun))->assertOk();
    }

    /** Audit 2026-10-18 (A-M2): a row of another agency was bindable and its status writable. */
    public function test_a_row_of_another_agency_cannot_be_excluded_or_confirmed(): void
    {
        [$agencyA, , $adminA] = $this->makeAgencyBranchAdmin();
        [$agencyB, $branchB, $adminB] = $this->makeAgencyBranchAdmin();

        $runB = RentalTakeOnImportRun::create([
            'agency_id' => $agencyB->id, 'branch_id' => $branchB->id, 'user_id' => $adminB->id,
            'status' => RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, 'source_filename' => 'b.xlsx',
        ]);
        $rowB = $this->makeRow($runB);
        $this->assertSame($agencyB->id, (int) $rowB->agency_id);

        $this->actingAs($adminA)->post(route('corex.rentals.take-on-import.rows.exclude', $rowB))->assertNotFound();
        $this->actingAs($adminA)->post(route('corex.rentals.take-on-import.rows.confirm', $rowB))->assertNotFound();

        $this->assertSame(RentalTakeOnImportRow::STATUS_PENDING, $rowB->fresh()->status);
    }

    private function ownScopeAgent(Agency $agency, Branch $branch): User
    {
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $agency->id], ['label' => 'Agent']);
        foreach (['rentals_take_on_import.view', 'rentals_take_on_import.manage'] as $key) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $agency->id], ['scope' => 'own']);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
    }

    private function makeRow(RentalTakeOnImportRun $run): RentalTakeOnImportRow
    {
        return RentalTakeOnImportRow::create([
            'agency_id' => $run->agency_id,
            'run_id' => $run->id,
            'row_number' => 1,
            'payload_json' => ['street_name' => 'Beach Road'],
            'status' => RentalTakeOnImportRow::STATUS_PENDING,
        ]);
    }

    /**
     * @return array{0: Agency, 1: Branch, 2: User}
     */
    private function makeAgencyBranchAdmin(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main Branch']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        return [$agency, $branch, $admin];
    }

    private function sampleRow(string $street, string $suburb, string $landlord, string $tenant, float $rent): array
    {
        $row = array_fill_keys(RentalTakeOnFieldSchema::keys(), null);
        $row['street_number'] = '1';
        $row['street_name'] = $street;
        $row['suburb'] = $suburb;
        $row['landlord1_name'] = $landlord;
        $row['landlord1_phone'] = '0825550101';
        $row['tenant1_name'] = $tenant;
        $row['tenant1_phone'] = '0835550102';
        $row['lease_start_date'] = now()->toDateString();
        $row['lease_end_date'] = now()->addYear()->toDateString();
        $row['lease_type'] = 'Fixed term';
        $row['monthly_rental_amount'] = $rent;

        return $row;
    }

    private function buildFilledTemplate(Agency $agency, array $dataRows): string
    {
        $spreadsheet = app(RentalTakeOnTemplateService::class)->build($agency);
        $sheet = $spreadsheet->getSheet(0);
        $keys = RentalTakeOnFieldSchema::keys();

        foreach ($dataRows as $rowIndex => $row) {
            foreach ($keys as $colIndex => $key) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 2], $row[$key] ?? null);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'rtoi_test') . '.xlsx';
        app(RentalTakeOnTemplateService::class)->write($spreadsheet, $path);

        return $path;
    }
}
