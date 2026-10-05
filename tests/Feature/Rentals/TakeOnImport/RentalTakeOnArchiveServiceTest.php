<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use App\Services\Rentals\TakeOnImport\RentalTakeOnArchiveService;
use App\Services\Rentals\TakeOnImport\RentalTakeOnConfirmService;
use App\Services\Rentals\TakeOnImport\RentalTakeOnDryRunResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-takeon-import.md §7 — "Archive batch" only archives what
 * THIS import created and that is still untouched; it leaves matched
 * (pre-existing) and since-edited records alone, and Restore reverses
 * exactly what it archived.
 */
final class RentalTakeOnArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_removes_fresh_creations_and_leaves_an_edited_lease_alone(): void
    {
        [$agency, $run, $branch, $admin] = $this->makeAgencyAndRun();

        $rowA = $this->confirmedRow($run, $admin, $this->payload('Beach Road', 'Scottburgh', 'A Tenant'));
        $rowB = $this->confirmedRow($run, $admin, $this->payload('Marine Drive', 'Uvongo', 'B Tenant'));

        // Simulate an agent editing rowB's lease sometime after import
        // (bumping the deposit) — this one must be LEFT ALONE, not
        // archived. The "untouched" signal is created_at === updated_at
        // (second precision on these columns), so the edit must land in a
        // later second than creation to be a realistic simulation of "an
        // agent touched this later" rather than a same-second test-timing
        // artifact.
        \Illuminate\Support\Carbon::setTestNow(now()->addMinute());
        $leaseB = Lease::withoutGlobalScopes()->find($rowB->target_lease_id);
        $leaseB->update(['deposit_amount' => 1000]);
        \Illuminate\Support\Carbon::setTestNow();

        $counts = app(RentalTakeOnArchiveService::class)->archive($run->fresh());

        self::assertSame(1, $counts['archived']['leases']);
        self::assertSame(1, $counts['left_alone_edited']['leases']);

        $leaseA = Lease::withoutGlobalScopes()->find($rowA->target_lease_id);
        self::assertTrue($leaseA->trashed());
        self::assertFalse($leaseB->fresh()->trashed());

        self::assertTrue($run->fresh()->trashed());
    }

    public function test_archive_never_touches_a_property_that_was_matched_not_created(): void
    {
        [$agency, $run, $branch, $admin] = $this->makeAgencyAndRun();

        $existingProperty = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $admin->id,
            'title' => '5 Beach Road', 'status' => 'active', 'listing_type' => 'rental',
            'street_number' => '5', 'street_name' => 'Beach Road', 'suburb' => 'Scottburgh',
        ]);

        $row = $this->confirmedRow($run, $admin, array_merge(
            $this->payload('Beach Road', 'Scottburgh', 'A Tenant'),
            ['street_number' => '5']
        ));

        self::assertSame($existingProperty->id, $row->fresh()->target_property_id);

        $counts = app(RentalTakeOnArchiveService::class)->archive($run->fresh());

        self::assertSame(0, $counts['archived']['properties']);
        self::assertSame(1, $counts['left_alone_existing']['properties']);
        self::assertFalse($existingProperty->fresh()->trashed());
    }

    public function test_restore_reverses_exactly_what_was_archived(): void
    {
        [, $run, , $admin] = $this->makeAgencyAndRun();
        $row = $this->confirmedRow($run, $admin, $this->payload('Beach Road', 'Scottburgh', 'A Tenant'));

        $archiveService = app(RentalTakeOnArchiveService::class);
        $archiveService->archive($run->fresh());

        $lease = Lease::withoutGlobalScopes()->find($row->target_lease_id);
        $property = Property::withoutGlobalScopes()->find($row->target_property_id);
        self::assertTrue($lease->trashed());
        self::assertTrue($property->trashed());

        $archiveService->restore(RentalTakeOnImportRun::withTrashed()->find($run->id));

        self::assertFalse($lease->fresh()->trashed());
        self::assertFalse($property->fresh()->trashed());
        self::assertFalse(RentalTakeOnImportRun::find($run->id)->trashed());
    }

    /**
     * @return array{0: Agency, 1: RentalTakeOnImportRun, 2: Branch, 3: User}
     */
    private function makeAgencyAndRun(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main Branch']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $run = RentalTakeOnImportRun::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'user_id' => $admin->id,
            'status' => RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, 'source_filename' => 'book.xlsx',
        ]);

        return [$agency, $run, $branch, $admin];
    }

    private function confirmedRow(RentalTakeOnImportRun $run, User $admin, array $payload): RentalTakeOnImportRow
    {
        $row = RentalTakeOnImportRow::create([
            'run_id' => $run->id, 'row_number' => rand(2, 9999),
            'payload_json' => $payload, 'status' => RentalTakeOnImportRow::STATUS_PENDING,
        ]);
        app(RentalTakeOnDryRunResolver::class)->resolve($row, $run);
        $row->refresh();

        $result = app(RentalTakeOnConfirmService::class)->confirmRow($row, $admin->id);
        self::assertTrue($result['ok'], $result['message'] ?? '');

        return $row->fresh();
    }

    private function payload(string $street, string $suburb, string $tenant): array
    {
        return [
            'street_number' => '12', 'street_name' => $street, 'suburb' => $suburb, 'erf_number' => null,
            'landlord1_name' => 'Jane Smith', 'landlord1_phone' => '0825550101',
            'tenant1_name' => $tenant, 'tenant1_phone' => '0835550102',
            'lease_start_date' => now()->toDateString(), 'lease_end_date' => now()->addYear()->toDateString(),
            'lease_type' => 'Fixed term', 'monthly_rental_amount' => 9500.0,
        ];
    }
}
