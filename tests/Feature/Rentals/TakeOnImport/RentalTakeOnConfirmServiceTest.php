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
use App\Services\Rentals\TakeOnImport\RentalTakeOnConfirmService;
use App\Services\Rentals\TakeOnImport\RentalTakeOnDryRunResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * .ai/specs/rental-takeon-import.md §5.3 — confirm actually writes
 * Property/Contact/Lease/LeaseTenant through the canonical services, never
 * sends any notification, and leaves a genuine overlap as a draft rather
 * than losing the row.
 */
final class RentalTakeOnConfirmServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_a_complete_row_creates_property_contacts_lease_and_activates_it(): void
    {
        [$agency, $run, , $admin] = $this->makeAgencyAndRun();
        $row = $this->dryRunRow($run, $this->basePayload());

        $result = $this->confirmService()->confirmRow($row, $admin->id);
        $row->refresh();

        self::assertTrue($result['ok'], $result['message'] ?? '');
        self::assertSame(RentalTakeOnImportRow::STATUS_CONFIRMED, $row->status);

        $lease = Lease::withoutGlobalScopes()->find($row->target_lease_id);
        self::assertNotNull($lease);
        self::assertSame(Lease::STATUS_ACTIVE, $lease->status);
        self::assertSame(Lease::SOURCE_MIGRATED_TAKEON, $lease->source);
        self::assertSame('rental_take_on_import_rows', $lease->migrated_from_table);
        self::assertSame($row->id, $lease->migrated_from_id);
        self::assertSame(9500.0, (float) $lease->rental_amount);

        $property = Property::withoutGlobalScopes()->find($row->target_property_id);
        self::assertNotNull($property);
        self::assertSame('rental', $property->listing_type);
        self::assertSame($agency->id, $property->agency_id);

        self::assertCount(1, $lease->tenants);
        self::assertNotEmpty($row->target_landlord_contact_ids_json);
        self::assertDatabaseHas('contact_property', [
            'contact_id' => $row->target_landlord_contact_ids_json[0],
            'property_id' => $property->id,
            'role' => 'landlord',
        ]);
    }

    /**
     * rental-takeon-import.md §6 — escalation %/next date, opening arrears,
     * and last inspection date are captured on confirm (read-only facts
     * shown on the lease screen, never posted to a ledger, never fed into
     * any scheduled-escalation calculation).
     */
    public function test_confirming_a_row_with_takeon_facts_populates_the_migrated_fields_on_the_lease(): void
    {
        [, $run, , $admin] = $this->makeAgencyAndRun();
        $row = $this->dryRunRow($run, array_merge($this->basePayload(), [
            'escalation_percent' => 8.0,
            'next_escalation_date' => now()->addMonths(6)->toDateString(),
            'arrears_opening_balance' => 1500.50,
            'last_inspection_date' => now()->subMonths(3)->toDateString(),
        ]));

        $result = $this->confirmService()->confirmRow($row, $admin->id);
        self::assertTrue($result['ok'], $result['message'] ?? '');

        $lease = Lease::withoutGlobalScopes()->find($row->fresh()->target_lease_id);
        self::assertSame(8.0, (float) $lease->migrated_escalation_percent);
        self::assertSame(now()->addMonths(6)->toDateString(), $lease->migrated_next_escalation_date->toDateString());
        self::assertSame(1500.50, (float) $lease->migrated_opening_arrears);
        self::assertSame(now()->subMonths(3)->toDateString(), $lease->migrated_last_inspection_date->toDateString());
    }

    /** The lazy-but-valid shortcut row (none of these optional facts given) must leave all four null, not a crash or a fabricated zero. */
    public function test_confirming_a_row_with_no_takeon_facts_leaves_the_migrated_fields_null(): void
    {
        [, $run, , $admin] = $this->makeAgencyAndRun();
        $row = $this->dryRunRow($run, $this->basePayload());

        $result = $this->confirmService()->confirmRow($row, $admin->id);
        self::assertTrue($result['ok'], $result['message'] ?? '');

        $lease = Lease::withoutGlobalScopes()->find($row->fresh()->target_lease_id);
        self::assertNull($lease->migrated_escalation_percent);
        self::assertNull($lease->migrated_next_escalation_date);
        self::assertNull($lease->migrated_opening_arrears);
        self::assertNull($lease->migrated_last_inspection_date);
    }

    public function test_two_rows_resolving_to_the_same_property_the_second_stays_draft_not_lost(): void
    {
        [, $run, , $admin] = $this->makeAgencyAndRun();

        $payload = $this->basePayload();
        $rowOne = $this->dryRunRow($run, $payload);
        $rowTwo = $this->dryRunRow($run, array_merge($payload, [
            'tenant1_name' => 'Second Tenant', 'lease_start_date' => now()->addDay()->toDateString(),
        ]));

        $resultOne = $this->confirmService()->confirmRow($rowOne, $admin->id);
        $resultTwo = $this->confirmService()->confirmRow($rowTwo, $admin->id);

        self::assertTrue($resultOne['ok']);
        self::assertTrue($resultTwo['ok'], 'the second row must still import, just as a draft');

        $rowOne->refresh();
        $rowTwo->refresh();

        self::assertSame(RentalTakeOnImportRow::STATUS_CONFIRMED, $rowOne->status);
        self::assertSame(RentalTakeOnImportRow::STATUS_CONFIRMED, $rowTwo->status);

        $leaseOne = Lease::withoutGlobalScopes()->find($rowOne->target_lease_id);
        $leaseTwo = Lease::withoutGlobalScopes()->find($rowTwo->target_lease_id);

        self::assertSame(Lease::STATUS_ACTIVE, $leaseOne->status);
        self::assertSame(Lease::STATUS_DRAFT, $leaseTwo->status, 'conflicting lease must not silently activate');
        self::assertNotEmpty($rowTwo->warnings_json);
        self::assertNotNull($leaseTwo->id, 'the draft lease and its contacts must not be lost');
        self::assertCount(1, $leaseTwo->tenants);
    }

    public function test_confirming_a_row_with_blocking_errors_is_refused_and_creates_nothing(): void
    {
        [$agency, $run, , $admin] = $this->makeAgencyAndRun();

        $row = RentalTakeOnImportRow::create([
            'run_id' => $run->id, 'row_number' => 2,
            'payload_json' => $this->basePayload(),
            'status' => RentalTakeOnImportRow::STATUS_ERROR,
            'errors_json' => ['Monthly rental amount is missing or not a valid number.'],
        ]);

        $result = $this->confirmService()->confirmRow($row, $admin->id);

        self::assertFalse($result['ok']);
        self::assertSame(0, Property::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
        self::assertSame(0, Lease::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_no_email_whatsapp_or_portal_notification_is_ever_sent_by_confirm(): void
    {
        Mail::fake();
        Notification::fake();

        [, $run, , $admin] = $this->makeAgencyAndRun();
        $row = $this->dryRunRow($run, array_merge($this->basePayload(), [
            'landlord1_email' => 'landlord@example.test',
            'tenant1_email' => 'tenant@example.test',
            'agent_email' => $admin->email,
        ]));

        $result = $this->confirmService()->confirmRow($row, $admin->id);

        self::assertTrue($result['ok'], $result['message'] ?? '');
        Mail::assertNothingSent();
        Notification::assertNothingSent();
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

    private function dryRunRow(RentalTakeOnImportRun $run, array $payload): RentalTakeOnImportRow
    {
        $row = RentalTakeOnImportRow::create([
            'run_id' => $run->id, 'row_number' => rand(2, 9999),
            'payload_json' => $payload, 'status' => RentalTakeOnImportRow::STATUS_PENDING,
        ]);
        app(RentalTakeOnDryRunResolver::class)->resolve($row, $run);

        return $row->fresh();
    }

    private function basePayload(): array
    {
        return [
            'street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Scottburgh', 'erf_number' => null,
            'landlord1_name' => 'Jane Smith', 'landlord1_phone' => '0825550101',
            'tenant1_name' => 'John Doe', 'tenant1_phone' => '0835550102',
            'lease_start_date' => now()->toDateString(), 'lease_end_date' => now()->addYear()->toDateString(),
            'lease_type' => 'Fixed term', 'monthly_rental_amount' => 9500.0,
        ];
    }

    private function confirmService(): RentalTakeOnConfirmService
    {
        return app(RentalTakeOnConfirmService::class);
    }
}
