<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Contact export — Excel in the agreed round-trip format. The "Agents" column
 * carries the owning agent's name so the importer re-assigns the contact on
 * re-import (created_by_user_id). Columns with no native field are blank.
 */
final class ContactExportTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_HEADERS = [
        'Category', 'Name', 'Surname', 'Email', 'Cell', 'Phone', 'Type',
        '*ID Number', 'BirthDay', 'Tags', 'Source', 'Address', 'Wish Lists',
        'Matches', 'SMS', 'Emails', 'WhatsApp', 'Opt-In', 'Agents',
        'Loaded', 'Modified', 'Last Contacted', 'Additional Info',
    ];

    public function test_export_returns_xlsx_with_exact_headers_and_contact_data(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->makeContact($agencyId, $user->id, ['first_name' => 'Jane', 'last_name' => 'Seller', 'email' => 'jane@example.com']);

        $response = $this->actingAs($user)->get(route('corex.contacts.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $rows = $this->parse($response->streamedContent());

        $this->assertSame(self::EXPECTED_HEADERS, $rows[0], 'header row must match the agreed format exactly');

        // Locate Jane's row and assert key columns, including the Agents round-trip value.
        $jane = collect($rows)->firstWhere(1, 'Jane'); // column index 1 = Name
        $this->assertNotNull($jane, 'exported contact present');
        $this->assertSame('Seller', $jane[2]);                 // Surname
        $this->assertSame('jane@example.com', $jane[3]);       // Email
        $this->assertSame($user->name, $jane[18]);             // Agents = owning agent name
    }

    public function test_export_all_includes_every_agency_contact_while_agent_filter_narrows(): void
    {
        [$agencyId, $owner] = $this->seedAgencyUser();
        $other = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        $this->makeContact($agencyId, $owner->id, ['first_name' => 'OwnerOwned']);
        $this->makeContact($agencyId, $other->id, ['first_name' => 'OtherOwned']);

        // Export all → both contacts present.
        $all = $this->parse($this->actingAs($owner)->get(route('corex.contacts.export', ['all' => 1]))->streamedContent());
        $allNames = collect($all)->skip(1)->pluck(1)->all();
        $this->assertContains('OwnerOwned', $allNames);
        $this->assertContains('OtherOwned', $allNames);

        // Filtered by agent_id → only that agent's contacts.
        $filtered = $this->parse($this->actingAs($owner)->get(route('corex.contacts.export', ['agent_id' => $other->id]))->streamedContent());
        $filteredNames = collect($filtered)->skip(1)->pluck(1)->all();
        $this->assertContains('OtherOwned', $filteredNames);
        $this->assertNotContains('OwnerOwned', $filteredNames);
    }

    public function test_export_respects_search_filter(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();
        $this->makeContact($agencyId, $user->id, ['first_name' => 'Findme', 'last_name' => 'Unique']);
        $this->makeContact($agencyId, $user->id, ['first_name' => 'Hidden', 'last_name' => 'Other']);

        $rows  = $this->parse($this->actingAs($user)->get(route('corex.contacts.export', ['agent_id' => '', 'search' => 'Findme']))->streamedContent());
        $names = collect($rows)->skip(1)->pluck(1)->all();

        $this->assertContains('Findme', $names);
        $this->assertNotContains('Hidden', $names);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Parse streamed xlsx bytes back into a 0-indexed array of rows. */
    private function parse(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cexp') . '.xlsx';
        file_put_contents($tmp, $bytes);
        try {
            $sheet = IOFactory::load($tmp)->getActiveSheet();
            return $sheet->toArray(null, true, false, false);
        } finally {
            @unlink($tmp);
        }
    }

    private function seedAgencyUser(): array
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

        return [$agencyId, $user];
    }

    private function makeContact(int $agencyId, int $userId, array $attrs = []): Contact
    {
        $id = (int) DB::table('contacts')->insertGetId(array_merge([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $userId,
            'first_name' => 'Contact',
            'last_name'  => Str::random(5),
            'phone'      => '0821234567',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return Contact::withoutGlobalScopes()->findOrFail($id);
    }
}
