<?php

namespace Tests\Feature\Importer;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the P24 agents importer's match-or-create-by-email rule (spec §4.1, §13 Q1):
 *  - unknown email                          → create a new inactive agent
 *  - email of an existing user in THIS agency → link (no duplicate, P24 ids only)
 *  - email of an existing user in ANOTHER agency → skip (multi-tenancy guard)
 *
 * The resolution is asserted at BOTH the preview stage (parse time, so the admin
 * sees the truth before confirming) and the import stage (the job's writes).
 */
class AgentImportMatchOrCreateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agencyA;
    private Agency $agencyB;
    private User $owner;
    private User $existingInA;
    private User $existingInB;

    protected function setUp(): void
    {
        parent::setUp();

        $ownerRole = Role::firstOrCreate(['name' => 'system_owner'], ['label' => 'System Owner']);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::firstOrCreate(['name' => 'agent'], ['label' => 'Agent']);
        Role::clearCache();

        $this->agencyA = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->agencyB = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        Branch::create(['agency_id' => $this->agencyA->id, 'name' => 'A Main']);
        Branch::create(['agency_id' => $this->agencyB->id, 'name' => 'B Main']);

        $this->owner = User::factory()->create(['role' => 'system_owner', 'agency_id' => null]);

        // An agent that already exists in the target agency — should be LINKED.
        $this->existingInA = User::factory()->create([
            'name'      => 'Existing In A',
            'email'     => 'linkme@test.com',
            'role'      => 'agent',
            'agency_id' => $this->agencyA->id,
        ]);

        // A user that exists under a DIFFERENT agency — must be SKIPPED, never touched.
        $this->existingInB = User::factory()->create([
            'name'      => 'Existing In B',
            'email'     => 'other@test.com',
            'role'      => 'agent',
            'agency_id' => $this->agencyB->id,
        ]);
    }

    public function test_preview_resolves_each_row_to_create_link_or_skip(): void
    {
        Storage::fake('local');

        $run = $this->uploadAgentsCsv();

        $this->assertSame('pending_confirm', $run->fresh()->status);

        $rows = $run->rows()->where('row_type', 'agent')->get()->keyBy(fn ($r) => $r->mapped_json['email']);
        $this->assertCount(3, $rows);

        // Unknown email → create.
        $this->assertSame('create', $rows['new@test.com']->action);
        $this->assertSame('pending', $rows['new@test.com']->status);
        $this->assertNull($rows['new@test.com']->resolved_agent_id);

        // Existing in this agency → link, pointing at the existing user.
        $this->assertSame('update', $rows['linkme@test.com']->action);
        $this->assertSame($this->existingInA->id, $rows['linkme@test.com']->resolved_agent_id);

        // Existing in another agency → skip, pointing at the foreign user.
        $this->assertSame('skip', $rows['other@test.com']->action);
        $this->assertSame($this->existingInB->id, $rows['other@test.com']->resolved_agent_id);

        $counts = $run->fresh()->counts_json;
        $this->assertSame(1, $counts['new']);
        $this->assertSame(1, $counts['link']);
        $this->assertSame(1, $counts['skip']);
        $this->assertSame(0, $counts['errors']);
    }

    public function test_confirm_creates_links_and_skips_without_duplicates(): void
    {
        Storage::fake('local');

        $run = $this->uploadAgentsCsv();
        $skipRow = $run->rows()->where('action', 'skip')->firstOrFail();

        // Mirror the preview default: the skip row is pre-excluded.
        $this->actingAs($this->owner)
            ->post(route('admin.importer.confirm', $run), ['excluded' => [$skipRow->id]])
            ->assertRedirect(route('admin.importer.show', $run));

        // New agent created under agency A, inactive, no usable password.
        $created = User::withoutGlobalScopes()->where('email', 'new@test.com')->first();
        $this->assertNotNull($created);
        $this->assertSame($this->agencyA->id, (int) $created->agency_id);
        $this->assertSame('agent', $created->role);
        $this->assertFalse((bool) $created->is_active);

        // Existing-in-A linked, not duplicated: still exactly one row, P24 id stamped.
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'linkme@test.com')->count());
        $this->assertSame(222, (int) $this->existingInA->fresh()->p24_agent_id);

        // Skip row excluded; the foreign user is untouched and not cloned into agency A.
        $this->assertSame('excluded', $skipRow->fresh()->status);
        $this->assertSame(1, User::withoutGlobalScopes()->where('email', 'other@test.com')->count());
        $this->assertSame($this->agencyB->id, (int) $this->existingInB->fresh()->agency_id);
    }

    /**
     * Uploads a 3-row agents CSV (create / link / skip) for Agency A and returns the run.
     */
    private function uploadAgentsCsv(): P24ImportRun
    {
        $header = 'AgentId,Firstname,Lastname,Status,SourceReference,MobileNumber,WorkNumber,EmailAddress,Qualification,About,Property24ProfilePictureURL,Published';
        $lines = [
            $header,
            '111,New,Agent,Active,CoreX-1,0820000001,0310000001,new@test.com,,,,1',
            '222,Link,Agent,Active,CoreX-2,0820000002,0310000002,linkme@test.com,,,,1',
            '333,Other,Agent,Active,CoreX-3,0820000003,0310000003,other@test.com,,,,1',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'agents') . '.csv';
        file_put_contents($tmp, implode("\n", $lines));
        $upload = new UploadedFile($tmp, 'agents.csv', 'text/csv', null, true);

        $resp = $this->actingAs($this->owner)->post(route('admin.importer.agents.upload'), [
            'agency_id'  => $this->agencyA->id,
            'agents_csv' => $upload,
        ]);
        $resp->assertRedirect();

        return P24ImportRun::where('agency_id', $this->agencyA->id)->where('kind', 'agents')->latest('id')->firstOrFail();
    }
}
