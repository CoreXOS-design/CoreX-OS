<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Models\Campaigns\Campaign;
use App\Models\Campaigns\CampaignLead;
use App\Models\Campaigns\CampaignList;
use App\Models\Campaigns\CampaignSend;
use App\Models\Campaigns\CampaignSender;
use App\Models\Campaigns\CampaignStep;
use App\Models\Campaigns\CampaignSuppression;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Role;
use App\Models\User;
use App\Services\Campaigns\CampaignImportException;
use App\Services\Campaigns\CampaignImportService;
use App\Services\Campaigns\CampaignInboxChecker;
use App\Services\Campaigns\CampaignSendService;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\TestCase;

/**
 * AT-534 — Campaigns (.ai/specs/campaigns.md §10.2). The per-mailbox transport is replaced by a recorder, so no test opens a socket.
 */
final class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to:string, from:string, subject:string, body:string}> */
    private array $sent = [];

    /** @var list<string> sanitised reasons to throw, consumed one per send */
    private array $failNext = [];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $this->app->instance(PerMailboxMailTransportBuilder::class, new class($test) extends PerMailboxMailTransportBuilder {
            public function __construct(private $t)
            {
            }

            public function send(CommunicationMailbox $mailbox, Mailable $mailable): string
            {
                return $this->t->recordSend($mailbox, $mailable);
            }
        });

        $role = Role::create(['name' => 'super_admin', 'label' => 'System Owner']);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();
        $this->owner = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);
    }

    public function recordSend(CommunicationMailbox $mailbox, Mailable $mailable): string
    {
        if ($this->failNext !== []) {
            throw new OutgoingMailboxSendFailedException(array_shift($this->failNext), 'failed');
        }
        $mailable->render(); // proves the plain-text view renders
        $this->sent[] = [
            'to' => $mailable->to[0]['address'] ?? '',
            'from' => $mailbox->email_address,
            'subject' => $mailable->subjectLine ?? '',
            'body' => $mailable->bodyText ?? '',
        ];

        return 'raw';
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────

    private function sender(array $attrs = []): CampaignSender
    {
        return CampaignSender::create($attrs + [
            'email' => 'andre@corexos.co.za', 'from_name' => 'Andre — CoreX OS', 'signature' => "Andre Roets\nCoreX OS",
            'smtp_host' => 'mail.corexos.co.za', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'username' => 'andre@corexos.co.za',
            'encrypted_password' => 'Pw-Zq81xKv', 'is_default' => true,
        ]);
    }

    /** A running campaign: $leads leads (plus-addressed test inbox), $steps steps with a 2-day gap. */
    private function campaign(int $leads = 3, int $steps = 2, int $limit = 60): Campaign
    {
        $list = CampaignList::create(['name' => 'Agencies ' . uniqid()]);
        for ($i = 1; $i <= $leads; $i++) {
            CampaignLead::create(['campaign_list_id' => $list->id, 'first_name' => "Lead{$i}", 'company' => "Co{$i}", 'email' => "can.assurance+{$i}@gmail.com"]);
        }
        $campaign = Campaign::create([
            'name' => 'Test', 'campaign_list_id' => $list->id, 'campaign_sender_id' => ($this->senderModel ??= $this->sender())->id,
            'status' => 'draft', 'send_days' => [1, 2, 3, 4, 5], 'send_from' => '08:00', 'send_to' => '16:00', 'daily_limit' => $limit,
        ]);
        for ($s = 1; $s <= $steps; $s++) {
            CampaignStep::create(['campaign_id' => $campaign->id, 'step_number' => $s, 'subject' => "Step {$s} for {company}", 'body' => "Hi {first_name},\nStep {$s}.", 'day_gap' => $s === 1 ? 0 : 2]);
        }
        app(CampaignSendService::class)->start($campaign, $this->owner->id);

        return $campaign->fresh();
    }

    private ?CampaignSender $senderModel = null;

    private function service(): CampaignSendService
    {
        return app(CampaignSendService::class);
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cmp') . '.csv';
        file_put_contents($path, $content);

        return $path;
    }

    // ── access ──────────────────────────────────────────────────────────────────────────────

    public function test_non_owner_is_refused_every_screen_including_by_id(): void
    {
        $campaign = $this->campaign(1, 1);
        $agent = User::factory()->create();
        foreach ([
            route('admin.campaigns.index'), route('admin.campaigns.show', $campaign->id), route('admin.campaigns.lists.index'),
            route('admin.campaigns.lists.show', $campaign->campaign_list_id), route('admin.campaigns.suppressions.index'), route('admin.campaigns.settings'),
        ] as $url) {
            $this->assertContains($this->actingAs($agent)->get($url)->status(), [403, 404], $url);
        }
        $this->actingAs($agent)->post(route('admin.campaigns.pause', $campaign->id))->assertForbidden();
        $this->assertSame('running', $campaign->fresh()->status);
    }

    public function test_owner_screens_render(): void
    {
        $campaign = $this->campaign(2, 2);
        $this->service()->runBatch($campaign, Carbon::parse('2027-01-04 09:00'));
        $this->actingAs($this->owner);
        $this->get(route('admin.campaigns.index'))->assertOk()->assertSee('data-campaign-row="' . $campaign->id . '"', false);
        foreach (['list', 'steps', 'activity'] as $tab) {
            $this->get(route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => $tab]))->assertOk();
        }
        $this->get(route('admin.campaigns.create'))->assertOk();
        $this->get(route('admin.campaigns.lists.index'))->assertOk();
        $this->get(route('admin.campaigns.lists.show', $campaign->campaign_list_id))->assertOk()->assertSee('can.assurance+1@gmail.com');
        $this->get(route('admin.campaigns.suppressions.index'))->assertOk();
        $this->get(route('admin.campaigns.settings'))->assertOk()->assertSee('andre@corexos.co.za')->assertDontSee('Pw-Zq81xKv');
    }

    // ── import (§5.2) ───────────────────────────────────────────────────────────────────────

    public function test_import_handles_the_input_space(): void
    {
        CampaignSuppression::suppress('blocked@example.com', 'manual');
        $other = CampaignList::create(['name' => 'Other']);
        CampaignLead::create(['campaign_list_id' => $other->id, 'email' => 'existing@example.com']);
        $list = CampaignList::create(['name' => 'Import']);

        $path = $this->csv("\u{FEFF}First Name;Surname;Company;Town;E-mail;Cell;Notes\n"
            . "Ann;Smith;Acme;Margate;ann@example.com;'+27821234567;hello\n"
            . "Bob;;;;  BOB@Example.COM  ;;\n"
            . "Cat;;;;not-an-email;;\n"
            . "Dan;;;;;;\n"
            . "Ann2;;;;ann@example.com;;\n"
            . "Eve;;;;existing@example.com;;\n"
            . "Fay;;;;blocked@example.com;;\n"
            . ";;;;;;\n");
        $svc = app(CampaignImportService::class);
        $headers = $svc->readHeaders($path, 'csv');
        $this->assertSame('First Name', $headers[0], 'BOM stripped, semicolon detected');
        $mapping = $svc->suggestMapping($headers);
        $this->assertSame(['first_name' => 0, 'last_name' => 1, 'company' => 2, 'town' => 3, 'email' => 4, 'phone' => 5, 'notes' => 6], $mapping);

        $r = $svc->import($list, $path, 'csv', $mapping, $this->owner->id);

        $this->assertSame(2, $r['imported']);
        $this->assertSame(1, $r['suppressed']);
        $this->assertSame(2, $r['skipped']);
        $this->assertSame(2, $r['rejected']);
        $this->assertSame('+27821234567', CampaignLead::where('email', 'ann@example.com')->value('phone'));
        $this->assertTrue(CampaignLead::where('email', 'bob@example.com')->exists(), 'trimmed + lower-cased');
        $this->assertSame('suppressed', CampaignLead::where('email', 'blocked@example.com')->value('status'));
        $this->assertSame('', CampaignLead::where('email', 'bob@example.com')->value('company'));
        $reasons = array_column($r['rows'], 'reason', 'row');
        $this->assertSame('Not a valid email address', $reasons[4]);
        $this->assertSame('Missing email', $reasons[5]);
        $this->assertSame('Duplicate of row 2', $reasons[6]);
        $this->assertSame('Already on list Other', $reasons[7]);
    }

    public function test_import_reads_xlsx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cmp') . '.xlsx';
        $w = new XlsxWriter();
        $w->openToFile($path);
        $w->addRow(Row::fromValues(['Email', 'First name', 'Phone']));
        $w->addRow(Row::fromValues(['x@example.com', 'Xan', 27821234567]));
        $w->close();
        $list = CampaignList::create(['name' => 'X']);
        $svc = app(CampaignImportService::class);

        $r = $svc->import($list, $path, 'xlsx', $svc->suggestMapping($svc->readHeaders($path, 'xlsx')), $this->owner->id);

        $this->assertSame(1, $r['imported']);
        $this->assertSame('27821234567', CampaignLead::where('email', 'x@example.com')->value('phone'), 'a numeric phone is not "…0" or exponent');
    }

    public function test_import_rejects_empty_and_oversized_files_without_writing(): void
    {
        $list = CampaignList::create(['name' => 'E']);
        $svc = app(CampaignImportService::class);
        try {
            $svc->import($list, $this->csv("Email\n"), 'csv', ['email' => 0], $this->owner->id);
            $this->fail('header-only file accepted');
        } catch (CampaignImportException $e) {
            $this->assertStringContainsString('no rows', $e->getMessage());
        }
        config(['campaigns.max_import_rows' => 2]);
        try {
            $svc->import($list, $this->csv("Email\na@x.com\nb@x.com\nc@x.com\n"), 'csv', ['email' => 0], $this->owner->id);
            $this->fail('oversized file accepted');
        } catch (CampaignImportException $e) {
            $this->assertStringContainsString('Split it', $e->getMessage());
        }
        $this->assertSame(0, CampaignLead::count());
    }

    public function test_import_rolls_back_every_row_when_saving_fails_midway(): void
    {
        $list = CampaignList::create(['name' => 'R']);
        $n = 0;
        CampaignLead::creating(function () use (&$n) {
            if (++$n === 2) {
                throw new \RuntimeException('db went away');
            }
        });
        try {
            app(CampaignImportService::class)->import($list, $this->csv("Email\na@x.com\nb@x.com\nc@x.com\n"), 'csv', ['email' => 0], $this->owner->id);
            $this->fail('expected the failure to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('db went away', $e->getMessage());
        }
        $this->assertSame(0, CampaignLead::count());
    }

    public function test_import_screens_upload_map_and_run(): void
    {
        $list = CampaignList::create(['name' => 'Screens']);
        $file = new \Illuminate\Http\UploadedFile($this->csv("Name,Email\nZed,zed@example.com\n"), 'leads.csv', 'text/csv', null, true);
        $this->actingAs($this->owner)
            ->post(route('admin.campaigns.lists.import.upload', $list->id), ['file' => $file])
            ->assertRedirect(route('admin.campaigns.lists.import.map', $list->id));
        $this->get(route('admin.campaigns.lists.import.map', $list->id))->assertOk()->assertSee('data-map-columns', false);
        $token = session('campaign_import.' . $list->id)['token'];
        $this->post(route('admin.campaigns.lists.import.run', $list->id), ['token' => $token, 'column' => ['first_name' => 0, 'email' => 1]])
            ->assertRedirect(route('admin.campaigns.lists.show', $list->id));
        $this->assertSame('Zed', CampaignLead::where('email', 'zed@example.com')->value('first_name'));

        $xls = new \Illuminate\Http\UploadedFile($this->csv('x'), 'old.xls', 'application/vnd.ms-excel', null, true);
        $this->post(route('admin.campaigns.lists.import.upload', $list->id), ['file' => $xls])->assertSessionHasErrors('file');
    }

    // ── sending (§6.2) ──────────────────────────────────────────────────────────────────────

    public function test_daily_limit_window_and_day_gap_are_respected_and_the_sequence_completes(): void
    {
        $campaign = $this->campaign(3, 2, 2);
        $svc = $this->service();
        $mon = Carbon::parse('2027-01-04 09:00'); // a Monday

        $this->assertSame(0, $svc->runBatch($campaign, Carbon::parse('2027-01-04 07:59'))['sent'], 'before the window opens');
        $this->assertSame(0, $svc->runBatch($campaign, Carbon::parse('2027-01-09 10:00'))['sent'], 'Saturday is not a send day');

        $this->travelTo($mon);
        $this->assertSame(2, $svc->runBatch($campaign, $mon)['sent'], 'daily limit 2');
        $r = $svc->runBatch($campaign, $mon->copy()->addMinutes(5));
        $this->assertSame(0, $r['sent']);
        $this->assertSame('daily_limit', $r['stopped_reason']);

        $tue = Carbon::parse('2027-01-05 09:00');
        $this->travelTo($tue);
        $this->assertSame(1, $svc->runBatch($campaign, $tue)['sent'], 'third lead, step 1; step 2 not due before the 2-day gap');

        $wed = Carbon::parse('2027-01-06 09:30');
        $this->travelTo($wed);
        $this->assertSame(2, $svc->runBatch($campaign, $wed)['sent'], 'step 2 for the two Monday leads');
        $thu = Carbon::parse('2027-01-07 09:30');
        $this->travelTo($thu);
        $this->assertSame(1, $svc->runBatch($campaign, $thu)['sent'], 'step 2 for the Tuesday lead');

        $this->assertSame('done', $campaign->fresh()->status);
        $this->assertCount(6, $this->sent);
        $this->assertSame(6, CampaignSend::where('status', 'sent')->count());
        $first = $this->sent[0];
        $this->assertSame('andre@corexos.co.za', $first['from']);
        $this->assertSame('Step 1 for Co1', $first['subject']);
        $this->assertStringContainsString("Hi Lead1,\nStep 1.\n\nAndre Roets\nCoreX OS\n\n", $first['body']);
        $this->assertStringContainsString('/campaign-unsubscribe/', $first['body']);
        foreach ($this->sent as $s) {
            $this->assertMatchesRegularExpression('/^can\.assurance\+\d@gmail\.com$/', $s['to']);
        }
    }

    public function test_a_lead_step_pair_is_never_sent_twice(): void
    {
        $campaign = $this->campaign(1, 1);
        $svc = $this->service();
        $step = $campaign->steps()->first();
        $lead = CampaignLead::first();

        $this->assertSame('sent', $svc->sendPair($campaign, $step, $lead));
        $this->assertSame('skipped', $svc->sendPair($campaign, $step, $lead));
        $this->assertCount(1, $this->sent);
        $this->assertSame(1, CampaignSend::count());
    }

    public function test_a_send_stuck_mid_flight_becomes_unknown_and_is_not_resent(): void
    {
        $campaign = $this->campaign(1, 1);
        $send = CampaignSend::create([
            'campaign_id' => $campaign->id, 'campaign_step_id' => $campaign->steps()->first()->id, 'campaign_lead_id' => CampaignLead::first()->id,
            'status' => 'sending', 'attempts' => 1, 'to_email' => 'can.assurance+1@gmail.com', 'from_email' => 'andre@corexos.co.za', 'subject' => 's', 'body' => 'b',
        ]);
        CampaignSend::whereKey($send->id)->update(['updated_at' => now()->subHour()]);

        $this->service()->runBatch($campaign, Carbon::parse('2027-01-04 09:00'));

        $this->assertSame('unknown', $send->fresh()->status);
        $this->assertCount(0, $this->sent);
    }

    public function test_mailbox_login_failure_pauses_the_campaign_and_failed_sends_are_retried(): void
    {
        $campaign = $this->campaign(3, 1);
        $this->failNext = ['auth_failed', 'auth_failed'];
        $this->travelTo(Carbon::parse('2027-01-04 09:00'));

        $r = $this->service()->runBatch($campaign, now());

        $this->assertSame('paused', $r['stopped_reason']);
        $campaign->refresh();
        $this->assertSame('paused', $campaign->status);
        $this->assertStringContainsString('login failed', $campaign->paused_reason);
        $this->assertSame(2, CampaignSend::where('status', 'failed')->count());
        $this->assertSame('auth_failed', CampaignSender::first()->last_send_error);

        $this->service()->resume($campaign, $this->owner->id);
        $this->service()->runBatch($campaign->fresh(), now()->addMinutes(5));
        $this->assertSame(3, CampaignSend::where('status', 'sent')->count(), 'both failures retried + the third lead');
        $this->assertSame(3, CampaignSend::count());
    }

    // ── unsubscribe (§6.4) ──────────────────────────────────────────────────────────────────

    public function test_unsubscribe_get_never_writes_post_stops_and_suppresses(): void
    {
        $campaign = $this->campaign(1, 2);
        $this->travelTo(Carbon::parse('2027-01-04 09:00'));
        $this->service()->runBatch($campaign, now());
        $send = CampaignSend::firstOrFail();
        $url = URL::signedRoute('campaigns.public.unsubscribe', ['send' => $send->id]);

        $this->get($url)->assertOk()->assertSee('data-unsubscribe-state="confirm"', false);
        $this->assertNull($send->fresh()->unsubscribed_at);
        $this->get(route('campaigns.public.unsubscribe', ['send' => $send->id]))->assertForbidden();

        $this->post($url)->assertOk()->assertSee('data-unsubscribe-state="unsubscribed"', false);
        $this->post($url)->assertOk()->assertSee('data-unsubscribe-state="done"', false);

        $this->assertNotNull($send->fresh()->unsubscribed_at);
        $this->assertSame('stopped', CampaignLead::first()->status);
        $this->assertTrue(CampaignSuppression::isSuppressed('can.assurance+1@gmail.com'));

        $this->travelTo(Carbon::parse('2027-01-08 09:00'));
        $this->service()->runBatch($campaign->fresh(), now());
        $this->assertCount(1, $this->sent, 'step 2 never goes to an unsubscribed lead');
    }

    // ── replies / bounces (§6.6) ────────────────────────────────────────────────────────────

    public function test_replies_stop_replies_and_bounces_are_recognised(): void
    {
        $campaign = $this->campaign(5, 2);
        $this->travelTo(Carbon::parse('2027-01-04 09:00'));
        $this->service()->runBatch($campaign, now());
        $sends = CampaignSend::orderBy('id')->get();
        $checker = app(CampaignInboxChecker::class);
        $sender = CampaignSender::first();
        $h = fn (string $from, string $extra) => "From: {$from}\r\nSubject: Re: hi\r\n{$extra}";

        // 1 — plain reply threaded by In-Reply-To; the quoted original's own "Reply STOP" footer must not count.
        $this->assertSame('reply', $checker->process($sender, $h('can.assurance+1@gmail.com', 'In-Reply-To: <' . $sends[0]->message_id . '>'), '',
            "Thanks, call me Tuesday.\n\nOn Mon, 4 Jan 2027 at 09:00, Andre wrote:\n> Reply STOP or use the link below"));
        // 2 — STOP reply, matched by sender address only.
        $this->assertSame('stop', $checker->process($sender, $h('Lead2 <can.assurance+2@gmail.com>', ''), '', "stop\n"));
        // 3 — hard bounce DSN naming our Message-ID.
        $dsn = "From: Mail Delivery System <MAILER-DAEMON@mx.example.com>\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=x\r\n";
        $this->assertSame('bounce', $checker->process($sender, $dsn, $dsn . "\r\n\r\nStatus: 5.1.1\r\nMessage-ID: <" . $sends[2]->message_id . '>', ''));
        // 4 — soft bounce: ignored.
        $this->assertSame('soft_bounce', $checker->process($sender, $dsn, $dsn . "\r\n\r\nStatus: 4.2.2\r\nMessage-ID: <" . $sends[3]->message_id . '>', ''));
        // 5 — out-of-office: not a reply.
        $this->assertSame('auto_reply', $checker->process($sender, $h('can.assurance+5@gmail.com', "Auto-Submitted: auto-replied\r\nIn-Reply-To: <" . $sends[4]->message_id . '>'), '', 'I am away'));
        // forged id with the right number but wrong random part: not matched.
        $forged = preg_replace('/-[a-z0-9]+@/', '-zzzzzzzzzzzz@', $sends[4]->message_id);
        $this->assertSame('ignored', $checker->process($sender, $h('someone@else.com', 'In-Reply-To: <' . $forged . '>'), '', 'stop'));

        $status = CampaignLead::orderBy('id')->pluck('status')->all();
        $this->assertSame(['replied', 'stopped', 'bounced', 'sent', 'sent'], $status);
        $this->assertFalse(CampaignSuppression::isSuppressed('can.assurance+1@gmail.com'), 'a reply does not suppress');
        $this->assertTrue(CampaignSuppression::isSuppressed('can.assurance+2@gmail.com'));
        $this->assertTrue(CampaignSuppression::isSuppressed('can.assurance+3@gmail.com'));

        $this->travelTo(Carbon::parse('2027-01-07 09:00'));
        $this->service()->runBatch($campaign->fresh(), now());
        $this->assertSame(2, CampaignSend::whereHas('step', fn ($q) => $q->where('step_number', 2))->count(), 'step 2 only to leads 4 and 5');
    }

    public function test_inbox_is_never_opened_from_a_non_sending_environment(): void
    {
        // §42 hard rule — the testing environment is not on the sending allowlist, so no IMAP login may be attempted at all.
        $this->assertFalse(\App\Support\OutboundMailGuard::isSendingConfirmed());
        $sender = $this->sender(['smtp_host' => '192.0.2.1', 'imap_host' => '192.0.2.1']); // TEST-NET: would hang if dialled
        $checker = app(CampaignInboxChecker::class);

        $started = microtime(true);
        $this->assertSame(CampaignInboxChecker::OFF_HERE, $checker->testInbox($sender));
        $this->assertSame(['checked' => 0, 'error' => CampaignInboxChecker::OFF_HERE], $checker->checkSender($sender));
        $this->assertLessThan(2, microtime(true) - $started, 'no connection attempted');
        $this->assertSame(CampaignInboxChecker::OFF_HERE, $sender->fresh()->last_inbox_error);
    }

    // ── steps ───────────────────────────────────────────────────────────────────────────────

    public function test_unknown_merge_field_and_short_day_gap_are_rejected(): void
    {
        $list = CampaignList::create(['name' => 'S']);
        $campaign = Campaign::create([
            'name' => 'S', 'campaign_list_id' => $list->id, 'campaign_sender_id' => $this->sender()->id, 'status' => 'draft',
            'send_days' => [1], 'send_from' => '08:00', 'send_to' => '16:00', 'daily_limit' => 10,
        ]);
        $this->actingAs($this->owner);
        $this->post(route('admin.campaigns.steps.store', $campaign->id), ['subject' => 'Hi {fname}', 'body' => 'x'])->assertSessionHasErrors('subject');
        $this->post(route('admin.campaigns.steps.store', $campaign->id), ['subject' => 'Hi {first_name}', 'body' => 'x'])->assertSessionHasNoErrors();
        $this->post(route('admin.campaigns.steps.store', $campaign->id), ['subject' => 'Again', 'body' => 'y', 'day_gap' => 1])->assertSessionHasErrors('day_gap');
        $this->assertSame(1, $campaign->steps()->count());

        $this->post(route('admin.campaigns.start', $campaign->id))->assertSessionHas('error');
        $this->assertSame('draft', $campaign->fresh()->status, 'no leads → cannot start');
    }
}
