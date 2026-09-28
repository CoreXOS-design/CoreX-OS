<?php

namespace Tests\Feature\Importer;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\P24OnboardingPortal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-423 — importer.md §15: P24 agents whose email cannot identify ONE person.
 *
 * Rows with no email, a malformed email, the agency's Team Inbox shared-inbox address, or an
 * email repeated on several agents become "Choose who this is". The admin links each to an
 * existing person in the agency (sub-users included) or skips it — never a guess, never a
 * silent drop. The onboarding portal's listing picker offers everyone in the agency.
 */
class AgentImportChooseWhoTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $owner;
    private User $inbox;      // shared inbox account (agents@hfcoastal.co.za)
    private User $thandi;     // sub-user
    private User $sipho;      // sub-user
    private User $retha;      // ordinary agent with her own email

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $ownerRole = Role::firstOrCreate(['name' => 'system_owner'], ['label' => 'System Owner']);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::firstOrCreate(['name' => 'agent'], ['label' => 'Agent']);
        Role::clearCache();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach']);
        $this->owner = User::factory()->create(['role' => 'system_owner', 'agency_id' => null]);

        $this->inbox = User::factory()->create(['name' => 'HFC Reception', 'email' => 'agents@hfcoastal.co.za', 'role' => 'agent', 'agency_id' => $this->agency->id]);
        $this->agency->forceFill(['one_email_enabled' => true, 'one_email_user_id' => $this->inbox->id])->save();
        Agency::forgetFindMemo();

        $this->thandi = $this->subUser('Thandi Mokoena', 'thandi@hfcoastal');
        $this->sipho  = $this->subUser('Sipho Ngcobo', 'sipho@hfcoastal');
        $this->retha  = User::factory()->create(['name' => 'Retha Kelly', 'email' => 'retha@hfcoastal.co.za', 'role' => 'agent', 'agency_id' => $this->agency->id]);
    }

    private function subUser(string $name, string $username): User
    {
        $u = User::factory()->pendingInvite()->create(['name' => $name, 'email' => $username, 'role' => 'agent', 'agency_id' => $this->agency->id]);
        $u->forceFill(['is_sub_user' => true])->save();

        return $u;
    }

    /** P24 export as it really looks for a shared-inbox agency: blanks, repeats, one bad address. */
    private function upload(array $extraLines = []): P24ImportRun
    {
        $lines = array_merge([
            'AgentId,Firstname,Lastname,Status,SourceReference,MobileNumber,WorkNumber,EmailAddress,Qualification,About,Property24ProfilePictureURL,Published',
            '111,Thandi,Mokoena,Active,,0825550147,,,,,,1',                                   // no email
            '222,Sipho,Ngcobo,Active,,0835550123,,agents@hfcoastal.co.za,,,,1',                 // shared inbox
            '333,Lindiwe,Zulu,Active,,0725550188,,AGENTS@hfcoastal.co.za ,,,,1',               // shared inbox (caps + space)
            '444,Pieter,van Wyk,Active,,0845550101,,sales@hfcoastal.co.za,,,,1',               // repeated
            '555,Nomsa,Dlamini,Active,,0845550102,,sales@hfcoastal.co.za,,,,1',                // repeated
            '666,Craig,Botha,Active,,0615550199,,craig.botha@gmail.com,,,,1',                  // normal → create
            '777,Zanele,Khumalo,Active,,0615550200,,zanele@hfcoastal,,,,1',                    // malformed
        ], $extraLines);
        $tmp = tempnam(sys_get_temp_dir(), 'agents') . '.csv';
        file_put_contents($tmp, implode("\n", $lines));

        $this->actingAs($this->owner)->post(route('admin.importer.agents.upload'), [
            'agency_id'  => $this->agency->id,
            'agents_csv' => new UploadedFile($tmp, 'Agency-export-agents.csv', 'text/csv', null, true),
        ])->assertRedirect();

        return P24ImportRun::where('agency_id', $this->agency->id)->where('kind', 'agents')->latest('id')->firstOrFail();
    }

    private function rows(P24ImportRun $run)
    {
        return $run->rows()->where('row_type', 'agent')->get()->keyBy('external_id');
    }

    public function test_rows_email_cannot_settle_become_choose_who_this_is(): void
    {
        $run = $this->upload();
        $rows = $this->rows($run);

        foreach (['111', '222', '333', '444', '555', '777'] as $id) {
            $this->assertSame('choose', $rows[$id]->action, "row {$id}");
            $this->assertEmpty($rows[$id]->errors_json, "row {$id} is not an error any more");
            $this->assertSame('pending', $rows[$id]->status);
        }
        $this->assertSame('No email on this agent', $rows['111']->mapped_json['link_reason']);
        $this->assertSame('Uses your shared Team Inbox address', $rows['222']->mapped_json['link_reason']);
        $this->assertSame('Uses your shared Team Inbox address', $rows['333']->mapped_json['link_reason']);
        $this->assertSame('Same email as other agents in this file', $rows['444']->mapped_json['link_reason']);
        $this->assertSame('The email on this agent is not a valid address', $rows['777']->mapped_json['link_reason']);

        // A unique real email still behaves exactly as before.
        $this->assertSame('create', $rows['666']->action);
        $this->assertSame(6, $run->fresh()->counts_json['choose']);
        $this->assertSame(1, $run->fresh()->counts_json['new']);
    }

    public function test_the_preview_offers_everyone_including_sub_users_and_skip(): void
    {
        $run = $this->upload();

        $this->actingAs($this->owner)->get(route('admin.importer.preview', $run))
            ->assertOk()
            ->assertSee('6 agents need you to choose who they are')
            ->assertSee('Thandi Mokoena — thandi@hfcoastal (sub-user)')
            ->assertSee('Retha Kelly — retha@hfcoastal.co.za')
            ->assertSee("Skip — don't import this agent", false);
    }

    public function test_confirm_is_refused_until_every_row_has_an_answer(): void
    {
        $run = $this->upload();
        $rows = $this->rows($run);

        $this->actingAs($this->owner)->from(route('admin.importer.preview', $run))
            ->post(route('admin.importer.confirm', $run), ['links' => [$rows['111']->id => $this->thandi->id]])
            ->assertRedirect(route('admin.importer.preview', $run))
            ->assertSessionHasErrors('links');

        $this->assertSame('pending_confirm', $run->fresh()->status, 'nothing imported');
        $this->assertNull($this->thandi->fresh()->p24_agent_id);
    }

    public function test_one_person_cannot_be_two_p24_agents(): void
    {
        $run = $this->upload();
        $rows = $this->rows($run);

        $links = collect(['111', '222', '333', '444', '555', '777'])->mapWithKeys(fn ($id) => [$rows[$id]->id => 'skip'])->all();
        $links[$rows['111']->id] = $this->thandi->id;
        $links[$rows['222']->id] = $this->thandi->id;

        $this->actingAs($this->owner)->from(route('admin.importer.preview', $run))
            ->post(route('admin.importer.confirm', $run), ['links' => $links])
            ->assertSessionHasErrors('links');
        $this->assertStringContainsString('one person can only be one Property24 agent', session('errors')->first('links'));
    }

    public function test_someone_from_another_agency_cannot_be_chosen(): void
    {
        $run = $this->upload();
        $rows = $this->rows($run);
        $foreign = User::factory()->create(['email' => 'boss@otheragency.co.za', 'agency_id' => Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()])->id]);

        $links = collect(['111', '222', '333', '444', '555', '777'])->mapWithKeys(fn ($id) => [$rows[$id]->id => 'skip'])->all();
        $links[$rows['111']->id] = $foreign->id;

        $this->actingAs($this->owner)->from(route('admin.importer.preview', $run))
            ->post(route('admin.importer.confirm', $run), ['links' => $links])
            ->assertSessionHasErrors('links');
        $this->assertNull($foreign->fresh()->p24_agent_id);
    }

    public function test_linking_puts_the_p24_ids_on_the_chosen_people(): void
    {
        $run = $this->upload();
        $rows = $this->rows($run);

        $this->actingAs($this->owner)->post(route('admin.importer.confirm', $run), ['links' => [
            $rows['111']->id => $this->thandi->id,
            $rows['222']->id => $this->sipho->id,
            $rows['333']->id => 'skip',
            $rows['444']->id => $this->retha->id,
            $rows['555']->id => 'skip',
            $rows['777']->id => 'skip',
        ]])->assertRedirect(route('admin.importer.show', $run));

        $this->assertSame(111, (int) $this->thandi->fresh()->p24_agent_id);
        $this->assertSame(222, (int) $this->sipho->fresh()->p24_agent_id);
        $this->assertSame(444, (int) $this->retha->fresh()->p24_agent_id);

        $rows = $this->rows($run);
        $this->assertSame('link', $rows['111']->action);
        $this->assertSame('confirmed', $rows['111']->status);
        $this->assertSame($this->thandi->id, (int) $rows['111']->target_id);
        foreach (['333', '555', '777'] as $id) {
            $this->assertSame('excluded', $rows[$id]->status, "row {$id} skipped");
        }

        // Nobody was invented from a blank / shared / malformed email, and the shared inbox
        // account was not given anyone's P24 id.
        $this->assertNull($this->inbox->fresh()->p24_agent_id);
        $this->assertFalse(User::withoutGlobalScopes()->where('email', '')->exists());
        $this->assertFalse(User::withoutGlobalScopes()->where('email', 'zanele@hfcoastal')->exists());
        // The ordinary row still created its agent.
        $this->assertTrue(User::withoutGlobalScopes()->where('email', 'craig.botha@gmail.com')->exists());
    }

    public function test_a_re_import_pre_selects_whoever_already_holds_that_p24_id(): void
    {
        $this->thandi->forceFill(['p24_agent_id' => 111])->save();

        $run = $this->upload();

        $this->assertSame($this->thandi->id, (int) $this->rows($run)['111']->resolved_agent_id);
        $this->actingAs($this->owner)->get(route('admin.importer.preview', $run))
            ->assertOk()->assertSee('value="' . $this->thandi->id . '" selected', false);
    }

    public function test_an_agency_without_team_inbox_still_gets_the_choice_for_blank_and_repeated_emails(): void
    {
        $this->agency->forceFill(['one_email_enabled' => false, 'one_email_user_id' => null])->save();
        Agency::forgetFindMemo();

        $rows = $this->rows($this->upload());

        $this->assertSame('choose', $rows['111']->action);  // blank
        $this->assertSame('choose', $rows['444']->action);  // repeated
        // agents@ appears twice (rows 222 + 333), so it is still ambiguous — repeated, not "shared".
        $this->assertSame('Same email as other agents in this file', $rows['222']->mapped_json['link_reason']);
    }

    public function test_the_portal_listing_picker_offers_sub_users_and_accepts_them(): void
    {
        $run = P24ImportRun::create(['agency_id' => $this->agency->id, 'kind' => 'listings_images', 'status' => 'completed']);
        $row = P24ImportRow::create([
            'run_id' => $run->id, 'row_type' => 'listing', 'external_id' => '114001234',
            'mapped_json' => ['address' => '12 Marine Drive, Shelly Beach'],
            'errors_json' => ['Primary agent not resolved (p24_agent_id=111) — auto-assigned to HFC Reception; reassign if needed.'],
            'status' => 'pending',
        ]);
        $portal = P24OnboardingPortal::create([
            'agency_id' => $this->agency->id, 'token' => P24OnboardingPortal::generateToken(),
            'slug' => 'hfc-portal-' . P24OnboardingPortal::generateToken(), 'label' => 'HFC', 'expires_at' => now()->addDays(30),
        ]);

        $this->get(route('onboarding.portal.review', $portal->urlKey()))
            ->assertOk()->assertSee('Thandi Mokoena');

        $this->postJson(route('onboarding.portal.row.reassign', [$portal->urlKey(), $row->id]), ['user_id' => $this->thandi->id])
            ->assertOk()->assertJsonPath('agent_name', 'Thandi Mokoena');
        $this->assertSame($this->thandi->id, (int) $row->fresh()->resolved_agent_id);

        // An assistant is never offered or accepted.
        $assistant = User::factory()->create(['name' => 'Lerato Dube', 'agency_id' => $this->agency->id, 'is_assistant' => true, 'role' => 'assistant']);
        $this->postJson(route('onboarding.portal.row.reassign', [$portal->urlKey(), $row->id]), ['user_id' => $assistant->id])
            ->assertStatus(422);
    }
}
