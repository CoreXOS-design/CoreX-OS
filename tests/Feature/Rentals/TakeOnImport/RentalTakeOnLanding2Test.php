<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalTakeOnColumnMapping;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * .ai/specs/rental-takeon-import.md §11 — Landing 2: saved, reusable
 * column mappings for an arbitrary CRM export. Proven against a
 * DELIBERATELY differently-shaped file (renamed + reordered headers, plus
 * one column our template has no field for at all), not a copy of our own
 * template.
 *
 * The custom file's columns, in order: Owner Name(0), Rent Amount(1),
 * Tenant Name(2), Property Street(3), Town(4), Start Date(5), Term
 * Type(6), Internal CRM ID(7, unmapped on purpose).
 */
final class RentalTakeOnLanding2Test extends TestCase
{
    use RefreshDatabase;

    private const FULL_MAPPING = [
        'landlord1_name' => 0, 'monthly_rental_amount' => 1, 'tenant1_name' => 2,
        'street_name' => 3, 'suburb' => 4, 'lease_start_date' => 5, 'lease_type' => 6,
    ];

    public function test_uploading_a_differently_shaped_file_routes_to_the_map_columns_screen(): void
    {
        [$agency, , $admin] = $this->makeAgencyBranchAdmin();
        $path = $this->buildCustomShapedFile([$this->sampleCustomRow()]);

        $response = $this->actingAs($admin)->post(route('corex.rentals.take-on-import.upload'), [
            'file' => new UploadedFile($path, 'other-crm-export.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);

        $run = RentalTakeOnImportRun::where('agency_id', $agency->id)->first();
        self::assertNotNull($run);
        self::assertSame(RentalTakeOnImportRun::STATUS_MAPPING_PENDING, $run->status);
        self::assertSame(0, $run->rows()->count(), 'nothing is parsed until the mapping is confirmed');
        $response->assertRedirect(route('corex.rentals.take-on-import.map-columns', $run));
    }

    public function test_map_columns_screen_auto_suggests_and_confirming_parses_the_file_correctly(): void
    {
        [$agency, , $admin] = $this->makeAgencyBranchAdmin();
        $path = $this->buildCustomShapedFile([$this->sampleCustomRow()]);
        $this->actingAs($admin)->post(route('corex.rentals.take-on-import.upload'), [
            'file' => new UploadedFile($path, 'other-crm-export.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
        $run = RentalTakeOnImportRun::where('agency_id', $agency->id)->first();

        $mapResponse = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.map-columns', $run));
        $mapResponse->assertOk();
        // Auto-suggested selections must be present in the rendered form
        // (the synonym list resolves "Rent Amount"/"Owner Name"/etc.).
        $mapResponse->assertSee('selected', false);

        $confirm = $this->actingAs($admin)->post(route('corex.rentals.take-on-import.confirm-mapping', $run), [
            'column' => self::FULL_MAPPING,
        ]);

        $run->refresh();
        self::assertSame(RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, $run->status);
        self::assertSame(1, $run->rows()->count());
        $row = $run->rows()->first();
        self::assertSame('Jane Smith', $row->payload_json['landlord1_name']);
        self::assertSame('John Doe', $row->payload_json['tenant1_name']);
        // MySQL's native JSON column normalises a float with no fractional
        // part (9500.0) to a bare 9500 on round-trip, so json_decode() sees
        // it as a PHP int, not a float — cast before comparing, same
        // convention the pre-existing RentalTakeOnConfirmServiceTest uses
        // for its own decimal-cast assertions.
        self::assertSame(9500.0, (float) $row->payload_json['monthly_rental_amount']);
        self::assertSame('Beach Road', $row->payload_json['street_name']);
        self::assertSame('Scottburgh', $row->payload_json['suburb']);
        self::assertSame('Fixed term', $row->payload_json['lease_type']);
        $confirm->assertRedirect(route('corex.rentals.take-on-import.preview', $run));
    }

    public function test_confirming_a_mapping_missing_a_required_field_is_rejected_and_creates_nothing(): void
    {
        [$agency, , $admin] = $this->makeAgencyBranchAdmin();
        $path = $this->buildCustomShapedFile([$this->sampleCustomRow()]);
        $this->actingAs($admin)->post(route('corex.rentals.take-on-import.upload'), [
            'file' => new UploadedFile($path, 'other-crm-export.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
        $run = RentalTakeOnImportRun::where('agency_id', $agency->id)->first();

        // monthly_rental_amount (required) deliberately left unmapped.
        $incomplete = self::FULL_MAPPING;
        unset($incomplete['monthly_rental_amount']);

        $response = $this->actingAs($admin)->post(route('corex.rentals.take-on-import.confirm-mapping', $run), [
            'column' => $incomplete,
        ]);

        $response->assertSessionHasErrors('mapping');
        self::assertSame(0, $run->fresh()->rows()->count());
        self::assertSame(RentalTakeOnImportRun::STATUS_MAPPING_PENDING, $run->fresh()->status);
    }

    public function test_saving_a_mapping_persists_it_and_reusing_it_resolves_against_a_reordered_file(): void
    {
        [$agency, , $admin] = $this->makeAgencyBranchAdmin();
        $path1 = $this->buildCustomShapedFile([$this->sampleCustomRow()]);
        $this->actingAs($admin)->post(route('corex.rentals.take-on-import.upload'), [
            'file' => new UploadedFile($path1, 'export1.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
        $run1 = RentalTakeOnImportRun::where('agency_id', $agency->id)->first();

        $this->actingAs($admin)->post(route('corex.rentals.take-on-import.confirm-mapping', $run1), [
            'column' => self::FULL_MAPPING,
            'save_mapping' => '1',
            'mapping_name' => 'Acme CRM export',
        ]);

        $saved = RentalTakeOnColumnMapping::where('agency_id', $agency->id)->where('name', 'Acme CRM export')->first();
        self::assertNotNull($saved);
        self::assertSame('Property Street', $saved->mapping_json['street_name']);
        self::assertSame('Owner Name', $saved->mapping_json['landlord1_name']);

        // The SAME CRM's next export — columns reordered (CRMs do this);
        // "Internal CRM ID" dropped entirely this time.
        $path2 = $this->buildCustomShapedFile([[
            'Rent Amount' => 7200, 'Owner Name' => 'Peter Jones', 'Property Street' => 'Marine Drive',
            'Tenant Name' => 'Mary Brown', 'Town' => 'Uvongo', 'Start Date' => now()->toDateString(), 'Term Type' => 'Fixed term',
        ]]);
        $this->actingAs($admin)->post(route('corex.rentals.take-on-import.upload'), [
            'file' => new UploadedFile($path2, 'export2.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
        $run2 = RentalTakeOnImportRun::where('id', '!=', $run1->id)->where('agency_id', $agency->id)->first();

        $mapResponse = $this->actingAs($admin)->get(route('corex.rentals.take-on-import.map-columns', $run2, ['load_mapping' => $saved->id]));
        $mapResponse->assertOk();
        $mapResponse->assertSee('Loaded');

        // Confirm using the RE-RESOLVED positions for the reordered file
        // (0=Rent Amount, 1=Owner Name, 2=Property Street, 3=Tenant Name, 4=Town, 5=Start Date, 6=Term Type).
        $confirm = $this->actingAs($admin)->post(route('corex.rentals.take-on-import.confirm-mapping', $run2), [
            'column' => [
                'monthly_rental_amount' => 0, 'landlord1_name' => 1, 'street_name' => 2,
                'tenant1_name' => 3, 'suburb' => 4, 'lease_start_date' => 5, 'lease_type' => 6,
            ],
        ]);
        $confirm->assertRedirect(route('corex.rentals.take-on-import.preview', $run2));
        $row = $run2->fresh()->rows()->first();
        self::assertSame('Peter Jones', $row->payload_json['landlord1_name']);
        self::assertSame('Mary Brown', $row->payload_json['tenant1_name']);
    }

    public function test_saved_mapping_crud_archive_restore_and_agency_isolation(): void
    {
        [$agencyA, , $adminA] = $this->makeAgencyBranchAdmin();
        [, , $adminB] = $this->makeAgencyBranchAdmin();

        $mapping = RentalTakeOnColumnMapping::create([
            'agency_id' => $agencyA->id, 'name' => 'Agency A mapping',
            'mapping_json' => ['tenant1_name' => 'Tenant'], 'created_by_user_id' => $adminA->id,
        ]);

        // Agency isolation — direct URL by id, not just absent from a menu.
        $this->actingAs($adminB)->post(route('corex.rentals.take-on-import.mappings.archive', $mapping))->assertNotFound();

        $this->actingAs($adminA)->get(route('corex.rentals.take-on-import.mappings.index'))->assertOk()->assertSee('Agency A mapping');

        $this->actingAs($adminA)->post(route('corex.rentals.take-on-import.mappings.archive', $mapping))->assertRedirect();
        self::assertTrue($mapping->fresh()->trashed());

        $this->actingAs($adminA)->get(route('corex.rentals.take-on-import.mappings.index', ['archived' => 1]))->assertOk()->assertSee('Agency A mapping');

        $this->actingAs($adminA)->post(route('corex.rentals.take-on-import.mappings.restore', $mapping->id))->assertRedirect();
        self::assertFalse($mapping->fresh()->trashed());
    }

    private function sampleCustomRow(): array
    {
        return [
            'Owner Name' => 'Jane Smith', 'Rent Amount' => 9500, 'Tenant Name' => 'John Doe',
            'Property Street' => 'Beach Road', 'Town' => 'Scottburgh', 'Start Date' => now()->toDateString(),
            'Term Type' => 'Fixed term', 'Internal CRM ID' => 'XRM-001',
        ];
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

    /**
     * @param array<int, array<string, mixed>> $rows  each row is header => value
     */
    private function buildCustomShapedFile(array $rows): string
    {
        $headers = array_keys($rows[0]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($headers as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }
        foreach ($rows as $r => $row) {
            foreach ($headers as $i => $header) {
                $sheet->setCellValue([$i + 1, $r + 2], $row[$header]);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'rtoi_custom') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
