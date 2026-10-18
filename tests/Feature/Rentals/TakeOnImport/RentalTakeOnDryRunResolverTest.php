<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use App\Services\Rentals\TakeOnImport\RentalTakeOnDryRunResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-takeon-import.md §5.2/§9 — the dry run must write a
 * truthful preview and never create a Property/Contact/Lease. Covers the
 * input-space matrix BUILD_STANDARD §5 requires: happy path, each-required-
 * field-omitted, the lazy-but-valid shortcut, malformed input.
 */
final class RentalTakeOnDryRunResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_with_no_prior_data_resolves_to_create_everything_and_is_complete(): void
    {
        [$agency, $run] = $this->makeAgencyAndRun();
        $row = $this->makeRow($run, $this->basePayload());

        $this->resolver()->resolve($row, $run);
        $row->refresh();

        self::assertSame(RentalTakeOnImportRow::ACTION_CREATE, $row->property_match_action);
        self::assertSame(RentalTakeOnImportRow::COMPLETENESS_COMPLETE, $row->lease_completeness);
        self::assertSame(RentalTakeOnImportRow::STATUS_PENDING, $row->status);
        self::assertNull($row->errors_json);
        // Nothing was actually created — dry run only.
        self::assertSame(0, Property::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
        self::assertSame(0, Contact::withoutGlobalScopes()->where('agency_id', $agency->id)->count());
    }

    public function test_an_existing_property_is_matched_not_marked_for_creation(): void
    {
        [$agency, $run, $branch, $admin] = $this->makeAgencyAndRun(true);

        // The matcher (non-negotiable #10) only ever looks at
        // tracked_properties, never at properties directly — so "already
        // known to CoreX" means a TrackedProperty exists for this address,
        // via the SAME matchOrCreate() call the real confirm step uses.
        app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $agency->id,
            ['street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Scottburgh'],
            ['type' => 'manual', 'ref' => 'pre-existing-' . uniqid()],
        );

        $row = $this->makeRow($run, array_merge($this->basePayload(), [
            'street_number' => '12', 'street_name' => 'Beach Road', 'suburb' => 'Scottburgh',
        ]));

        $this->resolver()->resolve($row, $run);
        $row->refresh();

        self::assertSame(RentalTakeOnImportRow::ACTION_MATCH, $row->property_match_action);
        self::assertStringContainsString('Matches existing property', (string) $row->property_match_label);
    }

    public function test_an_existing_contact_is_offered_as_a_match_not_a_blind_create(): void
    {
        [$agency, $run, $branch] = $this->makeAgencyAndRun(true);

        $existingLandlord = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Jane', 'last_name' => 'Smith', 'phone' => '0825550101',
        ]);

        $row = $this->makeRow($run, array_merge($this->basePayload(), [
            'landlord1_name' => 'Jane Smith', 'landlord1_phone' => '082 555 0101',
        ]));

        $this->resolver()->resolve($row, $run);
        $row->refresh();

        self::assertSame('match', $row->landlord_match_json[1]['action']);
        self::assertSame($existingLandlord->id, $row->landlord_match_json[1]['existing_contact_id']);
    }

    public function test_the_lazy_but_valid_shortcut_imports_as_complete(): void
    {
        [, $run] = $this->makeAgencyAndRun();

        // Only the hard-mandatory set: property anchor, one landlord, one
        // tenant, rent, start date. No deposit/escalation/notes/branch/agent.
        $row = $this->makeRow($run, [
            'street_name' => 'Main Road', 'suburb' => 'Scottburgh',
            'landlord1_name' => 'Jane Smith',
            'tenant1_name' => 'John Doe',
            'lease_start_date' => now()->toDateString(),
            'lease_type' => 'Fixed term',
            'lease_end_date' => now()->addYear()->toDateString(),
            'monthly_rental_amount' => 8500.0,
        ]);

        $this->resolver()->resolve($row, $run);
        $row->refresh();

        self::assertSame(RentalTakeOnImportRow::COMPLETENESS_COMPLETE, $row->lease_completeness);
        self::assertNull($row->errors_json);
    }

    public function test_each_hard_mandatory_field_omitted_individually_produces_draft_or_error(): void
    {
        [, $run] = $this->makeAgencyAndRun();

        // No landlord — draft, not error.
        $row = $this->makeRow($run, array_merge($this->basePayload(), ['landlord1_name' => null]));
        $this->resolver()->resolve($row, $run);
        $row->refresh();
        self::assertSame(RentalTakeOnImportRow::COMPLETENESS_DRAFT, $row->lease_completeness);
        self::assertNull($row->errors_json);

        // No tenant — draft, not error.
        $row2 = $this->makeRow($run, array_merge($this->basePayload(), ['tenant1_name' => null]));
        $this->resolver()->resolve($row2, $run);
        $row2->refresh();
        self::assertSame(RentalTakeOnImportRow::COMPLETENESS_DRAFT, $row2->lease_completeness);

        // No rent — hard error (schema NOT NULL on leases.rental_amount).
        $row3 = $this->makeRow($run, array_merge($this->basePayload(), ['monthly_rental_amount' => null]));
        $this->resolver()->resolve($row3, $run);
        $row3->refresh();
        self::assertNotEmpty($row3->errors_json);

        // No start date — hard error (schema NOT NULL on leases.start_date).
        $row4 = $this->makeRow($run, array_merge($this->basePayload(), ['lease_start_date' => null]));
        $this->resolver()->resolve($row4, $run);
        $row4->refresh();
        self::assertNotEmpty($row4->errors_json);

        // No street/suburb/erf at all — nothing to anchor a property to.
        $row5 = $this->makeRow($run, array_merge($this->basePayload(), ['street_name' => null, 'suburb' => null, 'erf_number' => null]));
        $this->resolver()->resolve($row5, $run);
        $row5->refresh();
        self::assertNotEmpty($row5->errors_json);
    }

    public function test_malformed_rent_and_unrecognised_lease_type_are_rejected_with_a_plain_message(): void
    {
        [, $run] = $this->makeAgencyAndRun();

        $row = $this->makeRow($run, array_merge($this->basePayload(), [
            'monthly_rental_amount' => -500.0,
            'lease_type' => 'Whenever we feel like it',
        ]));

        $this->resolver()->resolve($row, $run);
        $row->refresh();

        self::assertNotEmpty($row->errors_json);
        self::assertTrue(collect($row->errors_json)->contains(fn ($e) => str_contains($e, 'greater than zero')));
        self::assertTrue(collect($row->errors_json)->contains(fn ($e) => str_contains($e, 'not recognised')));
    }

    public function test_month_to_month_with_no_end_date_is_a_warning_not_an_error(): void
    {
        [, $run] = $this->makeAgencyAndRun();

        $row = $this->makeRow($run, array_merge($this->basePayload(), [
            'lease_type' => 'Month-to-month', 'lease_end_date' => null,
        ]));

        $this->resolver()->resolve($row, $run);
        $row->refresh();

        self::assertNull($row->errors_json);
        self::assertSame(RentalTakeOnImportRow::COMPLETENESS_COMPLETE, $row->lease_completeness);
    }

    public function test_unresolved_agent_email_is_a_warning_not_a_blocking_error(): void
    {
        [, $run] = $this->makeAgencyAndRun();

        $row = $this->makeRow($run, array_merge($this->basePayload(), [
            'agent_email' => 'nobody-at-this-agency@example.test',
        ]));

        $this->resolver()->resolve($row, $run);
        $row->refresh();

        self::assertNull($row->errors_json);
        self::assertNotEmpty($row->warnings_json);
        self::assertTrue(collect($row->warnings_json)->contains(fn ($w) => str_contains($w, 'was not found')));
    }

    /**
     * @return array{0: Agency, 1: RentalTakeOnImportRun, 2: Branch, 3: User}
     */
    private function makeAgencyAndRun(bool $withBranchAndAdmin = false): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main Branch']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $run = RentalTakeOnImportRun::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'user_id' => $admin->id,
            'status' => RentalTakeOnImportRun::STATUS_PARSING, 'source_filename' => 'book.xlsx',
        ]);

        return [$agency, $run, $branch, $admin];
    }

    private function makeRow(RentalTakeOnImportRun $run, array $payload): RentalTakeOnImportRow
    {
        return RentalTakeOnImportRow::create([
            'run_id' => $run->id,
            'row_number' => 2,
            'payload_json' => $payload,
            'status' => RentalTakeOnImportRow::STATUS_PENDING,
        ]);
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

    private function resolver(): RentalTakeOnDryRunResolver
    {
        return app(RentalTakeOnDryRunResolver::class);
    }
}
