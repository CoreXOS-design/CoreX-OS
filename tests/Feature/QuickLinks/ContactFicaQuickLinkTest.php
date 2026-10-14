<?php

declare(strict_types=1);

namespace Tests\Feature\QuickLinks;

use App\Models\RolePermission;
use App\Mail\FicaRequestMail;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaResendLog;
use App\Models\FicaSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Quick Links Wave 1 (.ai/specs/quick-links.md §4): the FICA chip + Send/Resend on the contact, for a DEFAULT AGENT
 * (holds compliance.fica.send, does NOT hold access_compliance), rendered and posted for real over HTTP.
 *
 * Seeding mirrors FeaturesPageTest: without real grants PermissionService falls back to allow-all and the "agent can/can't"
 * assertions would pass for the wrong reason.
 */
final class ContactFicaQuickLinkTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $agent;
    private User $otherAgent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $roles = [
            ['name' => 'super_admin', 'label' => 'System Owner',  'is_owner' => 1, 'can_be_deleted' => 0, 'sort_order' => 1],
            ['name' => 'admin',       'label' => 'Administrator', 'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 2],
            ['name' => 'agent',       'label' => 'Agent',         'is_owner' => 0, 'can_be_deleted' => 1, 'sort_order' => 4],
        ];
        foreach ($roles as &$r) {
            $r += ['agency_id' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('roles')->insert($roles);
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'QL ' . uniqid(), 'slug' => 'ql-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $mk = fn (string $role) => User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true,
        ]);
        $this->admin      = $mk('admin');
        $this->agent      = $mk('agent');
        $this->otherAgent = $mk('agent');

        $this->contact = $this->makeContact($this->agent, ['email' => 'client@example.co.za']);

        $this->assertGreaterThan(0, DB::table('role_permissions')->where('agency_id', $this->agency->id)->count(), 'agency must be provisioned (else allow-all)');
        $this->assertTrue($this->agent->hasPermission('compliance.fica.send'), 'default agent holds compliance.fica.send');
        // The scenario under test is an agent who may SEND a FICA but has no compliance screens. The default agent now
        // carries everything HFC's agent holds (Johan 14 Oct), access_compliance included - this agency trimmed it.
        RolePermission::where('agency_id', $this->agency->id)->where('role', 'agent')->where('permission_key', 'access_compliance')->delete();
        \App\Services\PermissionService::clearCache();
        $this->assertFalse($this->agent->hasPermission('access_compliance'), 'this agent has no access_compliance');

        Mail::fake();
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function makeContact(User $owner, array $extra = []): Contact
    {
        return Contact::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'first_name' => 'Thandeka', 'last_name' => 'Mkhize ' . uniqid(), 'phone' => '082' . random_int(1000000, 9999999),
        ], $extra));
    }

    private function show(User $as, ?Contact $c = null): string
    {
        return $this->actingAs($as)->get(route('corex.contacts.show', $c ?? $this->contact))->assertOk()->getContent();
    }

    private function send(User $as, ?Contact $c = null, array $extra = [])
    {
        return $this->actingAs($as)->post(route('compliance.fica.store'), array_merge([
            'contact_id' => ($c ?? $this->contact)->id, 'return_to' => 'contact',
        ], $extra));
    }

    public function test_a_contact_with_no_fica_shows_a_red_chip_and_a_send_button_to_an_agent(): void
    {
        $html = $this->show($this->agent);

        $this->assertStringContainsString('data-fica-quick-link="none"', $html);
        $this->assertStringContainsString('FICA — not done', $html);
        $this->assertStringContainsString('data-fica-send', $html);
        $this->assertStringContainsString(route('compliance.fica.store'), $html);
    }

    public function test_an_agent_sends_a_fica_and_lands_back_on_the_contact_not_a_403(): void
    {
        $this->send($this->agent)
            ->assertRedirect(route('corex.contacts.show', ['contact' => $this->contact, 'tab' => 'fica']))
            ->assertSessionHas('success');

        $sub = FicaSubmission::where('contact_id', $this->contact->id)->sole();
        $this->assertSame('draft', $sub->status);
        $this->assertSame($this->agent->id, (int) $sub->requested_by);
        Mail::assertSent(FicaRequestMail::class, 1);

        // ...and the page now shows it as waiting on the client, with Resend instead of Send.
        $html = $this->show($this->agent);
        $this->assertStringContainsString('data-fica-quick-link="awaiting"', $html);
        $this->assertStringContainsString('data-fica-resend', $html);
        $this->assertStringNotContainsString('data-fica-send>', $html);
    }

    public function test_a_second_send_for_the_same_contact_is_refused_while_one_is_waiting(): void
    {
        $this->send($this->agent)->assertSessionHas('success');
        $this->send($this->agent)
            ->assertRedirect(route('corex.contacts.show', ['contact' => $this->contact, 'tab' => 'fica']))
            ->assertSessionHas('error');

        $this->assertSame(1, FicaSubmission::where('contact_id', $this->contact->id)->count(), 'no duplicate request');
        Mail::assertSent(FicaRequestMail::class, 1);
    }

    public function test_an_expired_waiting_request_does_not_block_a_fresh_one(): void
    {
        $this->send($this->agent);
        FicaSubmission::where('contact_id', $this->contact->id)->update(['token_expires_at' => now()->subDay()]);

        $this->assertStringContainsString('data-fica-quick-link="awaiting_expired"', $this->show($this->agent));
        $this->send($this->agent)->assertSessionHas('success');
        $this->assertSame(2, FicaSubmission::where('contact_id', $this->contact->id)->count());
    }

    public function test_an_agent_can_resend_and_returns_to_the_contact(): void
    {
        $this->send($this->agent);
        $sub = FicaSubmission::where('contact_id', $this->contact->id)->sole();
        $oldToken = $sub->token;

        $this->actingAs($this->agent)->post(route('compliance.fica.resend', $sub), ['return_to' => 'contact'])
            ->assertRedirect(route('corex.contacts.show', ['contact' => $this->contact, 'tab' => 'fica']))
            ->assertSessionHas('success');

        $this->assertNotSame($oldToken, $sub->fresh()->token, 'resend issues a fresh link');
        $this->assertSame(1, FicaResendLog::where('fica_submission_id', $sub->id)->count());
        Mail::assertSent(FicaRequestMail::class, 2);
    }

    public function test_the_link_life_is_the_agencys_own_setting(): void
    {
        $this->agency->forceFill(['fica_link_expiry_days' => 30])->save();
        $this->send($this->agent);

        $days = now()->diffInHours(FicaSubmission::where('contact_id', $this->contact->id)->sole()->token_expires_at) / 24;
        $this->assertEqualsWithDelta(30, $days, 0.1, 'link valid 30 days, not the old fixed 14');
    }

    public function test_a_contact_with_no_email_is_given_one_inline_and_without_one_nothing_is_sent(): void
    {
        $bare = $this->makeContact($this->agent, ['email' => null]);

        $this->send($this->agent, $bare)->assertSessionHas('error');
        $this->assertSame(0, FicaSubmission::where('contact_id', $bare->id)->count());
        Mail::assertNothingSent();

        $this->send($this->agent, $bare, ['email' => 'new@example.co.za'])->assertSessionHas('success');
        $this->assertSame('new@example.co.za', $bare->fresh()->email, 'the email is saved on the contact');
        Mail::assertSent(FicaRequestMail::class, 1);

        // the pop-up asks for the email only when the contact has none
        $this->assertStringContainsString('name="email"', $this->show($this->agent, $this->makeContact($this->agent, ['email' => null])));
        $this->assertStringNotContainsString('name="email" required', $this->show($this->agent));
    }

    public function test_an_agent_cannot_send_for_a_contact_outside_their_scope(): void
    {
        // This agency's agents see only their OWN contacts (Role Manager → Contacts → scope = own).
        DB::table('role_permissions')->where('agency_id', $this->agency->id)->where('role', 'agent')
            ->where('permission_key', 'contacts.view')->update(['scope' => 'own']);
        PermissionService::clearCache();

        $theirs = $this->makeContact($this->otherAgent, ['email' => 'x@example.co.za']);
        $this->send($this->agent, $theirs)->assertNotFound();
        $this->assertSame(0, FicaSubmission::where('contact_id', $theirs->id)->count());
        $this->send($this->agent)->assertSessionHas('success');   // ...but their own contact still works

        // another agency entirely
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        // Raw insert: Contact::create() while a user is logged in force-stamps THEIR agency (BelongsToAgency), which would put
        // this "foreign" contact in the sender's own agency and test nothing.
        $otherBranch = (int) DB::table('branches')->insertGetId(['agency_id' => $other->id, 'name' => 'Far', 'created_at' => now(), 'updated_at' => now()]);
        $foreignId = (int) DB::table('contacts')->insertGetId([
            'agency_id' => $other->id, 'branch_id' => $otherBranch, 'first_name' => 'Far', 'last_name' => 'Away', 'email' => 'far@example.co.za',
            'phone' => '0821234567', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin)->post(route('compliance.fica.store'), ['contact_id' => $foreignId, 'return_to' => 'contact'])->assertNotFound();
        $this->assertSame(0, FicaSubmission::where('contact_id', $foreignId)->count());
        Mail::assertSent(FicaRequestMail::class, 1);   // only the agent's own contact was ever mailed
    }

    public function test_a_resend_outside_the_senders_scope_is_refused(): void
    {
        DB::table('role_permissions')->where('agency_id', $this->agency->id)->where('role', 'agent')
            ->where('permission_key', 'fica.view')->update(['scope' => 'own']);
        PermissionService::clearCache();

        $theirs = $this->makeContact($this->otherAgent, ['email' => 'x@example.co.za']);
        $this->send($this->otherAgent, $theirs);
        $sub = FicaSubmission::where('contact_id', $theirs->id)->sole();

        $this->actingAs($this->agent)->post(route('compliance.fica.resend', $sub), ['return_to' => 'contact'])->assertForbidden();
    }

    public function test_an_approved_fica_shows_green_and_the_agency_threshold_turns_it_to_expiring(): void
    {
        FicaSubmission::unguarded(fn () => FicaSubmission::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id, 'requested_by' => $this->agent->id,
            'status' => 'approved', 'verified_at' => now()->subMonths(5), 'token' => 'x', 'token_expires_at' => now()->addDay(),
        ]));

        $this->assertStringContainsString('data-fica-quick-link="approved"', $this->show($this->agent));

        $this->agency->forceFill(['fica_current_months' => 4])->save();
        $html = $this->show($this->agent);
        $this->assertStringContainsString('data-fica-quick-link="expiring"', $html);
        $this->assertStringContainsString('Send new FICA form', $html);
    }

    public function test_compliance_switched_off_shows_no_chip_and_closes_the_send_route(): void
    {
        AgencyFeature::withTrashed()->updateOrCreate(
            ['agency_id' => $this->agency->id, 'feature_key' => 'compliance'],
            ['enabled' => false, 'deleted_at' => null]
        );
        app(AgencyFeatureService::class)->forget($this->agency->id);
        PermissionService::clearCache();

        $this->assertStringNotContainsString('data-fica-quick-link', $this->show($this->agent));
        $this->send($this->agent)->assertNotFound();
    }

    public function test_the_contact_list_shows_a_chip_per_row_in_a_bounded_number_of_queries(): void
    {
        foreach (range(1, 4) as $i) {
            $this->makeContact($this->agent);
        }
        $this->send($this->agent);   // one of them awaiting

        DB::enableQueryLog();
        $html = $this->actingAs($this->agent)->get(route('corex.contacts.index'))->assertOk()->getContent();
        $ficaQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'fica_submissions'))->count();
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(5, substr_count($html, 'data-fica-quick-link='), 'a chip on every row');
        $this->assertStringContainsString('data-fica-quick-link="awaiting"', $html);
        $this->assertLessThanOrEqual(2, $ficaQueries, 'FICA state for the whole page is batch-loaded, not one query per row');
    }

    private function hub(Contact $buyer): string
    {
        return $this->actingAs($this->agent)->get(route('command-center.buyers.show', $buyer))->assertOk()->getContent();
    }

    public function test_buyer_hub_has_the_send_fica_button_between_schedule_viewing_and_build_viewing_pack(): void
    {
        $buyer = $this->makeContact($this->agent, ['is_buyer' => true, 'email' => 'buyer@example.co.za']);
        $html = $this->hub($buyer);

        $schedule = strpos($html, 'Schedule Viewing</button>');
        // the attribute is followed by whitespace, not '>' — find the BUTTON (first bare data-fica-send), not data-fica-send-confirm
        $send     = preg_match('/data-fica-send(?![-\w])/', $html, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : false;
        $pack     = strpos($html, 'Build Viewing Pack</button>');
        $this->assertNotFalse($schedule);
        $this->assertNotFalse($send, 'the Send FICA form button is on the Buyer Hub');
        $this->assertNotFalse($pack);
        $this->assertTrue($schedule < $send && $send < $pack, 'Send FICA form sits between Schedule Viewing and Build Viewing Pack');
        $this->assertStringContainsString('Send FICA form', $html);

        // and it works: opens the pop-up that posts the existing store route back to the contact
        $this->assertStringContainsString('action="' . route('compliance.fica.store') . '"', $html);
    }

    public function test_buyer_hub_hides_the_fica_button_once_the_fica_is_done_or_under_review(): void
    {
        $buyer = $this->makeContact($this->agent, ['is_buyer' => true, 'email' => 'buyer@example.co.za']);
        $mk = fn (string $status, array $extra = []) => FicaSubmission::unguarded(fn () => FicaSubmission::create(array_merge([
            'contact_id' => $buyer->id, 'agency_id' => $this->agency->id, 'requested_by' => $this->agent->id,
            'status' => $status, 'token' => bin2hex(random_bytes(8)), 'token_expires_at' => now()->addDay(),
        ], $extra)));

        $done = $mk('approved', ['verified_at' => now()->subMonths(2)]);
        $html = $this->hub($buyer);
        $this->assertStringContainsString('data-fica-quick-link="approved"', $html, 'the status pill still shows');
        $this->assertStringNotContainsString('data-fica-send', $html, 'FICA is done: no Send button');
        $this->assertStringNotContainsString('data-fica-resend', $html, 'FICA is done: no Resend button');

        $done->delete();
        $mk('submitted');
        $html = $this->hub($buyer);
        $this->assertStringContainsString('data-fica-quick-link="in_review"', $html);
        $this->assertStringNotContainsString('data-fica-send', $html);
        $this->assertStringNotContainsString('data-fica-resend', $html);
    }

    public function test_buyer_hub_offers_resend_while_waiting_and_a_new_form_when_expiring_and_nothing_to_someone_who_cannot_send(): void
    {
        $buyer = $this->makeContact($this->agent, ['is_buyer' => true, 'email' => 'buyer@example.co.za']);
        $this->send($this->agent, $buyer);
        $html = $this->hub($buyer);
        $this->assertStringContainsString('Resend FICA form', $html);
        $this->assertStringContainsString('data-fica-resend', $html);

        // a user who may not send FICA forms sees the pill but no button
        DB::table('role_permissions')->where('agency_id', $this->agency->id)->where('role', 'agent')
            ->where('permission_key', 'compliance.fica.send')->delete();
        PermissionService::clearCache();
        $html = $this->hub($buyer);
        $this->assertStringContainsString('data-fica-quick-link', $html);
        $this->assertStringNotContainsString('data-fica-resend', $html);
        $this->assertStringNotContainsString('data-fica-send', $html);
    }

    public function test_the_fica_tab_never_shows_view_or_pdf_to_someone_who_cannot_open_them(): void
    {
        $sub = FicaSubmission::unguarded(fn () => FicaSubmission::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id, 'requested_by' => $this->agent->id,
            'status' => 'approved', 'verified_at' => now()->subMonth(), 'token' => 'x', 'token_expires_at' => now()->addDay(),
        ]));

        // a default agent can send FICA forms but holds no access_compliance => the buttons would 403, so they are not shown
        $this->assertFalse($this->agent->hasPermission('access_compliance'));
        $html = $this->show($this->agent);
        $this->assertStringContainsString('data-fica-quick-link="approved"', $html, 'the status itself is still shown');
        $this->assertStringNotContainsString('data-fica-view', $html);
        $this->assertStringNotContainsString(route('compliance.fica.pdf', $sub), $html);
        $this->assertStringNotContainsString(route('compliance.fica.show', $sub), $html);

        // compliance staff can open them, so they do see them
        $this->assertTrue($this->admin->hasPermission('access_compliance'));
        $html = $this->show($this->admin);
        $this->assertStringContainsString('data-fica-view', $html);
        $this->assertStringContainsString(route('compliance.fica.pdf', $sub), $html);
        $this->assertStringContainsString(route('compliance.fica.show', $sub), $html);
    }
}
