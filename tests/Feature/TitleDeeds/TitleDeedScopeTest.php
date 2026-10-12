<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Models\Branch;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * AT-450 - OWN / BRANCH / AGENCY enforced at the query layer, and the agency wall, by DIRECT URL (not just by absence from a
 * menu): list, detail, edit, update, archive, restore, export, scan download, receipt files, notify, handover, the poll
 * endpoint. own = the responsible agent OR the person who logged the deed.
 */
final class TitleDeedScopeTest extends TitleDeedTestCase
{
    private User $ownUser;
    private User $branchUser;
    private User $agencyUser;
    private User $foreigner;
    private TitleDeed $mine;        // responsible agent = ownUser, branch A
    private TitleDeed $loggedByMe;  // logged by ownUser for someone else, branch A
    private TitleDeed $colleagues;  // someone else's, branch A
    private TitleDeed $otherBranch; // branch B
    private TitleDeed $foreign;     // another agency

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $perms = ['access_title_deeds', 'title_deeds.view', 'title_deeds.create', 'title_deeds.edit', 'title_deeds.archive', 'title_deeds.notify', 'title_deeds.handover'];
        $this->ownUser = $this->userWith($perms, 'own', $this->branchA->id, name: 'Own');
        $this->branchUser = $this->userWith($perms, 'branch', $this->branchA->id, name: 'Branch');
        $this->agencyUser = $this->userWith($perms, 'all', $this->branchA->id, name: 'Agency');

        // The moment any role carries grants, a role with NONE is fail-closed: give the admin who builds the fixtures their reach.
        foreach (['properties.view', 'contacts.view', 'title_deeds.view'] as $key) {
            \App\Models\RolePermission::create(['role' => 'admin', 'permission_key' => $key, 'scope' => 'all', 'agency_id' => $this->agencyId]);
        }
        \App\Services\PermissionService::clearCache();

        $this->mine = $this->deed([], ['agent_id' => $this->ownUser->id, '_property' => $this->property('1 Own Street', 'Uvongo')]);
        // logged BY ownUser for a colleague's property (the property picker needs property rights this fixture role does not hold)
        $this->loggedByMe = $this->deed([], ['agent_id' => $this->agent->id, '_property' => $this->property('2 Logged Street', 'Uvongo')]);
        $this->loggedByMe->forceFill(['created_by' => $this->ownUser->id])->save();
        $this->colleagues = $this->deed([], ['agent_id' => $this->agent->id, '_property' => $this->property('3 Colleague Street', 'Uvongo')]);
        $this->otherBranch = $this->deed([], ['agent_id' => $this->agent->id, 'branch_id' => $this->branchB->id, '_property' => $this->property('4 Other Branch Street', 'Southbroom', branchId: $this->branchB->id)]);

