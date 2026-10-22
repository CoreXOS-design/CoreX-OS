<?php

declare(strict_types=1);

namespace Tests\Feature\RentalNotices;

use App\Mail\Rentals\RentalNoticeMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\RentalNotice;
use App\Models\RentalNoticeDelivery;
use App\Models\RentalNoticeTemplate;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Permissions\RoleDefaultsResolver;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\LeaseNotices\BuildsNoticeFixture;
use Tests\TestCase;

/**
 * AT-495 - the Notices tabs are usable: each tab has its action (lease picker -> the existing screens), template rows download the original and
 * can be used, sent notices carry a delivery record (a contact with no email is NOT sent, visibly), resend, the role defaults.
 */
final class NoticesTabsActionsTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function agentWith(array $keys, string $scope = 'all', string $role = 'agent'): User
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'name' => 'Pieter Plain']);
        foreach ($keys as $key) {
            RolePermission::firstOrCreate(['role' => $role, 'permission_key' => $key], ['scope' => $scope]);
        }
        PermissionService::clearCache();

        return $user;
    }

    private function template(array $over = []): RentalNoticeTemplate
    {
        return RentalNoticeTemplate::create($over + [
            'agency_id' => $this->agency->id, 'name' => 'General notice', 'notice_type' => 'breach_other', 'is_active' => true,
            'body_html' => '<p>Dear {{tenant_name}}, this is a notice about {{property_address}}.</p>',
        ]);
    }

    private function send(array $over = []): \Illuminate\Testing\TestResponse
    {
        $tpl = $this->template();

        return $this->actingAs($this->agent)->post(route('corex.leases.notices.store', $this->lease), $over + [
            'rental_notice_template_id' => $tpl->id, 'send_to_tenant' => '1', 'send_to_landlord' => '1', 'intent' => 'send',
        ]);
    }

    // ── each tab has its action ─────────────────────────────────────────────

    public function test_every_tab_shows_its_action_to_someone_who_may_use_it(): void
    {
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index'))->assertOk()
            ->assertSee('data-qa="record-received-notice"', false)->assertSee('Record a received notice');
        $this->actingAs($this->agent)->get(route('corex.rental-notices.index'))->assertOk()
            ->assertSee('data-qa="send-notice"', false)->assertSee('Notices sent');
        $this->actingAs($this->agent)->get(route('corex.breaches.index'))->assertOk()
            ->assertSee('data-qa="issue-breach-notice"', false)->assertSee('Issue breach notice');
        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index'))->assertOk()->assertSee('+ Add Template');
    }

    public function test_the_buttons_are_not_offered_to_someone_who_may_only_read(): void
    {
        $reader = $this->agentWith(['leases.view', 'rental_notices.view', 'rental_breaches.view', 'rental_notices.review'], 'all', 'reader');

        $this->actingAs($reader)->get(route('corex.rental-notices.index'))->assertOk()->assertDontSee('data-qa="send-notice"', false);
        $this->actingAs($reader)->get(route('corex.breaches.index'))->assertOk()->assertDontSee('data-qa="issue-breach-notice"', false);
        // review lets him open the received tab, but he can still record one
        $this->actingAs($reader)->get(route('corex.lease-notices.index'))->assertOk()->assertSee('data-qa="record-received-notice"', false);
    }

    public function test_the_empty_states_carry_their_add_action(): void
    {
        $this->actingAs($this->agent)->get(route('corex.rental-notices.index'))->assertOk()
            ->assertSee('data-qa="rental-notices-empty"', false)->assertSee('data-qa="send-notice-empty"', false);
        $this->actingAs($this->agent)->get(route('corex.breaches.index'))->assertOk()->assertSee('data-qa="issue-breach-notice-empty"', false);
        $this->actingAs($this->agent)->get(route('corex.lease-notices.index'))->assertOk()->assertSee('data-qa="record-received-notice-empty"', false);
        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index'))->assertOk()
            ->assertSee('data-qa="templates-empty"', false)->assertSee('data-qa="template-add-empty"', false);

        // a filter that matches nothing says so instead of offering the add action
        $this->actingAs($this->agent)->get(route('corex.rental-notices.index', ['q' => 'nothing like this']))->assertOk()
            ->assertSee('No notices match these filters')->assertDontSee('data-qa="send-notice-empty"', false);
    }

    // ── the lease picker ────────────────────────────────────────────────────

    private function picker(User $user, array $query): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->getJson(route('api.v1.rentals.notices.leases', $query));
    }

    public function test_the_picker_finds_a_lease_by_tenant_property_or_lease_number(): void
    {
        $byTenant = $this->picker($this->agent, ['purpose' => 'sent', 'q' => 'Tina Tenant'])->assertOk()->json('leases');
        $this->assertSame([$this->lease->id], array_column($byTenant, 'id'));
        $this->assertSame('Lease #' . $this->lease->id, $byTenant[0]['number']);

        $this->assertSame([$this->lease->id], array_column($this->picker($this->agent, ['purpose' => 'sent', 'q' => 'Vlei Court'])->assertOk()->json('leases'), 'id'));
        $this->assertSame([$this->lease->id], array_column($this->picker($this->agent, ['purpose' => 'sent', 'q' => '#' . $this->lease->id])->assertOk()->json('leases'), 'id'));
        $this->assertSame([], $this->picker($this->agent, ['purpose' => 'sent', 'q' => 'zzz-no-such-thing'])->assertOk()->json('leases'));
        // blank = the most recent leases, never an error
        $this->assertCount(1, $this->picker($this->agent, ['purpose' => 'sent'])->assertOk()->json('leases'));
    }

    public function test_the_picker_leads_to_the_existing_screen_for_each_button(): void
    {
        $sent = $this->picker($this->agent, ['purpose' => 'sent', 'template' => 77])->json('leases.0.actions');
        $this->assertSame(route('corex.leases.notices.create', ['lease' => $this->lease->id, 'template' => 77]), $sent[0]['url']);

        $breach = $this->picker($this->agent, ['purpose' => 'breach'])->json('leases.0.actions');
        $this->assertSame(route('corex.leases.breaches.create', ['lease' => $this->lease->id]), $breach[0]['url']);

        $received = $this->picker($this->agent, ['purpose' => 'received'])->json('leases.0.actions');
        $this->assertSame(route('corex.leases.show', [$this->lease->id, 'action' => 'tenant-notice']), $received[0]['url']);
        $this->assertSame(route('corex.leases.show', [$this->lease->id, 'action' => 'landlord-notice']), $received[1]['url']);

        // the screens exist and open for this user
        $this->actingAs($this->agent)->get($sent[0]['url'])->assertOk();
        $this->actingAs($this->agent)->get($breach[0]['url'])->assertOk();
        $this->actingAs($this->agent)->get($received[0]['url'])->assertOk()->assertSee('lease-dialog-tenant-notice', false);
    }

    public function test_the_picker_says_why_a_lease_cannot_take_that_notice(): void
    {
        $this->lease->forceFill(['notice_date' => now()->toDateString()])->saveQuietly();
        $row = $this->picker($this->agent, ['purpose' => 'received'])->json('leases.0');
        $this->assertSame([], $row['actions']);
        $this->assertStringContainsString('already has an accepted notice', $row['note']);

        $this->lease->forceFill(['notice_date' => null, 'status' => 'expired'])->saveQuietly();
        $row = $this->picker($this->agent, ['purpose' => 'breach'])->json('leases.0');
        $this->assertSame([], $row['actions']);
        $this->assertStringContainsString('running lease', $row['note']);
    }

    public function test_the_picker_never_returns_another_agencys_lease_or_a_colleagues_under_own_scope(): void
    {
        // another agency's lease, same tenant name and address wording
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch2 = Branch::create(['agency_id' => $other->id, 'name' => 'Main', 'code' => 'C-' . $other->id, 'is_active' => true]);
        $theirs = Lease::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'branch_id' => $branch2->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 1,
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonths(11)->toDateString(), 'source' => 'manual', 'lease_type' => 'residential',
        ]);
        $ids = array_column($this->picker($this->agent, ['purpose' => 'sent'])->assertOk()->json('leases'), 'id');
        $this->assertContains($this->lease->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertSame([], $this->picker($this->agent, ['purpose' => 'sent', 'q' => '#' . $theirs->id])->json('leases'), 'not even by its number');

        // an "own" agent who is not on the lease sees nothing; the lease's own agent sees it
        $stranger = $this->agentWith(['leases.view', 'rental_notices.create'], 'own', 'own_agent');
        $this->assertSame([], $this->picker($stranger, ['purpose' => 'sent'])->assertOk()->json('leases'));
        $mine = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'own_agent']);
        $this->lease->forceFill(['owner_agent_user_id' => $mine->id])->saveQuietly();
        $this->assertSame([$this->lease->id], array_column($this->picker($mine, ['purpose' => 'sent'])->assertOk()->json('leases'), 'id'));
    }

    public function test_the_picker_checks_the_permission_of_its_purpose_and_a_known_purpose(): void
    {
        $viewer = $this->agentWith(['leases.view', 'rental_notices.view', 'rental_breaches.view', 'rental_notices.review'], 'all', 'viewer_only');
        $this->picker($viewer, ['purpose' => 'sent'])->assertForbidden();
        $this->picker($viewer, ['purpose' => 'breach'])->assertForbidden();
        $this->picker($viewer, ['purpose' => 'received'])->assertOk();
        $this->picker($this->agent, ['purpose' => 'whatever'])->assertStatus(422);
        $this->picker($this->agent, [])->assertStatus(422);
    }

    // ── templates: download the original / use ──────────────────────────────

    public function test_a_template_row_downloads_its_original_and_offers_use_this_template(): void
    {
        Storage::disk('local')->put('rental-notice-templates/1/abc-Breach.docx', 'PK-original-bytes');
        $tpl = $this->template(['name' => 'Pool breach', 'source_file_name' => 'Breach.docx', 'source_file_path' => 'rental-notice-templates/1/abc-Breach.docx']);
        $typed = $this->template(['name' => 'Typed one']);
        $vacate = $this->template(['name' => 'Vacate it', 'notice_type' => 'notice_to_vacate']);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index'))->assertOk()->getContent();
        $this->assertStringContainsString(route('corex.rental-notice-templates.download-original', $tpl->id), $html);
        $this->assertStringNotContainsString(route('corex.rental-notice-templates.download-original', $typed->id), $html, 'a typed template has no original to download');
        $this->assertStringContainsString('data-qa="template-download-original-unavailable"', $html, 'but its row still has the button, greyed, saying why on hover');
        $this->assertStringContainsString('No Word file was uploaded for this template', $html);
        preg_match_all('/<(?:a|button)\b[^>]*data-qa="template-use"[^>]*>/', $html, $uses);
        $this->assertCount(3, $uses[0], 'Use this template is on every row');
        foreach ($uses[0] as $tag) {
            $this->assertStringContainsString('corex-btn-outline text-xs', $tag, 'and it is the same standard button on each row');
        }
        $this->assertStringContainsString('data-qa="template-use"', $html);
        $this->assertStringContainsString(route('corex.breaches.index', ['tile' => 'reached']), $html, 'a notice to vacate is used from a breach whose date has been reached');
        $this->assertSame($vacate->notice_type, 'notice_to_vacate');

        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.download-original', $tpl->id))->assertOk()->assertDownload('Breach.docx');
        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.download-original', $typed->id))->assertNotFound();
    }

    public function test_the_original_of_another_agencys_template_is_a_404(): void
    {
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        Storage::disk('local')->put('rental-notice-templates/9/x.docx', 'PK');
        $theirs = RentalNoticeTemplate::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'name' => 'Theirs', 'notice_type' => 'breach_other', 'is_active' => true, 'body_html' => '<p>x</p>',
            'source_file_name' => 'x.docx', 'source_file_path' => 'rental-notice-templates/9/x.docx',
        ]);

        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.download-original', $theirs->id))->assertNotFound();
        // and someone without the templates permission cannot reach even their own
        $mine = $this->template(['source_file_name' => 'm.docx', 'source_file_path' => 'rental-notice-templates/1/m.docx']);
        Storage::disk('local')->put('rental-notice-templates/1/m.docx', 'PK');
        $noTemplates = $this->agentWith(['leases.view', 'rental_notices.view'], 'all', 'no_templates');
        $this->actingAs($noTemplates)->get(route('corex.rental-notice-templates.download-original', $mine->id))->assertForbidden();
    }

    public function test_use_this_template_arrives_with_the_template_chosen(): void
    {
        $a = $this->template(['name' => 'Alpha notice']);
        $b = $this->template(['name' => 'Beta notice', 'notice_type' => 'breach_payment']);

        $send = $this->actingAs($this->agent)->get(route('corex.leases.notices.create', ['lease' => $this->lease->id, 'template' => $b->id]))->assertOk()->getContent();
        $this->assertStringContainsString("templateId: '" . $b->id . "'", $send);

        $breach = $this->actingAs($this->agent)->get(route('corex.leases.breaches.create', ['lease' => $this->lease->id, 'template' => $b->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="' . $b->id . '"[^>]*selected/', $breach);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $a->id . '"[^>]*selected/', $breach);
    }

    public function test_the_templates_list_sorts_both_ways_and_filters_by_date_added(): void
    {
        $this->template(['name' => 'Aardvark']);
        $this->template(['name' => 'Zebra']);
        $asc = $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index', ['sort' => 'name', 'dir' => 'asc']))->getContent();
        $desc = $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index', ['sort' => 'name', 'dir' => 'desc']))->getContent();
        $this->assertLessThan(strpos($asc, 'Zebra'), strpos($asc, 'Aardvark'));
        $this->assertLessThan(strpos($desc, 'Aardvark'), strpos($desc, 'Zebra'));
        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index', ['sort' => 'no_such_column']))->assertOk();

        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index', ['date_to' => '2000-01-01']))->assertOk()->assertSee('No templates match this filter');
        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index', ['date_from' => now()->subDay()->toDateString()]))->assertOk()->assertSee('Aardvark');
    }

    // ── sending: the agent's mailbox, and who it did NOT reach ─────────────

    public function test_send_goes_through_the_agents_own_mailbox_dispatcher(): void
    {
        $dispatcher = \Mockery::mock(RentalMailDispatcher::class);
        $dispatcher->shouldReceive('send')->twice()->withArgs(fn ($to, $mail) => $mail instanceof RentalNoticeMail && $mail->sendingAgentId() === $this->agent->id);
        $this->app->instance(RentalMailDispatcher::class, $dispatcher);

        $this->send()->assertRedirect(route('corex.leases.show', $this->lease));

        $notice = RentalNotice::firstOrFail();
        $this->assertSame('sent', $notice->delivery_status);
        $this->assertSame(2, $notice->deliveries()->where('status', 'sent')->count());
        $this->assertFalse((new \ReflectionClass(RentalNoticeMail::class))->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class), 'a queued mail cannot go through a person\'s own mailbox');
    }

    public function test_a_shared_mailer_fallback_still_sends_as_the_agent(): void
    {
        $this->send(['send_to_landlord' => null])->assertRedirect();

        Mail::assertSent(RentalNoticeMail::class, fn (RentalNoticeMail $m) => $m->hasTo($this->tenant->email) && $m->sendingAgentId() === $this->agent->id && str_contains($m->render(), 'Anja Agent'));
    }

    public function test_a_contact_with_no_email_is_recorded_as_not_sent_not_as_sent(): void
    {
        $this->landlord->forceFill(['email' => null])->saveQuietly();

        $res = $this->send()->assertRedirect();

        $notice = RentalNotice::firstOrFail();
        $res->assertRedirect(route('corex.rental-notices.show', $notice));
        $res->assertSessionHas('error');
        $this->assertStringContainsString('NOT sent to Lenny Landlord', (string) session('error'));

        $this->assertSame('partial', $notice->delivery_status);
        $this->assertSame('sent', $notice->deliveries()->where('recipient_role', 'tenant')->value('status'));
        $landlord = $notice->deliveries()->where('recipient_role', 'landlord')->firstOrFail();
        $this->assertSame(RentalNoticeDelivery::NOT_SENT_NO_EMAIL, $landlord->status);
        $this->assertNull($landlord->recipient_email);

        Mail::assertSent(RentalNoticeMail::class, 1);
        Mail::assertSent(RentalNoticeMail::class, fn ($m) => $m->hasTo($this->tenant->email));

        $event = LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_SENT)->firstOrFail();
        $this->assertStringContainsString('sent to Tina Tenant', $event->description);
        $this->assertStringContainsString('NOT sent to Lenny Landlord (no email address on file)', $event->description);
        $this->assertStringNotContainsString('Tina Tenant, Lenny Landlord', $event->description, 'the log never claims a person was sent it when they were not');
        $this->assertSame(['Lenny Landlord'], $event->metadata['not_sent_no_email']);

        // and the screens say it
        $this->actingAs($this->agent)->get(route('corex.rental-notices.show', $notice))->assertOk()
            ->assertSee('NOT sent - no email address on file')->assertSee('Partly sent');
        $this->actingAs($this->agent)->get(route('corex.rental-notices.index'))->assertOk()->assertSee('Partly sent');
        $this->actingAs($this->agent)->get(route('corex.rental-notices.index', ['status' => 'partial']))->assertOk()->assertSee('data-notice-row="' . $notice->id . '"', false);
        $this->actingAs($this->agent)->get(route('corex.rental-notices.index', ['status' => 'sent']))->assertOk()->assertDontSee('data-notice-row="' . $notice->id . '"', false);
    }

    public function test_nobody_reached_is_not_sent_at_all(): void
    {
        $this->tenant->forceFill(['email' => null])->saveQuietly();
        $this->landlord->forceFill(['email' => null])->saveQuietly();

        $this->send()->assertSessionHas('error');

        $notice = RentalNotice::firstOrFail();
        $this->assertSame('not_sent', $notice->delivery_status);
        Mail::assertNothingSent();
        $this->assertStringContainsString('sent to no one', LeaseEvent::where('event_type', LeaseEvent::TYPE_NOTICE_SENT)->firstOrFail()->description);
    }

    public function test_a_failed_email_is_recorded_as_failed(): void
    {
        $dispatcher = \Mockery::mock(RentalMailDispatcher::class);
        $dispatcher->shouldReceive('send')->andThrow(new \RuntimeException('Connection refused by smtp.example.test'));
        $this->app->instance(RentalMailDispatcher::class, $dispatcher);

        $this->send(['send_to_landlord' => null])->assertSessionHas('error');

        $notice = RentalNotice::firstOrFail();
        $this->assertSame('not_sent', $notice->delivery_status);
        $d = $notice->deliveries()->firstOrFail();
        $this->assertSame(RentalNoticeDelivery::FAILED, $d->status);
        $this->assertStringContainsString('Connection refused', (string) $d->error);
        $this->assertStringContainsString('NOT sent to Tina Tenant (the email failed)', LeaseEvent::where('event_type', LeaseEvent::TYPE_NOTICE_SENT)->firstOrFail()->description);
    }

    // ── resend, archive, list ───────────────────────────────────────────────

    public function test_resend_emails_again_reads_the_addresses_afresh_and_is_logged(): void
    {
        $this->landlord->forceFill(['email' => null])->saveQuietly();
        $this->send();
        $notice = RentalNotice::firstOrFail();
        $this->assertSame('partial', $notice->delivery_status);

        // the landlord's address is added, then the notice is resent
        $this->landlord->forceFill(['email' => 'lenny@example.test'])->saveQuietly();
        Mail::fake();
        $this->actingAs($this->agent)->post(route('corex.rental-notices.resend', $notice))->assertSessionHas('success');

        Mail::assertSent(RentalNoticeMail::class, 2);
        Mail::assertSent(RentalNoticeMail::class, fn ($m) => $m->hasTo('lenny@example.test'));
        $notice->refresh();
        $this->assertSame('sent', $notice->delivery_status, 'the latest answer for each person decides');
        $this->assertSame(4, $notice->deliveries()->count(), 'every attempt is kept');
        $this->assertSame(2, LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_NOTICE_SENT)->count());
        $this->assertTrue((bool) LeaseEvent::where('event_type', LeaseEvent::TYPE_NOTICE_SENT)->latest('id')->first()->metadata['resend']);
    }

    public function test_resend_is_for_those_who_may_send_and_see_the_lease_and_never_without_a_filed_document(): void
    {
        $notice = RentalNotice::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_type' => 'breach_other', 'sent_at' => now(), 'sent_to_tenant' => true]);

        // another agency first (the agency context is remembered once a request has been made as someone else)
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch2 = Branch::create(['agency_id' => $other->id, 'name' => 'Main', 'code' => 'C-' . $other->id, 'is_active' => true]);
        $outsider = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch2->id, 'role' => 'admin']);
        $this->actingAs($outsider)->post(route('corex.rental-notices.resend', $notice))->assertNotFound();

        $viewer = $this->agentWith(['leases.view', 'rental_notices.view'], 'all', 'only_view');
        $this->actingAs($viewer)->post(route('corex.rental-notices.resend', $notice))->assertForbidden();

        $stranger = $this->agentWith(['leases.view', 'rental_notices.view', 'rental_notices.create'], 'own', 'own_sender');
        $this->actingAs($stranger)->post(route('corex.rental-notices.resend', $notice))->assertForbidden();

        $breachNotice = RentalNotice::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_type' => 'breach_other', 'sent_at' => now(), 'sent_to_tenant' => true, 'lease_breach_id' => 999]);
        Mail::fake();
        $this->actingAs($this->agent)->post(route('corex.rental-notices.resend', $breachNotice))->assertSessionHas('error');
        Mail::assertNothingSent();
    }

    public function test_the_sent_list_searches_sorts_filters_and_scopes(): void
    {
        $tpl = $this->template();
        $notice = RentalNotice::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_type' => 'breach_other', 'rental_notice_template_id' => $tpl->id,
            'sent_at' => now(), 'sent_to_tenant' => true, 'sent_by_user_id' => $this->agent->id, 'delivery_status' => 'partial',
        ]);

        $row = fn ($n) => 'data-notice-row="' . $n->id . '"';
        // another agency sees none of it, an "own" agent not on the lease sees none of it (checked first: the agency context sticks after a request)
        $outsiderAgency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch2 = Branch::create(['agency_id' => $outsiderAgency->id, 'name' => 'Main', 'code' => 'C-' . $outsiderAgency->id, 'is_active' => true]);
        $outsider = User::factory()->create(['agency_id' => $outsiderAgency->id, 'branch_id' => $branch2->id, 'role' => 'admin']);
        $this->assertStringNotContainsString($row($notice), $this->actingAs($outsider)->get(route('corex.rental-notices.index'))->getContent());
        $stranger = $this->agentWith(['leases.view', 'rental_notices.view'], 'own', 'own_viewer');
        $this->assertStringNotContainsString($row($notice), $this->actingAs($stranger)->get(route('corex.rental-notices.index'))->getContent());
        $this->actingAs($stranger)->get(route('corex.rental-notices.show', $notice))->assertForbidden();

        $other = $this->template(['name' => 'Second']);
        $old = RentalNotice::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_type' => 'breach_payment', 'rental_notice_template_id' => $other->id,
            'sent_at' => now()->subYear(), 'sent_to_tenant' => true, 'sent_by_user_id' => $this->agent->id,
        ]);
        $get = fn (array $q) => $this->actingAs($this->agent)->get(route('corex.rental-notices.index', $q))->assertOk()->getContent();

        $this->assertStringContainsString($row($notice), $get(['q' => 'Tina']));
        $this->assertStringContainsString($row($notice), $get(['q' => 'Vlei Court']));
        $this->assertStringContainsString($row($notice), $get(['q' => '#' . $this->lease->id]));
        $this->assertStringContainsString($row($old), $get(['q' => 'Second']), 'the document name is searchable');
        $this->assertStringNotContainsString($row($notice), $get(['q' => 'Second']));
        $this->assertStringNotContainsString($row($old), $get(['date_from' => now()->subMonth()->toDateString()]));
        $this->assertStringContainsString($row($old), $get(['date_to' => now()->subMonths(6)->toDateString()]));
        $this->assertStringContainsString($row($old), $get(['status' => 'unrecorded']), 'a notice from before deliveries were recorded');
        $this->assertStringNotContainsString($row($notice), $get(['status' => 'unrecorded']));
        $this->assertStringContainsString($row($old), $get(['notice_type' => 'breach_payment']));
        $this->assertStringNotContainsString($row($notice), $get(['notice_type' => 'breach_payment']));

        // default newest first; ascending flips it
        $html = $get([]);
        $this->assertLessThan(strpos($html, $row($old)), strpos($html, $row($notice)));
        $html = $get(['sort' => 'sent_at', 'dir' => 'asc']);
        $this->assertLessThan(strpos($html, $row($notice)), strpos($html, $row($old)));
        $get(['sort' => 'no_such_column']);
    }

    public function test_a_sent_notice_can_be_downloaded_archived_and_restored_never_deleted(): void
    {
        $this->send();
        $notice = RentalNotice::firstOrFail();
        $this->assertNotNull($notice->document_id);

        $this->actingAs($this->agent)->get(route('corex.rental-notices.download-document', $notice))->assertOk();
        $this->actingAs($this->agent)->get(route('corex.rental-notices.index'))->assertSee(route('corex.rental-notices.download-document', $notice), false)->assertSee('data-qa="notice-resend"', false);

        $this->actingAs($this->agent)->post(route('corex.rental-notices.archive', $notice))->assertRedirect(route('corex.rental-notices.index'));
        $this->assertSoftDeleted('rental_notices', ['id' => $notice->id]);
        $this->assertStringNotContainsString('data-notice-row="' . $notice->id . '"', $this->actingAs($this->agent)->get(route('corex.rental-notices.index'))->getContent());
        $this->assertStringContainsString('data-notice-row="' . $notice->id . '"', $this->actingAs($this->agent)->get(route('corex.rental-notices.index', ['archived' => 1]))->getContent());
        $this->actingAs($this->agent)->get(route('corex.rental-notices.show', $notice->id))->assertOk()->assertSee('(archived)');

        $this->actingAs($this->agent)->post(route('corex.rental-notices.restore', $notice->id))->assertRedirect();
        $this->assertNull($notice->fresh()->deleted_at);
    }

    // ── the lease screen's dialog ───────────────────────────────────────────

    public function test_the_lease_screen_dialog_records_a_notice_through_the_notice_cases_route(): void
    {
        $res = $this->actingAs($this->agent)->post(route('corex.leases.notice-cases.store', $this->lease), [
            'given_by' => 'tenant', 'notice_date' => now()->toDateString(), 'intended_end_date' => now()->addMonths(2)->toDateString(), 'reason' => 'Moving',
        ]);

        $notice = \App\Models\LeaseNotice::withoutGlobalScopes()->firstOrFail();
        $res->assertRedirect(route('corex.lease-notices.show', $notice->id));
        $this->assertSame('tenant', $notice->given_by);
        $this->assertSame('agent', $notice->via);
        $this->assertSame($this->agent->id, (int) $notice->recorded_by_user_id);
    }

    // ── role defaults and the back-fill ─────────────────────────────────────

    public function test_the_default_roles_carry_the_notice_permissions(): void
    {
        $defaults = config('corex-permissions.role_defaults');
        $all = array_column(config('corex-permissions.permissions'), 'key');

        $admin = RoleDefaultsResolver::keysForDef($defaults['admin'], $all);
        $this->assertContains('rental_notices.create', $admin);
        $this->assertContains('rental_notice_templates.manage_settings', $admin);

        $bm = RoleDefaultsResolver::keysForDef($defaults['branch_manager'], $all);
        $this->assertContains('rental_notices.create', $bm);
        $this->assertContains('rental_notice_templates.manage_settings', $bm);

        $agent = RoleDefaultsResolver::keysForDef($defaults['agent'], $all);
        $this->assertContains('rental_notices.create', $agent);
        $this->assertNotContains('rental_notice_templates.manage_settings', $agent);
    }

    public function test_the_backfill_gives_existing_agencies_the_same_and_is_idempotent_and_respects_a_revoke(): void
    {
        RolePermission::query()->delete();
        foreach (['admin' => 'all', 'branch_manager' => 'branch', 'agent' => 'own'] as $role => $scope) {
            RolePermission::create(['agency_id' => $this->agency->id, 'role' => $role, 'permission_key' => 'rental_notices.review', 'scope' => $scope]);
        }
        RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'viewer', 'permission_key' => 'rental_notices.view', 'scope' => 'all']);
        // someone deliberately took the agent's template right away before (soft-deleted) - it is not given back, and neither is create
        $revoked = RolePermission::create(['agency_id' => $this->agency->id, 'role' => 'agent', 'permission_key' => 'rental_notices.create', 'scope' => 'own']);
        $revoked->delete();

        $migration = require base_path('database/migrations/2026_11_27_100100_grant_rental_notice_send_and_template_permissions_at495.php');
        $migration->up();
        $migration->up();
        PermissionService::clearCache();

        $create = fn (string $role) => RolePermission::where('role', $role)->where('permission_key', 'rental_notices.create')->first();
        $manage = fn (string $role) => RolePermission::where('role', $role)->where('permission_key', 'rental_notice_templates.manage_settings')->first();
        $this->assertSame('all', $create('admin')->scope);
        $this->assertSame('branch', $create('branch_manager')->scope, 'same scope as the right it comes from');
        $this->assertNull($create('agent'), 'a deliberately revoked grant is not given back');
        $this->assertNull($create('viewer'));
        $this->assertSame('all', $manage('admin')->scope);
        $this->assertSame('branch', $manage('branch_manager')->scope);
        $this->assertNull($manage('agent'), 'only admin and branch manager get the templates');
        $this->assertSame(1, RolePermission::where('role', 'admin')->where('permission_key', 'rental_notices.create')->count());
    }
}
