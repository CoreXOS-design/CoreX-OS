<?php

namespace Tests\Feature\SoloAgent;

use App\Events\Platform\EsignDocumentCompleted;
use App\Events\SoloAgent\SoloAgentActivated;
use App\Events\SoloAgent\SoloAgentArchived;
use App\Events\SoloAgent\SoloAgentCreated;
use App\Events\SoloAgent\SoloAgentRestored;
use App\Events\SoloAgent\SoloAgentSuspended;
use App\Events\SoloAgent\SoloAgentUnsuspended;
use App\Models\Agency;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Template;
use App\Models\Role;
use App\Models\SoloAgentSignup;
use App\Models\User;
use App\Services\Billing\SubscriptionPricingService;
use App\Services\PlatformEsign\EsignService;
use App\Services\SoloAgent\SoloAgentProvisioner;
use App\Services\SoloAgent\SoloAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Solo Agent Accounts, build step 4 - the Solo Agents section, the agreement, the activation:
 * .ai/specs/solo-agent-accounts.md sections 4.2, 6 and 8.
 */
class SoloAgentSectionTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::query()->firstOrNew(['name' => 'super_admin', 'agency_id' => null]);
        $role->forceFill(['label' => 'System Owner', 'is_owner' => true, 'can_be_deleted' => false])->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'is_active' => 1]);
    }

    private function signUp(array $over = []): SoloAgentSignup
    {
        $this->n++;
        $r = app(SoloAgentProvisioner::class)->provision(array_merge([
            'agency_name'    => "Agent {$this->n} Realty",
            'name'           => "Agent Number{$this->n}",
            'email'          => "agent{$this->n}@solo.test",
            'cell'           => "082000{$this->n}000",
            'ffc_number'     => "FFC-100{$this->n}",
            'ffc_expires_on' => now()->addMonths(10)->toDateString(),
        ], $over));

        return $r['signup'];
    }

    /** The agency names on the page of results, in order - read from the view data (the owner's layout lists every agency in its switcher, so page text proves nothing). */
    private function names(\Illuminate\Testing\TestResponse $r): array
    {
        return $r->viewData('rows')->getCollection()->map(fn ($row) => $row->agency->name)->all();
    }

    private function template(array $roles = null): Template
    {
        return Template::create([
            'name' => 'Single-agent agreement', 'kind' => 'solo_agent_agreement', 'source' => 'web',
            'body' => "Agreement between CoreX and {{agency_name}} represented by {{agent_name}}.\nFee: {{monthly_fee}}.\nFFC {{agent_ffc_number}}.",
            'roles_json' => $roles ?? [['key' => 'r1', 'label' => 'Agent', 'order' => 1]],
            'version' => 1, 'is_active' => true,
        ]);
    }

    // ── Access ─────────────────────────────────────────────────────────────

    public function test_an_agency_admin_cannot_reach_the_section_or_any_action(): void
    {
        $signup = $this->signUp();
        $other  = Agency::create(['name' => 'Big Agency', 'slug' => 'big-agency']);
        $admin  = User::factory()->create(['role' => 'admin', 'agency_id' => $other->id, 'is_active' => 1]);

        $this->actingAs($admin);
        $this->get(route('admin.solo-agents.index'))->assertForbidden();
        $this->get(route('admin.solo-agents.create'))->assertForbidden();
        $this->get(route('admin.solo-agents.show', $signup->id))->assertForbidden();
        $this->post(route('admin.solo-agents.store'), [])->assertForbidden();
        $this->post(route('admin.solo-agents.suspend', $signup->id))->assertForbidden();
        $this->post(route('admin.solo-agents.archive', $signup->id))->assertForbidden();
        $this->post(route('admin.solo-agents.send-contract', $signup->id))->assertForbidden();
        $this->assertFalse($signup->fresh()->agency->isSuspended());
    }

    public function test_the_solo_agent_themselves_cannot_reach_the_section(): void
    {
        $signup = $this->signUp();
        $signup->user->forceFill(['is_active' => true])->save();

        $this->actingAs($signup->user->fresh())->get(route('admin.solo-agents.index'))->assertForbidden();
    }

    public function test_no_agency_role_is_granted_the_solo_agents_keys_by_default(): void
    {
        foreach (['solo_agents.view', 'solo_agents.create', 'solo_agents.manage', 'solo_agents.archive'] as $key) {
            $this->assertContains($key, array_column(config('corex-permissions.permissions'), 'key'));
            $this->assertContains($key, config('corex-permissions.role_defaults.admin.exclude'), "$key must stay out of the admin default");
            $this->assertContains($key, config('solo-agent.denied_permissions'));
        }
    }

    // ── Sign-up ────────────────────────────────────────────────────────────

    public function test_the_owner_signs_up_a_single_agent(): void
    {
        Event::fake([SoloAgentCreated::class]);

        $this->actingAs($this->owner())->post(route('admin.solo-agents.store'), [
            'agency_name' => 'Coastline Homes', 'name' => 'Sam Solo', 'email' => 'sam@coastline.test', 'cell' => '0821112222',
            'ffc_number' => 'FFC-777', 'ffc_expires_on' => '2027-12-31', 'agency_type' => 'rentals',
        ])->assertRedirect();

        $signup = SoloAgentSignup::first();
        $this->assertNotNull($signup);
        $this->assertSame('awaiting_contract', $signup->status);
        $this->assertSame('FFC-777', $signup->ffc_number);
        $this->assertSame('Coastline Homes', $signup->agency->name);
        $this->assertTrue($signup->agency->isSolo());
        $this->assertSame('rentals', $signup->agency->agency_type);
        $this->assertFalse((bool) $signup->user->is_active);
        $this->assertSame(1, $signup->events()->count());
        Event::assertDispatched(SoloAgentCreated::class);
    }

    public function test_agency_name_ffc_and_mobile_are_required_and_a_taken_email_is_refused(): void
    {
        $this->signUp(['email' => 'taken@solo.test']);

        $this->actingAs($this->owner())->post(route('admin.solo-agents.store'), [
            'name' => 'X', 'email' => 'taken@solo.test',
        ])->assertSessionHasErrors(['agency_name', 'cell', 'ffc_number', 'ffc_expires_on', 'email']);
    }

    // ── List: search / sort / filter / paginate / empty ────────────────────

    public function test_empty_state_with_the_sign_up_button(): void
    {
        $this->actingAs($this->owner())->get(route('admin.solo-agents.index'))
            ->assertOk()->assertSee('No single agents yet.')->assertSee('Sign up a single agent');
    }

    public function test_search_finds_by_each_named_field(): void
    {
        $a = $this->signUp(['agency_name' => 'Zebra Properties', 'name' => 'Zed Zebra', 'email' => 'zed@zebra.test', 'cell' => '0719998888', 'ffc_number' => 'ZZ-555']);
        $this->signUp(['agency_name' => 'Other Realty', 'name' => 'Olive Other', 'email' => 'olive@other.test', 'cell' => '0611110000', 'ffc_number' => 'OO-111']);
        $this->actingAs($this->owner());

        foreach (['Zebra Prop', 'Zed Zebra', 'zed@zebra', '0719998', 'ZZ-555'] as $term) {
            $this->assertSame(['Zebra Properties'], $this->names($this->get(route('admin.solo-agents.index', ['q' => $term]))->assertOk()), "search for $term");
        }
        $this->get(route('admin.solo-agents.index', ['q' => 'nothing-matches-this']))->assertOk()->assertSee('No single agents match those filters.');
        $this->assertSame($a->id, SoloAgentSignup::where('ffc_number', 'ZZ-555')->value('id'));
    }

    public function test_every_sort_column_works_and_the_default_is_newest_first(): void
    {
        $first  = $this->signUp(['agency_name' => 'Aaa First']);
        $second = $this->signUp(['agency_name' => 'Bbb Second']);
        SoloAgentSignup::whereKey($first->id)->update(['created_at' => now()->subDays(5)]);
        $this->actingAs($this->owner());

        $this->assertSame(['Bbb Second', 'Aaa First'], $this->names($this->get(route('admin.solo-agents.index'))->assertOk()));
        $this->assertSame(['Aaa First', 'Bbb Second'], $this->names($this->get(route('admin.solo-agents.index', ['sort' => 'agency', 'dir' => 'asc']))->assertOk()));

        foreach (['agency', 'agent', 'email', 'mobile', 'ffc', 'ffc_exp', 'type', 'created', 'contract', 'status', 'login', 'listings', 'deals', 'ai'] as $col) {
            foreach (['asc', 'desc'] as $dir) {
                $this->get(route('admin.solo-agents.index', ['sort' => $col, 'dir' => $dir]))->assertOk();
            }
        }
        $this->get(route('admin.solo-agents.index', ['sort' => 'DROP TABLE users']))->assertOk(); // junk falls back to the default
    }

    public function test_filters_by_status_what_they_do_ffc_expiry_and_signup_dates(): void
    {
        $awaiting = $this->signUp(['agency_name' => 'Awaiting Co']);
        $active   = $this->signUp(['agency_name' => 'Active Co', 'agency_type' => 'sales', 'ffc_expires_on' => now()->addDays(20)->toDateString()]);
        $active->update(['status' => 'active']);
        $susp     = $this->signUp(['agency_name' => 'Suspended Co']);
        $susp->update(['status' => 'active']);
        $susp->agency->suspend();
        $arch     = $this->signUp(['agency_name' => 'Archived Co']);
        app(SoloAgentService::class)->archive($arch, $this->owner()->id);
        SoloAgentSignup::whereKey($awaiting->id)->update(['created_at' => '2026-01-10 10:00:00']);
        $this->actingAs($this->owner());

        $only = fn (array $q, array $expect) => $this->assertSame($expect, collect($this->names($this->get(route('admin.solo-agents.index', $q))->assertOk()))->sort()->values()->all(), json_encode($q));

        $only(['status' => 'awaiting_contract'], ['Awaiting Co']);
        $only(['status' => 'active'], ['Active Co']);
        $only(['status' => 'suspended'], ['Suspended Co']);
        $only(['status' => 'archived'], ['Archived Co']);
        $only(['does' => 'sales'], ['Active Co']);
        $only(['ffc_days' => '30'], ['Active Co']);
        $only(['from' => '2026-01-01', 'to' => '2026-01-31'], ['Awaiting Co']);

        // Archived accounts stay out of the default list.
        $this->assertNotContains('Archived Co', $this->names($this->get(route('admin.solo-agents.index'))->assertOk()));
    }

    public function test_pagination_is_25_by_default_and_selectable(): void
    {
        foreach (range(1, 27) as $i) {
            $this->signUp(['agency_name' => sprintf('Paged Agency %02d', $i)]);
        }
        $this->actingAs($this->owner());

        $page = $this->names($this->get(route('admin.solo-agents.index', ['sort' => 'agency', 'dir' => 'asc']))->assertOk());
        $this->assertCount(25, $page);
        $this->assertSame('Paged Agency 25', end($page));
        $this->assertCount(27, $this->names($this->get(route('admin.solo-agents.index', ['sort' => 'agency', 'dir' => 'asc', 'per_page' => 50]))->assertOk()));
        $this->assertSame(['Paged Agency 26', 'Paged Agency 27'], $this->names($this->get(route('admin.solo-agents.index', ['sort' => 'agency', 'dir' => 'asc', 'page' => 2]))->assertOk()));
    }

    public function test_the_list_shows_usage_and_the_ai_allowance(): void
    {
        $signup = $this->signUp();
        $this->actingAs($this->owner())->get(route('admin.solo-agents.index'))->assertOk()->assertSee('0 of 5');
        $this->actingAs($this->owner())->get(route('admin.solo-agents.show', $signup->id))->assertOk()->assertSee('0 of 5')->assertSee('Timeline');
    }

    // ── The agreement and activation ───────────────────────────────────────

    public function test_sending_without_a_template_says_what_to_do(): void
    {
        $signup = $this->signUp();

        $this->actingAs($this->owner())->post(route('admin.solo-agents.send-contract', $signup->id))
            ->assertRedirect(route('admin.solo-agents.show', $signup->id))
            ->assertSessionHas('error');
        $this->assertNull($signup->fresh()->agreement_document_id);
    }

    public function test_sending_creates_the_agreement_for_this_agent_with_the_flat_fee_in_its_wording(): void
    {
        $this->template();
        $signup = $this->signUp(['name' => 'Sam Solo', 'email' => 'sam@solo.test']);

        $this->actingAs($this->owner())->post(route('admin.solo-agents.send-contract', $signup->id))->assertSessionHas('success');

        $signup = $signup->fresh();
        $doc = Document::withoutGlobalScopes()->find($signup->agreement_document_id);
        $this->assertNotNull($doc);
        $this->assertSame($signup->agency_id, (int) $doc->agency_id);
        $this->assertNotNull($signup->contract_sent_at);
        $this->assertSame('sam@solo.test', $doc->signers()->first()->email);
        $this->assertStringContainsString('R 450 per month', (string) $doc->body_html_snapshot, 'the contract states the same fee billing charges');
        $this->assertStringContainsString($signup->ffc_number, (string) $doc->body_html_snapshot);
        $this->assertFalse((bool) $signup->user->fresh()->is_active, 'sending is not signing');
    }

    public function test_a_further_signer_role_is_filled_by_the_corex_user_sending(): void
    {
        $this->template([['key' => 'r1', 'label' => 'Agent', 'order' => 1], ['key' => 'r2', 'label' => 'CoreX', 'order' => 2]]);
        $signup = $this->signUp(['email' => 'agent@solo.test']);
        $owner  = $this->owner();

        $this->actingAs($owner)->post(route('admin.solo-agents.send-contract', $signup->id));

        $doc = Document::withoutGlobalScopes()->find($signup->fresh()->agreement_document_id);
        $emails = $doc->signers()->orderBy('sign_order')->pluck('email')->all();
        $this->assertSame(['agent@solo.test', strtolower($owner->email)], $emails);
    }

    public function test_sending_again_while_the_agreement_is_open_resends_it_instead_of_making_a_second(): void
    {
        $this->template();
        $signup = $this->signUp();
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('admin.solo-agents.send-contract', $signup->id));
        $firstDoc = $signup->fresh()->agreement_document_id;

        $this->actingAs($owner)->post(route('admin.solo-agents.send-contract', $signup->id))->assertSessionHas('success');

        $this->assertSame($firstDoc, $signup->fresh()->agreement_document_id);
        $this->assertSame(1, Document::withoutGlobalScopes()->where('agency_id', $signup->agency_id)->count());
    }

    public function test_signing_the_agreement_opens_the_account(): void
    {
        Event::fake([SoloAgentActivated::class]);
        $this->template();
        $signup = $this->signUp(['email' => 'open@solo.test']);
        app(SoloAgentService::class)->sendContract($signup, $this->owner()->id);
        $docId = $signup->fresh()->agreement_document_id;

        event(new EsignDocumentCompleted($docId, $signup->agency_id, 'solo_agent_agreement'));

        $signup = $signup->fresh();
        $this->assertSame('active', $signup->status);
        $this->assertNotNull($signup->contract_signed_at);
        $this->assertTrue((bool) $signup->user->fresh()->is_active);
        $this->assertNotNull($signup->user->fresh()->invited_at);
        Mail::assertSent(\App\Mail\UserInviteMail::class, fn ($m) => $m->hasTo('open@solo.test'));
        Event::assertDispatched(SoloAgentActivated::class);

        // Idempotent: a replay changes nothing and sends nothing more.
        event(new EsignDocumentCompleted($docId, $signup->agency_id, 'solo_agent_agreement'));
        Mail::assertSent(\App\Mail\UserInviteMail::class, 1);
    }

    public function test_a_different_document_or_kind_never_opens_the_account(): void
    {
        $this->template();
        $signup = $this->signUp();
        app(SoloAgentService::class)->sendContract($signup, $this->owner()->id);

        event(new EsignDocumentCompleted(999999, $signup->agency_id, 'solo_agent_agreement'));       // not the agreement we sent
        event(new EsignDocumentCompleted($signup->fresh()->agreement_document_id, $signup->agency_id, 'other'));  // right id, wrong kind

        $this->assertSame('awaiting_contract', $signup->fresh()->status);
        $this->assertFalse((bool) $signup->user->fresh()->is_active);
    }

    public function test_an_archived_account_is_not_opened_by_a_late_signature(): void
    {
        $this->template();
        $signup = $this->signUp();
        $owner = $this->owner();
        app(SoloAgentService::class)->sendContract($signup, $owner->id);
        $docId = $signup->fresh()->agreement_document_id;
        app(SoloAgentService::class)->archive($signup->fresh(), $owner->id);

        event(new EsignDocumentCompleted($docId, $signup->agency_id, 'solo_agent_agreement'));

        $this->assertSame('awaiting_contract', SoloAgentSignup::withTrashed()->find($signup->id)->status);
        $this->assertFalse((bool) $signup->user->fresh()->is_active);
    }

    public function test_the_engine_announces_a_completed_document(): void
    {
        Event::fake([EsignDocumentCompleted::class]);
        $tpl = $this->template();
        $signup = $this->signUp();
        app(SoloAgentService::class)->sendContract($signup, $this->owner()->id);
        $doc = Document::withoutGlobalScopes()->find($signup->fresh()->agreement_document_id);

        app(EsignService::class)->complete($doc);

        Event::assertDispatched(EsignDocumentCompleted::class, fn ($e) => $e->documentId === $doc->id && $e->templateKind === 'solo_agent_agreement' && $e->agencyId === $signup->agency_id);
    }

    public function test_the_setup_email_can_be_resent_only_while_the_password_is_not_set(): void
    {
        $this->template();
        $signup = $this->signUp(['email' => 'pw@solo.test']);
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('admin.solo-agents.resend-setup', $signup->id))->assertSessionHas('error'); // not signed yet
        app(SoloAgentService::class)->activate($signup->fresh());

        $this->actingAs($owner)->post(route('admin.solo-agents.resend-setup', $signup->id))->assertSessionHas('success');
        Mail::assertSent(\App\Mail\UserInviteMail::class, 2);

        $signup->user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($owner)->post(route('admin.solo-agents.resend-setup', $signup->id))->assertSessionHas('error');
    }

    // ── The contract gate on sign-in ───────────────────────────────────────

    public function test_an_unsigned_agent_cannot_sign_in_even_after_a_password_reset(): void
    {
        $signup = $this->signUp(['email' => 'early@solo.test']);
        // Worst case: they reset the password themselves through the forgotten-password link.
        $signup->user->forceFill(['password' => 'secret-pass-1', 'email_verified_at' => now()])->save();

        $this->post('/login', ['email' => 'early@solo.test', 'password' => 'secret-pass-1'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('waiting for your signed agreement', session('errors')->first('email'));
        $user = $signup->user->fresh();
        $this->assertFalse((bool) $user->is_active, 'a first sign-in must not activate an unsigned account');
        $this->assertNull($user->first_login_at);
    }

    public function test_after_signing_the_agent_can_sign_in(): void
    {
        $signup = $this->signUp(['email' => 'late@solo.test']);
        $signup->user->forceFill(['password' => 'secret-pass-1', 'email_verified_at' => now()])->save();
        app(SoloAgentService::class)->activate($signup->fresh());

        $this->post('/login', ['email' => 'late@solo.test', 'password' => 'secret-pass-1'])->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    // ── Edit / suspend / archive / restore ─────────────────────────────────

    public function test_edit_details_changes_the_agency_name_agent_and_ffc(): void
    {
        $signup = $this->signUp();
        $this->actingAs($this->owner())->put(route('admin.solo-agents.update', $signup->id), [
            'agency_name' => 'Renamed Realty', 'trading_name' => 'Renamed', 'name' => 'New Name', 'cell' => '0829990000',
            'ffc_number' => 'NEW-1', 'ffc_expires_on' => '2028-01-01',
        ])->assertRedirect(route('admin.solo-agents.show', $signup->id));

        $signup = $signup->fresh();
        $this->assertSame('Renamed Realty', $signup->agency->name);
        $this->assertSame('New Name', $signup->user->name);
        $this->assertSame('NEW-1', $signup->ffc_number);
        $this->assertSame('edited', $signup->events()->first()->event);
    }

    public function test_suspend_and_unsuspend_use_the_agencys_own_suspension(): void
    {
        Event::fake([SoloAgentSuspended::class, SoloAgentUnsuspended::class]);
        $signup = $this->signUp();
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('admin.solo-agents.suspend', $signup->id), ['reason' => 'Non-payment']);
        $this->assertTrue($signup->agency()->first()->isSuspended());
        $this->assertSame('suspended', $signup->fresh()->displayStatus());
        $this->assertSame('Non-payment', $signup->events()->first()->reason);

        $this->actingAs($owner)->post(route('admin.solo-agents.suspend', $signup->id))->assertSessionHas('error'); // already suspended

        $this->actingAs($owner)->post(route('admin.solo-agents.unsuspend', $signup->id));
        $this->assertFalse($signup->agency()->first()->isSuspended());
        Event::assertDispatched(SoloAgentSuspended::class);
        Event::assertDispatched(SoloAgentUnsuspended::class);
    }

    public function test_archive_is_a_soft_delete_that_closes_the_login_and_stops_billing_and_restore_reverses_it(): void
    {
        Event::fake([SoloAgentArchived::class, SoloAgentRestored::class]);
        $this->template();
        $signup = $this->signUp(['email' => 'arch@solo.test']);
        $owner = $this->owner();
        $svc = app(SoloAgentService::class);
        $svc->activate($signup->fresh());
        $pricing = app(SubscriptionPricingService::class);
        $this->assertSame(450.0, $pricing->quoteFor($signup->agency()->first())->payableZar);

        $this->actingAs($owner)->post(route('admin.solo-agents.archive', $signup->id), ['reason' => 'Left the industry'])->assertRedirect(route('admin.solo-agents.index'));

        $archived = SoloAgentSignup::withTrashed()->find($signup->id);
        $this->assertTrue($archived->trashed());
        $this->assertNotNull(User::withoutGlobalScopes()->find($signup->user_id), 'nothing is deleted');
        $this->assertFalse((bool) User::withoutGlobalScopes()->find($signup->user_id)->is_active);
        $this->assertSame('archived', $archived->displayStatus());
        $this->assertSame(0.0, $pricing->quoteFor($archived->agency)->payableZar, 'billing stops');

        $this->actingAs($owner)->post(route('admin.solo-agents.restore', $signup->id))->assertRedirect(route('admin.solo-agents.show', $signup->id));
        $restored = SoloAgentSignup::find($signup->id);
        $this->assertNotNull($restored);
        $this->assertSame('active', $restored->displayStatus());
        $this->assertTrue((bool) $restored->user->fresh()->is_active);
        $this->assertSame(450.0, $pricing->quoteFor($restored->agency)->payableZar);
        Event::assertDispatched(SoloAgentArchived::class);
        Event::assertDispatched(SoloAgentRestored::class);
    }

    public function test_restoring_an_account_that_was_suspended_before_archive_keeps_it_suspended(): void
    {
        $signup = $this->signUp();
        $owner = $this->owner();
        $svc = app(SoloAgentService::class);
        $svc->activate($signup->fresh());
        $svc->suspend($signup->fresh(), $owner->id);
        $svc->archive($signup->fresh(), $owner->id);

        $svc->restore(SoloAgentSignup::withTrashed()->find($signup->id), $owner->id);

        $this->assertSame('suspended', SoloAgentSignup::find($signup->id)->displayStatus());
    }

    public function test_an_unsigned_account_stays_switched_off_after_restore(): void
    {
        $signup = $this->signUp();
        $owner = $this->owner();
        $svc = app(SoloAgentService::class);
        $svc->archive($signup->fresh(), $owner->id);
        $svc->restore(SoloAgentSignup::withTrashed()->find($signup->id), $owner->id);

        $this->assertFalse((bool) $signup->user->fresh()->is_active);
        $this->assertSame('awaiting_contract', SoloAgentSignup::find($signup->id)->displayStatus());
    }

    // ── Agency Management keeps single agents out by default ───────────────

    public function test_agency_management_leaves_single_agents_out_unless_asked(): void
    {
        $this->signUp(['agency_name' => 'Lonely Solo Realty']);
        Agency::create(['name' => 'Proper Big Agency', 'slug' => 'proper-big-agency']);
        $this->actingAs($this->owner());

        $names = fn (array $q) => collect($this->get(route('agencies.index', $q))->assertOk()->viewData('agencies'))->pluck('name')->all();

        $this->assertContains('Proper Big Agency', $names([]));
        $this->assertNotContains('Lonely Solo Realty', $names([]));
        $this->assertSame(['Lonely Solo Realty'], $names(['type' => 'solo']));
        $this->assertContains('Lonely Solo Realty', $names(['type' => 'all']));
        $this->assertContains('Proper Big Agency', $names(['type' => 'all']));
    }

    public function test_the_sidebar_entry_is_there_for_the_owner(): void
    {
        $this->actingAs($this->owner())->get(route('admin.solo-agents.index'))->assertOk()->assertSee(route('admin.solo-agents.index'), false);
    }
}
