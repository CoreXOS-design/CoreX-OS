<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\TvAccessCode;
use App\Models\TvMessage;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md step 8 / 10.2 (AT-454 lane, Johan 20 Oct 2026): TV codes and TV messages have ONE home, Settings > TV Display.
 *
 * Input paths proven: a whole-agency viewer makes the company code and any branch's code, revokes either, and works every message;
 * a branch manager works ONLY their own branch (a posted foreign branch id is ignored for codes and messages, a foreign code or message
 * id is refused, the company code is refused, agency-wide messages are shown but not editable); an agent gets 403 everywhere; another
 * agency's branch / code / message is a 404 and is never touched; messages are created, edited, archived (never deleted) and restored,
 * searched, filtered by branch and status, sorted, paged, and an empty list says so; a message ending before it starts is refused; with
 * the TV Display feature off the screen is closed; every old route name and URL (both old message screens, the four admin and two branch
 * code buttons) answers with a redirect to the new one; the public TV pages still work with a code made here.
 */
final class TvDisplaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $sea;
    private Branch $camps;
    private User $admin;
    private User $bm;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->sea = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->camps = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Camps Bay']);
        foreach (['admin', 'branch_manager', 'agent'] as $r) {
            Role::create(['name' => $r, 'label' => ucfirst($r), 'agency_id' => $this->agency->id]);
        }
        $this->admin = $this->user('admin', $this->sea);
        $this->bm = $this->user('branch_manager', $this->sea);
        $this->agent = $this->user('agent', $this->sea);

        foreach ([['admin', 'manage_tv_messages'], ['admin', 'view_company_stats'], ['branch_manager', 'manage_tv_messages'], ['branch_manager', 'view_branch_stats'], ['agent', 'view_deals']] as [$role, $key]) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        $this->tvDisplay(true);
    }

    private function user(string $role, Branch $b): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true]);
    }

    private function tvDisplay(bool $on): void
    {
        AgencyFeature::withTrashed()->updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'tv-display'], ['enabled' => $on, 'deleted_at' => null]);
        app(AgencyFeatureService::class)->forget($this->agency->id);
        PermissionService::clearCache();
    }

    private function message(?Branch $b, string $text, array $over = []): TvMessage
    {
        return TvMessage::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $b?->id, 'created_by_user_id' => $this->admin->id,
            'title' => null, 'message' => $text, 'display_area' => 'both', 'is_enabled' => true,
        ], $over));
    }

    private function foreign(): array
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'o-' . Str::random(6)]);
        $branch = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        $code = TvAccessCode::withoutGlobalScopes()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'code' => '111111', 'is_active' => true]);
        $msg = TvMessage::withoutGlobalScopes()->create(['agency_id' => $other->id, 'branch_id' => null, 'message' => 'Foreign note', 'display_area' => 'both', 'is_enabled' => true]);

        return [$branch, $code, $msg];
    }

    // ── codes ──

    public function test_a_whole_agency_viewer_makes_and_revokes_the_company_code_and_any_branch_code(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.codes.generate'), ['company' => 1])->assertRedirect();
        $company = TvAccessCode::forCompany()->where('is_active', true)->firstOrFail();
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.codes.generate'), ['company' => 1]);
        $this->assertFalse($company->fresh()->is_active, 'a new code replaces the live one');
        $this->assertSame(1, TvAccessCode::forCompany()->where('is_active', true)->count());

        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.codes.generate'), ['branch_id' => $this->camps->id]);
        $branchCode = TvAccessCode::where('branch_id', $this->camps->id)->where('is_active', true)->firstOrFail();

        $page = $this->actingAs($this->admin)->get(route('corex.settings.tv-display.index'))->assertOk();
        $page->assertSee($branchCode->code)->assertSee('Camps Bay')->assertSee('Company TV display');

        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.codes.revoke'), ['code_id' => $branchCode->id]);
        $this->assertFalse($branchCode->fresh()->is_active);
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.codes.revoke'), ['company' => 1]);
        $this->assertSame(0, TvAccessCode::forCompany()->where('is_active', true)->count());
    }

    public function test_a_branch_manager_works_only_their_own_branch(): void
    {
        // a posted foreign branch id is ignored: the code is made for their own branch
        $this->actingAs($this->bm)->post(route('corex.settings.tv-display.codes.generate'), ['branch_id' => $this->camps->id])->assertRedirect();
        $this->assertSame(1, TvAccessCode::where('branch_id', $this->sea->id)->where('is_active', true)->count());
        $this->assertSame(0, TvAccessCode::where('branch_id', $this->camps->id)->count());

        // the company code is not theirs
        $this->actingAs($this->bm)->post(route('corex.settings.tv-display.codes.generate'), ['company' => 1])->assertForbidden();
        $this->actingAs($this->bm)->post(route('corex.settings.tv-display.codes.revoke'), ['company' => 1])->assertForbidden();

        // another branch's code cannot be revoked by id
        $theirs = TvAccessCode::create(['branch_id' => $this->camps->id, 'code' => '222222', 'created_by' => $this->admin->id, 'is_active' => true]);
        $this->actingAs($this->bm)->post(route('corex.settings.tv-display.codes.revoke'), ['code_id' => $theirs->id])->assertForbidden();
        $this->assertTrue($theirs->fresh()->is_active);

        // the page shows their branch only
        $page = $this->actingAs($this->bm)->get(route('corex.settings.tv-display.index'))->assertOk();
        $page->assertDontSee('222222')->assertDontSee('Company TV display');

        // and "revoke" with no id revokes their own
        $this->actingAs($this->bm)->post(route('corex.settings.tv-display.codes.revoke'))->assertRedirect();
        $this->assertSame(0, TvAccessCode::where('branch_id', $this->sea->id)->where('is_active', true)->count());
    }

    public function test_an_agent_is_refused_everywhere(): void
    {
        $this->actingAs($this->agent)->get(route('corex.settings.tv-display.index'))->assertForbidden();
        $this->actingAs($this->agent)->post(route('corex.settings.tv-display.codes.generate'), ['branch_id' => $this->sea->id])->assertForbidden();
        $this->actingAs($this->agent)->post(route('corex.settings.tv-display.messages.store'), ['message' => 'x'])->assertForbidden();
        $this->assertSame(0, TvAccessCode::count() + TvMessage::count());
    }

    public function test_another_agencys_branch_code_and_message_are_never_reachable(): void
    {
        [$branch, $code, $msg] = $this->foreign();

        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.codes.generate'), ['branch_id' => $branch->id])->assertNotFound();
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.codes.revoke'), ['code_id' => $code->id])->assertNotFound();
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.messages.update', $msg->id), ['message' => 'hijacked'])->assertNotFound();
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.messages.archive', $msg->id))->assertNotFound();
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.messages.store'), ['message' => 'x', 'branch_id' => $branch->id])->assertSessionHasErrors('branch_id');

        $this->assertTrue(TvAccessCode::withoutGlobalScopes()->find($code->id)->is_active);
        $this->assertSame('Foreign note', TvMessage::withoutGlobalScopes()->find($msg->id)->message);
        $this->actingAs($this->admin)->get(route('corex.settings.tv-display.index'))->assertDontSee('Foreign note');
    }

    // ── messages ──

    public function test_messages_are_created_edited_archived_and_restored_never_deleted(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.messages.store'), ['message' => 'Welcome to the branch', 'branch_id' => $this->camps->id, 'display_area' => 'ticker', 'is_enabled' => 1])->assertRedirect(route('corex.settings.tv-display.index'));
        $m = TvMessage::where('message', 'Welcome to the branch')->firstOrFail();
        $this->assertSame($this->camps->id, (int) $m->branch_id);
        $this->assertSame($this->agency->id, (int) $m->agency_id);

        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.messages.update', $m->id), ['message' => 'Welcome, everyone', 'branch_id' => '', 'display_area' => 'hero', 'is_enabled' => 0])->assertRedirect();
        $m->refresh();
        $this->assertSame('Welcome, everyone', $m->message);
        $this->assertNull($m->branch_id, 'now agency-wide');
        $this->assertFalse($m->is_enabled);

        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.messages.archive', $m->id))->assertRedirect();
        $this->assertSoftDeleted('tv_messages', ['id' => $m->id]);
        $this->actingAs($this->admin)->get(route('corex.settings.tv-display.index', ['status' => 'archived']))->assertOk()->assertSee('Welcome, everyone')->assertSee('Restore');

        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.messages.restore', $m->id))->assertRedirect();
        $this->assertNull($m->fresh()->deleted_at);
    }

    public function test_a_message_ending_before_it_starts_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.messages.store'), ['message' => 'x', 'starts_at' => '2026-11-10', 'ends_at' => '2026-11-01'])->assertSessionHasErrors('ends_at');
        $this->assertSame(0, TvMessage::count());
    }

    public function test_a_branch_manager_writes_only_to_their_branch_and_only_edits_their_own_messages(): void
    {
        $this->actingAs($this->bm)->post(route('corex.settings.tv-display.messages.store'), ['message' => 'Sea Point open day', 'branch_id' => $this->camps->id])->assertRedirect();
        $mine = TvMessage::where('message', 'Sea Point open day')->firstOrFail();
        $this->assertSame($this->sea->id, (int) $mine->branch_id, 'a posted foreign branch is ignored');

        $theirs = $this->message($this->camps, 'Camps Bay only');
        $global = $this->message(null, 'Agency-wide notice');

        foreach ([$theirs, $global] as $m) {
            $this->actingAs($this->bm)->post(route('corex.settings.tv-display.messages.update', $m->id), ['message' => 'changed'])->assertForbidden();
            $this->actingAs($this->bm)->post(route('corex.settings.tv-display.messages.archive', $m->id))->assertForbidden();
        }
        $this->actingAs($this->bm)->post(route('corex.settings.tv-display.messages.update', $mine->id), ['message' => 'Sea Point open day, Saturday', 'branch_id' => $this->camps->id])->assertRedirect();
        $this->assertSame($this->sea->id, (int) $mine->fresh()->branch_id, 'cannot be moved to another branch or made agency-wide');

        $page = $this->actingAs($this->bm)->get(route('corex.settings.tv-display.index'))->assertOk();
        $page->assertSee('Sea Point open day, Saturday')->assertSee('Agency-wide notice')->assertSee('cannot be changed here')->assertDontSee('Camps Bay only');
    }

    public function test_search_filter_sort_paging_and_the_empty_state(): void
    {
        $this->message($this->sea, 'Alpha Sea Point note', ['title' => 'Zebra']);
        $this->message($this->camps, 'Bravo Camps Bay note', ['title' => 'Apple']);
        $this->message(null, 'Charlie for everyone', ['title' => 'Mango']);

        $titles = fn (array $q) => $this->actingAs($this->admin)->get(route('corex.settings.tv-display.index', $q))->assertOk()->viewData('messages')->pluck('message')->all();

        $this->assertSame(['Bravo Camps Bay note'], $titles(['q' => 'bravo']));
        $this->assertSame(['Charlie for everyone'], $titles(['branch' => 'all']));
        $this->assertSame(['Alpha Sea Point note'], $titles(['branch' => $this->sea->id]));
        $this->assertSame(['Bravo Camps Bay note', 'Charlie for everyone', 'Alpha Sea Point note'], $titles(['sort' => 'title']));

        $this->actingAs($this->admin)->get(route('corex.settings.tv-display.index', ['q' => 'nothing like this']))->assertSee('No messages match that search.');
        $this->actingAs($this->admin)->get(route('corex.settings.tv-display.index', ['status' => 'archived']))->assertSee('No archived messages.');

        for ($i = 0; $i < 22; $i++) {
            $this->message($this->sea, "Filler {$i}");
        }
        $page = $this->actingAs($this->admin)->get(route('corex.settings.tv-display.index'))->viewData('messages');
        $this->assertSame(25, $page->total());
        $this->assertCount(20, $page->items(), 'real pagination, 20 a page');
    }

    public function test_with_the_tv_display_feature_off_the_screen_is_closed(): void
    {
        $this->tvDisplay(false);
        $this->actingAs($this->admin)->get(route('corex.settings.tv-display.index'))->assertNotFound();
    }

    // ── old addresses, public TV, hub ──

    public function test_every_old_tv_address_redirects_and_keeps_working(): void
    {
        $m = $this->message($this->sea, 'Old route target');
        $to = fn (string $name, array $p = []) => route($name, $p);
        $as = $this->admin;

        $this->actingAs($as)->get(route('admin.tv-messages', ['status' => 'archived']))->assertStatus(301)->assertRedirect(route('corex.settings.tv-display.index', ['status' => 'archived']));
        $this->actingAs($as)->get(route('bm.tv-messages'))->assertStatus(301)->assertRedirect(route('corex.settings.tv-display.index'));
        foreach (['admin', 'bm'] as $who) {
            $this->actingAs($as)->post(route("{$who}.tv-messages.store"))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.messages.store'));
            $this->actingAs($as)->post(route("{$who}.tv-messages.update", $m->id))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.messages.update', ['tvMessage' => $m->id]));
            $this->actingAs($as)->post(route("{$who}.tv-messages.delete", $m->id))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.messages.archive', ['tvMessage' => $m->id]));
        }
        $this->actingAs($as)->post(route('admin.tv-messages.restore', $m->id))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.messages.restore', ['id' => $m->id]));
        $this->actingAs($as)->post(route('admin.tv-code.generate'))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.codes.generate'));
        $this->actingAs($as)->post(route('admin.tv-code.revoke'))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.codes.revoke'));
        $this->actingAs($as)->post(route('admin.tv-code.generate-company'))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.codes.generate', ['company' => 1]));
        $this->actingAs($as)->post(route('admin.tv-code.revoke-company'))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.codes.revoke', ['company' => 1]));
        $this->actingAs($this->bm)->post(route('bm.tv-code.generate'))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.codes.generate'));
        $this->actingAs($this->bm)->post(route('bm.tv-code.revoke'))->assertStatus(308)->assertRedirect($to('corex.settings.tv-display.codes.revoke'));
    }

    public function test_a_code_made_here_still_signs_a_tv_in(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.codes.generate'), ['branch_id' => $this->sea->id]);
        $code = TvAccessCode::where('branch_id', $this->sea->id)->where('is_active', true)->value('code');
        auth()->logout();

        $this->get(route('tv.index'))->assertOk();
        $this->post(route('tv.verify'), ['code' => $code])->assertRedirect(route('tv.display', ['code' => $code]));
        $this->assertNotNull(TvAccessCode::where('code', $code)->value('last_used_at'));

        // after a revoke the same code is refused
        $this->actingAs($this->admin)->post(route('corex.settings.tv-display.codes.revoke'), ['code_id' => TvAccessCode::where('code', $code)->value('id')]);
        auth()->logout();
        $this->post(route('tv.verify'), ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_the_settings_hub_lists_tv_display_and_p24_auto_import(): void
    {
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_settings', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();

        $html = $this->actingAs($this->admin)->get(route('corex.settings'))->assertOk()->getContent();
        $this->assertStringContainsString(route('corex.settings.tv-display.index'), $html);
        $this->assertStringContainsString(route('admin.minion.setup'), $html);
        $this->assertStringContainsString('P24 Auto-Import', $html);

        $this->tvDisplay(false);
        $html = $this->actingAs($this->admin)->get(route('corex.settings'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('corex.settings.tv-display.index'), $html, 'no door to a screen that is switched off');
    }
}
