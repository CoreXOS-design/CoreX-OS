<?php

declare(strict_types=1);

namespace Tests\Unit\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Services\Docuperfect\SignatureService;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §1.2 — the live bug: extractLeaseFields() looked for
 * `lease_start_date`/`commencement_date`/`start_date`, but the real lease
 * templates (confirmed by reading their actual `data-field` attributes)
 * name the field `lease_start`. None of the old candidates ever matched.
 *
 * Reflection-based, not a full signing-ceremony fixture — extractLeaseFields()
 * and firstPartyWithRole() are pure functions of their input (a Document's
 * fields_json / a parties array), so this proves the fix directly without
 * needing Template/SignatureTemplate/SignatureRequest scaffolding.
 */
final class LeaseFieldExtractionTest extends TestCase
{
    public function test_extracts_the_real_field_names_the_live_templates_actually_use(): void
    {
        $document = new Document(['fields_json' => [
            'lease_start' => '2026-03-01',
            'lease_end' => '2027-02-28',
            'rental_amount' => '9500',
            'street_address' => '12 Example Road',
            'escalation_percent' => '8',
        ]]);

        $fields = $this->callExtractLeaseFields($document);

        self::assertSame('2026-03-01', $fields['lease_start_date']);
        self::assertSame('2027-02-28', $fields['lease_end_date']);
        self::assertSame(9500.0, $fields['rental_amount']);
        self::assertSame('12 Example Road', $fields['property_address']);
        self::assertSame(8.0, $fields['escalation_percent']);
    }

    public function test_old_guessed_field_names_still_work_as_a_fallback(): void
    {
        $document = new Document(['fields_json' => [
            'lease_start_date' => '2026-03-01',
            'lease_end_date' => '2027-02-28',
            'monthly_rental' => '9500',
            'premises_address' => '12 Example Road',
        ]]);

        $fields = $this->callExtractLeaseFields($document);

        self::assertSame('2026-03-01', $fields['lease_start_date']);
        self::assertSame('2027-02-28', $fields['lease_end_date']);
        self::assertSame(9500.0, $fields['rental_amount']);
        self::assertSame('12 Example Road', $fields['property_address']);
    }

    public function test_party_role_matches_lessee_lessor_not_only_tenant_landlord(): void
    {
        $parties = [
            ['role' => 'lessee', 'name' => 'Jane Tenant', 'email' => 'jane@example.test'],
            ['role' => 'lessor', 'name' => 'John Landlord', 'email' => 'john@example.test'],
        ];

        $tenant = $this->callFirstPartyWithRole($parties, ['tenant', 'lessee']);
        $landlord = $this->callFirstPartyWithRole($parties, ['landlord', 'lessor']);

        self::assertSame('Jane Tenant', $tenant['name']);
        self::assertSame('John Landlord', $landlord['name']);
    }

    public function test_party_role_still_matches_tenant_landlord_literally(): void
    {
        $parties = [
            ['role' => 'tenant', 'name' => 'Jane Tenant'],
            ['role' => 'landlord', 'name' => 'John Landlord'],
        ];

        self::assertSame('Jane Tenant', $this->callFirstPartyWithRole($parties, ['tenant', 'lessee'])['name']);
        self::assertSame('John Landlord', $this->callFirstPartyWithRole($parties, ['landlord', 'lessor'])['name']);
    }

    private function callExtractLeaseFields(Document $document): array
    {
        $service = app(SignatureService::class);
        $method = new \ReflectionMethod($service, 'extractLeaseFields');
        $method->setAccessible(true);

        return $method->invoke($service, $document);
    }

    private function callFirstPartyWithRole(array $parties, array $acceptableRoles): ?array
    {
        $service = app(SignatureService::class);
        $method = new \ReflectionMethod($service, 'firstPartyWithRole');
        $method->setAccessible(true);

        return $method->invoke($service, $parties, $acceptableRoles);
    }
}
