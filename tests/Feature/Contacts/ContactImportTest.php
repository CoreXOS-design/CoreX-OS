<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Contact import — streams rows via OpenSpout (constant memory) and resolves
 * each row's "Agents" column to the owning agent (created_by_user_id). This is
 * the round-trip twin of the export and the agent-assignment behaviour the
 * feature is built around.
 */
final class ContactImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_assigns_contacts_to_the_agent_named_in_the_agents_column(): void
    {
        [$agencyId, $importer] = $this->seedAgencyUser();
        $agentB = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'role' => 'agent', 'name' => 'Retha Botha',
        ]);

        $file = $this->makeXlsx([
            ['Name', 'Surname', 'Cell', 'Email', 'Agents'],
            ['Jane', 'Buyer', '0820000001', 'jane@example.com', 'Retha Botha'],
            ['John', 'Owner', '0820000002', 'john@example.com', ''], // no agent → falls back to importer
        ]);

        $this->actingAs($importer)
            ->post(route('corex.contacts.import'), ['file' => $file])
            ->assertRedirect(route('corex.contacts.index'))
            ->assertSessionHas('success');

        $jane = Contact::withoutGlobalScopes()->where('email', 'jane@example.com')->first();
        $this->assertNotNull($jane);
        $this->assertSame($agentB->id, $jane->created_by_user_id, 'contact filed under the named agent');
        $this->assertSame($agentB->branch_id, $jane->branch_id, 'inherits the agent branch');

        $john = Contact::withoutGlobalScopes()->where('email', 'john@example.com')->first();
        $this->assertNotNull($john);
        $this->assertSame($importer->id, $john->created_by_user_id, 'blank agent falls back to importer');
    }

    public function test_import_skips_duplicates_and_empty_rows(): void
    {
        [$agencyId, $importer] = $this->seedAgencyUser();
        DB::table('contacts')->insert([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $importer->id,
            'first_name' => 'Existing', 'last_name' => 'Dupe', 'phone' => '0829999999', 'email' => 'dupe@example.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $file = $this->makeXlsx([
            ['Name', 'Surname', 'Cell', 'Email'],
            ['Existing', 'Dupe', '0829999999', 'dupe@example.com'], // duplicate phone → skipped
            ['', '', '', ''],                                        // empty → skipped
            ['Fresh', 'Lead', '0820000003', 'fresh@example.com'],   // created
        ]);

        $this->actingAs($importer)->post(route('corex.contacts.import'), ['file' => $file])
            ->assertRedirect(route('corex.contacts.index'));

        $this->assertSame(1, Contact::withoutGlobalScopes()->where('first_name', 'Fresh')->count());
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('phone', '0829999999')->count(), 'no duplicate created');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeXlsx(array $rows): UploadedFile
    {
        $path   = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues($r));
        }
        $writer->close();

        return new UploadedFile(
            $path,
            'contacts.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true // test mode — skip is_uploaded_file()
        );
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
}
