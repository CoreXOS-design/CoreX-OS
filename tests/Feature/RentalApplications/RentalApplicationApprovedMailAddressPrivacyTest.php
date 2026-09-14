<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\RentalApplicationApprovedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-392, 2026-09-17 — guards the fix in Property::addressFreeDescriptor()
 * and emails/rental-application-approved.blade.php against regressing.
 * Johan's rule: the applicant approval email never prints a property's
 * street address -- "3 bed house" not the street. cc1 confirmed, while
 * verifying the fix, that nothing previously tested this mailable at all;
 * this file closes that gap so the next person to touch the template
 * regresses loudly instead of silently.
 *
 * Every assertion is built from the property record's own address fields
 * (street_number/street_name/suburb/town/city), never a hardcoded address
 * string, so the test stays true as fixture data changes.
 *
 * Mail::fake() throughout -- nothing is ever actually sent.
 */
final class RentalApplicationApprovedMailAddressPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_matched_properties_show_descriptors_and_hide_every_address_field(): void
    {
        Mail::fake();

        [$application, $agencyId, $branchId, $agentId] = $this->makeApplication();

        $propertyOne = $this->makeProperty($agencyId, $branchId, $agentId, [
            'beds' => 2,
            'property_type' => 'Apartment / Flat',
            'street_number' => '60',
            'street_name' => 'Lilliecrona Boulevard',
            'suburb' => 'Manaba Beach',
            'town' => 'Margate',
            'city' => 'Margate',
            'rental_amount' => 8620,
        ]);

        $propertyTwo = $this->makeProperty($agencyId, $branchId, $agentId, [
            'beds' => 1,
            'property_type' => 'Commercial Property',
            'street_number' => '43',
            'street_name' => 'Coral Ridge Drive',
            'suburb' => 'Ballito',
            'town' => 'Ballito',
            'city' => 'Ballito',
            'rental_amount' => 6705,
        ]);

        $properties = new Collection([$propertyOne, $propertyTwo]);

        Mail::to('applicant@example.test')->send(
            new RentalApplicationApprovedMail($application, $properties, false, null)
        );

        Mail::assertSent(RentalApplicationApprovedMail::class, function (RentalApplicationApprovedMail $mail) use ($propertyOne, $propertyTwo) {
            $body = $mail->render();

            foreach ([$propertyOne, $propertyTwo] as $property) {
                $descriptor = $property->addressFreeDescriptor();
                self::assertStringContainsString(
                    $descriptor,
                    $body,
                    "Expected descriptor '{$descriptor}' to appear in the rendered body."
                );
                $this->assertNoAddressFieldsLeak($property, $body, 'body');
            }

            return true;
        });
    }

    public function test_address_free_descriptor_fallback_chain_reads_sensibly_in_every_case(): void
    {
        Mail::fake();

        [$application, $agencyId, $branchId, $agentId] = $this->makeApplication();

        $bedsAndType = $this->makeProperty($agencyId, $branchId, $agentId, [
            'beds' => 3, 'property_type' => 'House',
            'street_number' => '12', 'street_name' => 'Test Street',
            'suburb' => 'TestSuburb', 'town' => 'TestTown', 'city' => 'TestCity',
        ]);
        $typeOnlyNoBeds = $this->makeProperty($agencyId, $branchId, $agentId, [
            'beds' => 0, 'property_type' => 'Vacant Land',
            'street_number' => '13', 'street_name' => 'Second Street',
            'suburb' => 'SecondSuburb', 'town' => 'SecondTown', 'city' => 'SecondCity',
        ]);
        $bedsOnlyNoType = $this->makeProperty($agencyId, $branchId, $agentId, [
            'beds' => 2, 'property_type' => '',
            'street_number' => '14', 'street_name' => 'Third Street',
            'suburb' => 'ThirdSuburb', 'town' => 'ThirdTown', 'city' => 'ThirdCity',
        ]);
        // 'beds' is NOT NULL in the schema, so "neither" is represented as
        // 0/'' -- the same values addressFreeDescriptor()'s own null-coalesce
        // defends against, but the only ones the DB will actually accept.
        $neitherNullBlank = $this->makeProperty($agencyId, $branchId, $agentId, [
            'beds' => 0, 'property_type' => '',
            'street_number' => '15', 'street_name' => 'Fourth Street',
            'suburb' => 'FourthSuburb', 'town' => 'FourthTown', 'city' => 'FourthCity',
        ]);

        // Pin the fallback chain itself, independent of the email -- if this
        // ever drifts, the failure points straight at addressFreeDescriptor().
        self::assertSame('3 Bedroom House', $bedsAndType->addressFreeDescriptor());
        self::assertSame('Vacant Land', $typeOnlyNoBeds->addressFreeDescriptor());
        self::assertSame('2 Bedroom Property', $bedsOnlyNoType->addressFreeDescriptor());
        self::assertSame('A property', $neitherNullBlank->addressFreeDescriptor());

        $properties = new Collection([$bedsAndType, $typeOnlyNoBeds, $bedsOnlyNoType, $neitherNullBlank]);

        Mail::to('applicant@example.test')->send(
            new RentalApplicationApprovedMail($application, $properties, false, null)
        );

        Mail::assertSent(RentalApplicationApprovedMail::class, function (RentalApplicationApprovedMail $mail) use ($properties) {
            $body = $mail->render();

            foreach ($properties as $property) {
                $descriptor = $property->addressFreeDescriptor();

                self::assertNotSame(
                    '',
                    trim($descriptor),
                    'Descriptor must never be blank -- the email must read sensibly even with no beds/type data.'
                );
                self::assertStringContainsString($descriptor, $body);
                $this->assertNoAddressFieldsLeak($property, $body, 'body');
            }

            return true;
        });
    }

    public function test_subject_line_never_carries_property_content_and_matches_fica_state(): void
    {
        Mail::fake();

        [$application, $agencyId, $branchId, $agentId] = $this->makeApplication();
        $property = $this->makeProperty($agencyId, $branchId, $agentId, [
            'beds' => 2, 'property_type' => 'Apartment / Flat',
            'street_number' => '99', 'street_name' => 'Leak Street',
            'suburb' => 'LeakSuburb', 'town' => 'LeakTown', 'city' => 'LeakCity',
        ]);
        $properties = new Collection([$property]);

        Mail::to('applicant@example.test')->send(
            new RentalApplicationApprovedMail($application, $properties, true, 'https://example.test/fica')
        );

        Mail::assertSent(RentalApplicationApprovedMail::class, function (RentalApplicationApprovedMail $mail) use ($property) {
            $subject = $mail->envelope()->subject;

            self::assertStringContainsString("You're approved, subject to FICA verification", $subject);
            $this->assertNoAddressFieldsLeak($property, $subject, 'subject');

            return true;
        });
    }

    public function test_subject_line_matches_unconditional_approval_when_fica_clear(): void
    {
        Mail::fake();

        [$application, $agencyId, $branchId, $agentId] = $this->makeApplication();
        $property = $this->makeProperty($agencyId, $branchId, $agentId, [
            'beds' => 4, 'property_type' => 'House',
            'street_number' => '7', 'street_name' => 'Clear Street',
            'suburb' => 'ClearSuburb', 'town' => 'ClearTown', 'city' => 'ClearCity',
        ]);
        $properties = new Collection([$property]);

        Mail::to('applicant@example.test')->send(
            new RentalApplicationApprovedMail($application, $properties, false, null)
        );

        Mail::assertSent(RentalApplicationApprovedMail::class, function (RentalApplicationApprovedMail $mail) use ($property) {
            $subject = $mail->envelope()->subject;

            self::assertStringContainsString("Congratulations — you're approved to rent!", $subject);
            $this->assertNoAddressFieldsLeak($property, $subject, 'subject');

            return true;
        });
    }

    /**
     * Builds the "must not appear" list from the property record itself
     * (never a hardcoded string), so the test keeps working if fixture
     * values ever change.
     *
     * street_number is deliberately checked ONLY as part of the composed
     * "{number} {name}" street line, never as a bare digit -- a bare
     * street_number like "60" or "7" is a false-positive magnet against a
     * whole HTML email (CSS px/border-radius values, price digits like
     * "R8,620", even this test's own uniqid()-suffixed agency name all
     * contain stray digits). The composed line is what an actual address
     * leak would print, and it can't collide by accident.
     */
    private function assertNoAddressFieldsLeak(Property $property, string $haystack, string $where): void
    {
        self::assertNotEmpty($property->street_number, 'Fixture setup error: street_number must not be blank for this assertion to be meaningful.');
        self::assertNotEmpty($property->street_name, 'Fixture setup error: street_name must not be blank for this assertion to be meaningful.');

        $streetLine = trim($property->street_number . ' ' . $property->street_name);
        self::assertStringNotContainsString(
            $streetLine,
            $haystack,
            "Street address '{$streetLine}' leaked into the applicant approval email {$where}."
        );

        foreach (['suburb' => $property->suburb, 'town' => $property->town, 'city' => $property->city] as $field => $value) {
            self::assertNotEmpty($value, "Fixture setup error: property {$field} must not be blank for this assertion to be meaningful.");
            self::assertStringNotContainsString(
                (string) $value,
                $haystack,
                "Address field '{$field}' ({$value}) leaked into the applicant approval email {$where}."
            );
        }
    }

    /**
     * @return array{0: RentalApplication, 1: int, 2: int, 3: int}
     */
    private function makeApplication(array $overrides = []): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Test',
            'email' => 'contact-' . uniqid() . '@example.test',
        ]);

        $application = RentalApplication::create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $agent->id,
            'status' => 'approved', 'approved_rental_amount' => 9000.00,
            'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ], $overrides));

        return [$application, $agency->id, $branch->id, $agent->id];
    }

    private function makeProperty(int $agencyId, int $branchId, int $agentId, array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'agent_id' => $agentId,
            'title' => 'Test property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'rental',
            'rental_amount' => 7500,
        ], $overrides));
    }
}
