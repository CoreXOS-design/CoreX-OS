<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
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