        $other = $this->makeAgency('Cape Town');
        $otherBranch = Branch::create(['agency_id' => $other, 'name' => 'Sea Point']);
        $this->foreigner = $this->userWith($perms, 'all', $otherBranch->id, $other, 'Foreigner');
        $foreignAgent = User::factory()->create(['agency_id' => $other, 'branch_id' => $otherBranch->id, 'role' => 'agent']);
        // built directly: the service resolves the property through the creator's role, and a brand-new agency has no role grants yet
        $foreignProperty = $this->property('5 Foreign Street', 'Sea Point', $other, $otherBranch->id, $foreignAgent);
        $foreignOwner = $this->contact('Zara', 'Naidoo', agencyId: $other);
        $this->foreign = TitleDeed::create([
            'agency_id' => $other, 'branch_id' => $otherBranch->id, 'agent_id' => $foreignAgent->id, 'created_by' => $this->foreigner->id,
            'property_id' => $foreignProperty->id, 'property_address' => '5 Foreign Street, Sea Point', 'received_at' => now()->subDay()->toDateString(), 'status' => 'received',
        ]);
        \App\Models\TitleDeeds\TitleDeedOwner::create(['agency_id' => $other, 'title_deed_id' => $this->foreign->id, 'contact_id' => $foreignOwner->id, 'name_snapshot' => 'Zara Naidoo']);
    }

    private function listed(User $user): array
    {
        $html = $this->actingAs($user)->get(route('title-deeds.index'))->assertOk()->getContent();

        return collect(['1 Own Street', '2 Logged Street', '3 Colleague Street', '4 Other Branch Street', '5 Foreign Street'])
            ->filter(fn ($a) => str_contains($html, $a))->values()->all();
    }

    public function test_the_list_shows_exactly_what_the_scope_allows(): void
    {
        $this->assertSame(['1 Own Street', '2 Logged Street'], $this->listed($this->ownUser), 'own = responsible agent or the person who logged it');
        $this->assertSame(['1 Own Street', '2 Logged Street', '3 Colleague Street'], $this->listed($this->branchUser));
        $this->assertSame(['1 Own Street', '2 Logged Street', '3 Colleague Street', '4 Other Branch Street'], $this->listed($this->agencyUser), 'agency-wide, but never another agency');
        $this->assertSame(['5 Foreign Street'], $this->listed($this->foreigner));
    }

    public function test_a_direct_url_by_id_is_a_404_outside_the_scope_on_every_route(): void
    {
        $out = [
            [$this->ownUser, $this->colleagues], [$this->ownUser, $this->otherBranch], [$this->ownUser, $this->foreign],
            [$this->branchUser, $this->otherBranch], [$this->branchUser, $this->foreign],
            [$this->agencyUser, $this->foreign], [$this->foreigner, $this->mine],
        ];

        foreach ($out as [$user, $deed]) {
            $id = $deed->id;
            $this->actingAs($user)->get(route('title-deeds.show', $id))->assertNotFound();
            $this->actingAs($user)->get(route('title-deeds.edit', $id))->assertNotFound();
            $this->actingAs($user)->put(route('title-deeds.update', $id), ['notes' => 'x'])->assertNotFound();
            $this->actingAs($user)->delete(route('title-deeds.destroy', $id))->assertNotFound();
            $this->actingAs($user)->post(route('title-deeds.restore', $id))->assertNotFound();
            $this->actingAs($user)->get(route('title-deeds.scan.download', $id))->assertNotFound();
            $this->actingAs($user)->post(route('title-deeds.scan.upload', $id), ['scan' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertNotFound();
            $this->actingAs($user)->get(route('title-deeds.receipt.file', [$id, 'signature']))->assertNotFound();
            $this->actingAs($user)->post(route('title-deeds.notify', $id))->assertNotFound();
            $this->actingAs($user)->post(route('title-deeds.pin.resend', $id))->assertNotFound();
            $this->actingAs($user)->post(route('title-deeds.pin.new', $id))->assertNotFound();
            $this->actingAs($user)->get(route('title-deeds.handover', $id))->assertNotFound();
            $this->actingAs($user)->post(route('title-deeds.handover.submit', $id), ['method' => 'id_check'])->assertNotFound();
            $this->actingAs($user)->post(route('title-deeds.consent.request', $id), ['rep_name' => 'A B', 'rep_id_number' => '12345', 'rep_relationship' => 'Son'])->assertNotFound();
            $this->actingAs($user)->getJson(route('api.v1.title-deeds.consent-status', $id))->assertNotFound();
        }

        $row = TitleDeed::withoutGlobalScopes()->withTrashed()->find($this->colleagues->id);
        $this->assertSame('received', $row->status);
        $this->assertNull($row->deleted_at, 'nothing was archived by an out-of-scope request');
    }

    public function test_in_scope_users_reach_their_own_records(): void
    {
        $this->actingAs($this->ownUser)->get(route('title-deeds.show', $this->mine->id))->assertOk();
        $this->actingAs($this->ownUser)->get(route('title-deeds.show', $this->loggedByMe->id))->assertOk();
        $this->actingAs($this->branchUser)->get(route('title-deeds.show', $this->colleagues->id))->assertOk();
        $this->actingAs($this->agencyUser)->get(route('title-deeds.show', $this->otherBranch->id))->assertOk();
        $this->actingAs($this->agencyUser)->getJson(route('api.v1.title-deeds.consent-status', $this->otherBranch->id))->assertOk();
    }

    public function test_the_scan_download_is_scope_checked_and_audited(): void
    {
        $svc = app(\App\Services\TitleDeeds\TitleDeedService::class);
        $svc->attachScan($this->admin, $this->colleagues, UploadedFile::fake()->create('deed.pdf', 100, 'application/pdf'));
        $svc->attachScan($this->admin, $this->mine, UploadedFile::fake()->create('mine.pdf', 100, 'application/pdf'));

        $this->actingAs($this->ownUser)->get(route('title-deeds.scan.download', $this->colleagues->id))->assertNotFound();
        $this->actingAs($this->ownUser)->get(route('title-deeds.scan.download', $this->mine->id))->assertOk();
        $this->assertSame(1, \App\Models\TitleDeeds\TitleDeedAuditLog::where('title_deed_id', $this->mine->id)->where('event', 'scan_downloaded')->count());
    }

    public function test_the_csv_export_holds_only_what_the_scope_allows(): void
    {
        $csv = $this->actingAs($this->ownUser)->get(route('title-deeds.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('1 Own Street', $csv);
        $this->assertStringContainsString('2 Logged Street', $csv);
        $this->assertStringNotContainsString('3 Colleague Street', $csv);
        $this->assertStringNotContainsString('4 Other Branch Street', $csv);
        $this->assertStringNotContainsString('5 Foreign Street', $csv);

        $csv = $this->actingAs($this->agencyUser)->get(route('title-deeds.export'))->streamedContent();
        $this->assertStringContainsString('4 Other Branch Street', $csv);
        $this->assertStringNotContainsString('5 Foreign Street', $csv);
    }

    public function test_a_user_without_the_permission_is_forbidden_and_a_user_with_no_scope_sees_nothing(): void
    {
        $none = $this->userWith(['access_title_deeds'], 'all');
        $this->actingAs($none)->get(route('title-deeds.index'))->assertForbidden();   // no title_deeds.view

        $noScope = $this->userWith(['access_title_deeds', 'title_deeds.view'], 'nonsense');
        $this->assertSame([], $this->listed($noScope));
        $this->actingAs($noScope)->get(route('title-deeds.show', $this->mine->id))->assertNotFound();
    }

    public function test_the_branch_filter_cannot_widen_a_branch_scope(): void
    {
        $html = $this->actingAs($this->branchUser)->get(route('title-deeds.index', ['branch_id' => $this->branchB->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('4 Other Branch Street', $html);
    }

    public function test_the_foreign_agency_cannot_be_reached_by_the_pickers_either(): void
    {
        $this->actingAs($this->agencyUser)->getJson(route('title-deeds.search.contacts', ['q' => 'Naidoo']))->assertOk()->assertJsonCount(0, 'results');
        $this->actingAs($this->agencyUser)->getJson(route('title-deeds.search.properties', ['q' => 'Foreign Street']))->assertOk()->assertJsonCount(0, 'results');
    }
}
