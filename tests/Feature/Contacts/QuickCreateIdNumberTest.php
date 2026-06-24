<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use App\Rules\SouthAfricanIdNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase A.2.5 — ID number capture on quick-create contact (M62-M65).
 *
 * Covers the validator (which both entry points share) and the property
 * inline-create endpoint flow (which exercises the audit-field writes).
 * The seller-outreach entry path uses the same validator + audit fields;
 * the seller-outreach happy path needs an underlying prospecting_listing
 * + branch + permissions plumbing that's out of scope for this unit.
 */
final class QuickCreateIdNumberTest extends TestCase
{
    use RefreshDatabase;

    /** M62 — empty id_number is accepted (field is optional everywhere). */
    public function test_m62_empty_id_number_accepted(): void
    {
        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();

        $resp = $this->actingAs(User::find($userId))->postJson(
            route('corex.properties.contacts.createAndLink', $propertyId),
            [
                'first_name' => 'Jane',
                'last_name'  => 'Tester',
                'phone'      => '0821234567',
                'role'       => 'seller',
                'id_number'  => '',
            ],
        );

        $resp->assertOk();
        $contact = Contact::orderByDesc('id')->first();
        $this->assertNull($contact->id_number);
        $this->assertNull($contact->id_number_captured_at);
        $this->assertNull($contact->id_number_source);
    }

    /** M63 — checksum-invalid 13-digit input rejected by the SA ID rule. */
    public function test_m63_invalid_checksum_rejected(): void
    {
        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();

        $resp = $this->actingAs(User::find($userId))->postJson(
            route('corex.properties.contacts.createAndLink', $propertyId),
            [
                'first_name' => 'Jane', 'last_name' => 'Tester', 'phone' => '0821234567',
                'role'       => 'seller',
                // Last digit is wrong (checksum) — date OK, length OK.
                'id_number'  => '7610025020082',
            ],
        );
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['id_number']);
    }

    /** M64 — valid SA ID is persisted with captured_at + source. */
    public function test_m64_valid_id_persists_with_audit_fields(): void
    {
        [$agencyId, $userId, $propertyId] = $this->seedAgencyUserProperty();

        $before = now()->subSecond();
        $resp = $this->actingAs(User::find($userId))->postJson(
            route('corex.properties.contacts.createAndLink', $propertyId),
            [
                'first_name' => 'Sam', 'last_name' => 'Test', 'phone' => '0821234567',
                'role'       => 'seller',
                'id_number'  => '7610025020081',
            ],
        );
        $resp->assertOk();

        $contact = Contact::where('id_number', '7610025020081')->firstOrFail();
        $this->assertSame('7610025020081', $contact->id_number);
        $this->assertSame('property_inline_create', $contact->id_number_source);
        $this->assertNotNull($contact->id_number_captured_at);
        $this->assertTrue($contact->id_number_captured_at->greaterThanOrEqualTo($before));
    }

    /** M65 — SA ID validator helpers extract DOB + gender correctly. */
    public function test_m65_id_helpers_extract_dob_and_gender(): void
    {
        $id = '7610025020081';
        $this->assertSame('1976-10-02', SouthAfricanIdNumber::dateOfBirth($id));
        $this->assertSame('M', SouthAfricanIdNumber::gender($id),
            'sequence 5020 → male (≥ 5000)');

        // A female placeholder (sequence 0001 < 5000).
        $maleId   = '7610025020081';
        $femaleId = '7610020001083';  // crafted to be female with a valid date
        // Date 1976-10-02 valid, gender = F.
        $this->assertSame('F', SouthAfricanIdNumber::gender($femaleId));
        $this->assertNull(SouthAfricanIdNumber::dateOfBirth('12345'),
            'invalid input returns null');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgencyUserProperty(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        $propertyId = (int) DB::table('properties')->insertGetId([
            'external_id' => 'TEST-' . Str::random(8),
            'title' => 'Test Property',
            'address' => '18 Golf Course Road',
            'suburb' => 'Uvongo',
            'price' => 1_200_000,
            'status' => 'active',
            'is_demo' => false,
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'agent_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return [$agencyId, $user->id, $propertyId];
    }
}
