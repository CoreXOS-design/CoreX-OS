<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Agency;
use App\Models\Communications\CommunicationHostCircuitBreaker;
use App\Models\Communications\CommunicationMailbox;
use App\Notifications\Communications\HostCircuitBreakerOpenNotification;
use App\Services\Communications\HostCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-08/09 (Johan, circuit breaker) — "this is why today ran six hours
 * instead of ten minutes." Twenty mailboxes each independently backing off
 * (a SEPARATE fix — see at33-poll-backoff-2026-09-08) still adds up to twenty
 * real connection attempts to a blocked host every cycle. These tests prove
 * the standard closed -> open -> single-probe -> closed pattern actually
 * stops that: a concentrated failure rate across a host's mailboxes opens
 * the breaker, everything except one paced probe is held back while open,
 * and a successful probe closes it again.
 */
final class HostCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mailbox(array $overrides = []): CommunicationMailbox
    {
        return CommunicationMailbox::create(array_merge([
            'agency_id' => $this->agencyId, 'email_address' => 'office-' . Str::random(6) . '@shared-host.test',
            'imap_host' => 'shared-host.test', 'imap_port' => 993, 'username' => 'office@shared-host.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => false,
            'poll_interval_minutes' => 15, 'active' => true,
        ], $overrides));
    }

    private function failingMailbox(): CommunicationMailbox
    {
        return $this->mailbox(['last_error' => 'connect_failed', 'last_error_at' => now()]);
    }

    public function test_a_fresh_host_starts_closed(): void
    {
        $this->assertFalse(app(HostCircuitBreaker::class)->isOpen('shared-host.test'));
    }

    public function test_does_not_open_below_the_minimum_mailbox_count(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 3, 'communications.circuit_breaker_failure_threshold_percent' => 50]);
        $mailboxes = collect([$this->failingMailbox(), $this->failingMailbox()]); // 2, both failing -- 100% but below min 3

        app(HostCircuitBreaker::class)->evaluate('shared-host.test', $mailboxes);

        $this->assertFalse(app(HostCircuitBreaker::class)->isOpen('shared-host.test'), 'a lone mailbox (or two) having a bad day must never trip the breaker');
    }

    public function test_does_not_open_below_the_failure_threshold_percent(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 3, 'communications.circuit_breaker_failure_threshold_percent' => 80]);
        // 4 mailboxes, only 1 failing = 25%, well below 80%.
        $mailboxes = collect([$this->failingMailbox(), $this->mailbox(), $this->mailbox(), $this->mailbox()]);

        app(HostCircuitBreaker::class)->evaluate('shared-host.test', $mailboxes);

        $this->assertFalse(app(HostCircuitBreaker::class)->isOpen('shared-host.test'));
    }

    public function test_opens_when_the_concentrated_failure_rate_is_met(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 3, 'communications.circuit_breaker_failure_threshold_percent' => 80]);
        // This morning's exact shape: most/all of a host's mailboxes failing at once.
        $mailboxes = collect([$this->failingMailbox(), $this->failingMailbox(), $this->failingMailbox(), $this->mailbox()]); // 3/4 = 75%... bump to 4/4

        app(HostCircuitBreaker::class)->evaluate('shared-host.test', $mailboxes);
        $this->assertFalse(app(HostCircuitBreaker::class)->isOpen('shared-host.test'), '75% is below the 80% threshold -- sanity check on the boundary');

        $mailboxes = collect([$this->failingMailbox(), $this->failingMailbox(), $this->failingMailbox(), $this->failingMailbox()]); // 4/4 = 100%
        app(HostCircuitBreaker::class)->evaluate('shared-host.test', $mailboxes);

        $this->assertTrue(app(HostCircuitBreaker::class)->isOpen('shared-host.test'));
    }

    public function test_a_stale_failure_outside_the_lookback_window_does_not_count(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 2, 'communications.circuit_breaker_failure_threshold_percent' => 80, 'communications.circuit_breaker_lookback_minutes' => 15]);
        $mailboxes = collect([
            $this->mailbox(['last_error' => 'connect_failed', 'last_error_at' => now()->subHours(3)]), // stale -- recovered since, this is just an old scar
            $this->mailbox(['last_error' => 'connect_failed', 'last_error_at' => now()->subHours(3)]),
        ]);

        app(HostCircuitBreaker::class)->evaluate('shared-host.test', $mailboxes);

        $this->assertFalse(app(HostCircuitBreaker::class)->isOpen('shared-host.test'), 'an hours-old failure must not still count against the host');
    }

    public function test_evaluate_is_a_noop_once_already_open_and_does_not_reset_the_episode(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 2, 'communications.circuit_breaker_failure_threshold_percent' => 80]);
        $mailboxes = collect([$this->failingMailbox(), $this->failingMailbox()]);
        $breaker = app(HostCircuitBreaker::class);

        $breaker->evaluate('shared-host.test', $mailboxes);
        $openedAt = CommunicationHostCircuitBreaker::where('host', 'shared-host.test')->value('opened_at');
        $this->assertNotNull($openedAt);

        $this->travel(5)->minutes();
        $breaker->evaluate('shared-host.test', $mailboxes);
        $stillOpenedAt = CommunicationHostCircuitBreaker::where('host', 'shared-host.test')->value('opened_at');

        $this->assertSame((string) $openedAt, (string) $stillOpenedAt, 'must not re-open (and re-stamp opened_at) every cycle while already open -- that would also re-alert every time');
    }

    public function test_isAllowedProbe_is_false_while_closed(): void
    {
        $mailbox = $this->mailbox();
        $this->assertFalse(app(HostCircuitBreaker::class)->isAllowedProbe($mailbox));
    }

    public function test_isAllowedProbe_is_true_once_open_then_false_until_the_probe_interval_elapses(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 2, 'communications.circuit_breaker_failure_threshold_percent' => 80, 'communications.circuit_breaker_probe_interval_minutes' => 30]);
        $mailboxes = collect([$this->failingMailbox(), $this->failingMailbox()]);
        $breaker = app(HostCircuitBreaker::class);
        $breaker->evaluate('shared-host.test', $mailboxes);

        $probeCandidate = $mailboxes->first();
        $this->assertTrue($breaker->isAllowedProbe($probeCandidate), 'first probe allowed immediately on opening');

        $breaker->markProbeSent('shared-host.test');
        $this->assertFalse($breaker->isAllowedProbe($probeCandidate), 'a second probe must not go out before the interval elapses');

        $this->travel(31)->minutes();
        $this->assertTrue($breaker->isAllowedProbe($probeCandidate), 'allowed again once the interval has elapsed');
    }

    public function test_recordPollOutcome_closes_the_breaker_on_a_successful_probe(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 2, 'communications.circuit_breaker_failure_threshold_percent' => 80]);
        $mailboxes = collect([$this->failingMailbox(), $this->failingMailbox()]);
        $breaker = app(HostCircuitBreaker::class);
        $breaker->evaluate('shared-host.test', $mailboxes);
        $this->assertTrue($breaker->isOpen('shared-host.test'));

        $breaker->recordPollOutcome('shared-host.test', true);

        $this->assertFalse($breaker->isOpen('shared-host.test'), 'a probe that proved connectable must close the breaker immediately');
        $row = CommunicationHostCircuitBreaker::where('host', 'shared-host.test')->first();
        $this->assertNull($row->opened_at);
        $this->assertSame(0, $row->consecutive_probe_failures);
    }

    public function test_recordPollOutcome_keeps_the_breaker_open_and_counts_the_failed_probe(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 2, 'communications.circuit_breaker_failure_threshold_percent' => 80]);
        $mailboxes = collect([$this->failingMailbox(), $this->failingMailbox()]);
        $breaker = app(HostCircuitBreaker::class);
        $breaker->evaluate('shared-host.test', $mailboxes);

        $breaker->recordPollOutcome('shared-host.test', false);
        $breaker->recordPollOutcome('shared-host.test', false);

        $this->assertTrue($breaker->isOpen('shared-host.test'));
        $this->assertSame(2, CommunicationHostCircuitBreaker::where('host', 'shared-host.test')->value('consecutive_probe_failures'));
    }

    public function test_recordPollOutcome_is_a_noop_while_closed(): void
    {
        // A normal mailbox's everyday success/failure on a healthy host must
        // never touch breaker state -- evaluate() handles opening, in batch.
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordPollOutcome('never-opened-host.test', false);
        $breaker->recordPollOutcome('never-opened-host.test', true);

        $row = CommunicationHostCircuitBreaker::where('host', 'never-opened-host.test')->first();
        $this->assertSame(CommunicationHostCircuitBreaker::STATE_CLOSED, $row->state);
        $this->assertSame(0, $row->consecutive_probe_failures);
    }

    public function test_opening_alerts_the_affected_agencies_admins_exactly_once(): void
    {
        $this->seedCircuitBreakerEventType();
        Notification::fake();
        config(['communications.circuit_breaker_min_mailboxes' => 2, 'communications.circuit_breaker_failure_threshold_percent' => 80]);
        $admin = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $mailboxes = collect([$this->failingMailbox(), $this->failingMailbox()]);
        $breaker = app(HostCircuitBreaker::class);

        $breaker->evaluate('shared-host.test', $mailboxes);
        Notification::assertSentToTimes($admin, HostCircuitBreakerOpenNotification::class, 1);

        // A second evaluate() while still open must not alert again.
        $breaker->evaluate('shared-host.test', $mailboxes);
        Notification::assertSentToTimes($admin, HostCircuitBreakerOpenNotification::class, 1);
    }

    public function test_thresholds_use_the_most_conservative_agency_override_when_a_host_is_shared(): void
    {
        // Two agencies sharing a host -- the MINIMUM configured threshold
        // among them wins, so the shared resource trips at whichever
        // affected agency wants the most protection, not the least.
        config(['communications.circuit_breaker_min_mailboxes' => 10]);
        $agency2 = Agency::create(['name' => 'T2 ' . Str::random(5), 'slug' => 't2-' . Str::random(8)]);
        DB::table('agencies')->where('id', $this->agencyId)->update(['communication_circuit_breaker_min_mailboxes' => 2]);
        DB::table('agencies')->where('id', $agency2->id)->update(['communication_circuit_breaker_min_mailboxes' => 20]);

        $mailboxes = collect([
            $this->failingMailbox(),
            CommunicationMailbox::create(['agency_id' => $agency2->id, 'email_address' => 'x@shared-host.test', 'imap_host' => 'shared-host.test', 'imap_port' => 993, 'username' => 'x@shared-host.test', 'encrypted_password' => 's', 'poll_inbox' => true, 'poll_sent' => false, 'poll_interval_minutes' => 15, 'active' => true, 'last_error' => 'connect_failed', 'last_error_at' => now()]),
        ]);
        config(['communications.circuit_breaker_failure_threshold_percent' => 80]);

        app(HostCircuitBreaker::class)->evaluate('shared-host.test', $mailboxes);

        $this->assertTrue(app(HostCircuitBreaker::class)->isOpen('shared-host.test'), 'min(2, 20) = 2 mailboxes is enough to evaluate, per the more conservative agency');
    }

    /**
     * 2026-09-08/09 — proves the wiring end to end through the actual job, not
     * just the service in isolation: a mailbox on an OPEN-breaker host whose
     * poll genuinely succeeds closes the breaker via PollMailboxJob::handle(),
     * exactly as it would for the real probe PollMailboxes lets through.
     */
    public function test_pollmailboxjob_closes_an_open_breaker_when_its_poll_succeeds(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 2, 'communications.circuit_breaker_failure_threshold_percent' => 80]);
        $mailbox = $this->mailbox(['imap_host' => 'recovering-host.test']);
        $breaker = app(HostCircuitBreaker::class);
        $breaker->evaluate('recovering-host.test', collect([
            $this->mailbox(['imap_host' => 'recovering-host.test', 'last_error' => 'connect_failed', 'last_error_at' => now()]),
            $this->mailbox(['imap_host' => 'recovering-host.test', 'last_error' => 'connect_failed', 'last_error_at' => now()]),
        ]));
        $this->assertTrue($breaker->isOpen('recovering-host.test'));

        $poller = new class(app(\App\Services\Communications\EmailArchiveIngestor::class)) extends \App\Services\Communications\ImapMailboxPoller {
            public function poll(CommunicationMailbox $mailbox): array
            {
                return ['status' => 'success', 'reason' => null, 'stats' => []];
            }
        };
        $this->app->instance(\App\Services\Communications\ImapMailboxPoller::class, $poller);

        $job = new \App\Jobs\Communications\PollMailboxJob($mailbox->id);
        $job->handle($poller, $breaker);

        $this->assertFalse($breaker->isOpen('recovering-host.test'), 'a genuinely successful poll on the probe mailbox must close the breaker');
    }

    public function test_pollmailboxjob_treats_read_timeout_as_proving_connectivity_but_not_connect_failed(): void
    {
        config(['communications.circuit_breaker_min_mailboxes' => 2, 'communications.circuit_breaker_failure_threshold_percent' => 80]);
        $breaker = app(HostCircuitBreaker::class);

        // read_timeout: auth worked, the read was just slow -- must close.
        $mailboxA = $this->mailbox(['imap_host' => 'host-a.test']);
        $breaker->evaluate('host-a.test', collect([
            $this->mailbox(['imap_host' => 'host-a.test', 'last_error' => 'connect_failed', 'last_error_at' => now()]),
            $this->mailbox(['imap_host' => 'host-a.test', 'last_error' => 'connect_failed', 'last_error_at' => now()]),
        ]));
        $pollerA = new class(app(\App\Services\Communications\EmailArchiveIngestor::class)) extends \App\Services\Communications\ImapMailboxPoller {
            public function poll(CommunicationMailbox $mailbox): array { return ['status' => 'error', 'reason' => 'read_timeout', 'stats' => []]; }
        };
        (new \App\Jobs\Communications\PollMailboxJob($mailboxA->id))->handle($pollerA, $breaker);
        $this->assertFalse($breaker->isOpen('host-a.test'), 'read_timeout proves the host is reachable -- must close, not just log a failed probe');

        // connect_failed: must NOT close -- this is exactly what opened it.
        $mailboxB = $this->mailbox(['imap_host' => 'host-b.test']);
        $breaker->evaluate('host-b.test', collect([
            $this->mailbox(['imap_host' => 'host-b.test', 'last_error' => 'connect_failed', 'last_error_at' => now()]),
            $this->mailbox(['imap_host' => 'host-b.test', 'last_error' => 'connect_failed', 'last_error_at' => now()]),
        ]));
        $pollerB = new class(app(\App\Services\Communications\EmailArchiveIngestor::class)) extends \App\Services\Communications\ImapMailboxPoller {
            public function poll(CommunicationMailbox $mailbox): array { return ['status' => 'error', 'reason' => 'connect_failed', 'stats' => []]; }
        };
        (new \App\Jobs\Communications\PollMailboxJob($mailboxB->id))->handle($pollerB, $breaker);
        $this->assertTrue($breaker->isOpen('host-b.test'), 'a connect-class failure on the probe must keep the breaker open');
    }

    /**
     * 2026-09-09 (Johan, real-attempt-honesty incident) — a real Afrihost 535
     * was classified 'unknown' by MailFailureClassifier (fixed separately)
     * and this method's old `$reason !== 'auth_failed'` check silently
     * discarded it — one genuine failed login against a host with a hard
     * external cap of 3 was never counted. These tests prove the rewritten
     * blocklist counts everything except the two provably-safe cases:
     * no real connection was ever opened, or the login is proven to have
     * succeeded — never "we didn't recognise the reason, so let it slide."
     */
    public function test_an_unrecognised_unknown_reason_still_counts_as_a_spent_attempt(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('mail-honesty.test', 'unknown');

        $this->assertSame(1, CommunicationHostCircuitBreaker::where('host', 'mail-honesty.test')->value('auth_failure_count'));
    }

    public function test_classified_auth_failed_still_counts(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('mail-honesty.test', 'auth_failed');

        $this->assertSame(1, CommunicationHostCircuitBreaker::where('host', 'mail-honesty.test')->value('auth_failure_count'));
    }

    public function test_other_connect_class_failures_count_too_since_a_real_connection_was_attempted(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('mail-honesty.test', 'connect_failed');
        $breaker->recordAuthFailureIfApplicable('mail-honesty.test', 'tls_failed');

        $this->assertSame(2, CommunicationHostCircuitBreaker::where('host', 'mail-honesty.test')->value('auth_failure_count'));
    }

    public function test_incomplete_credentials_never_counts_because_no_connection_was_ever_opened(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('mail-honesty.test', 'incomplete_credentials');

        $this->assertNull(CommunicationHostCircuitBreaker::where('host', 'mail-honesty.test')->first());
    }

    public function test_intercepted_never_counts_because_it_was_a_deliberate_skip(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('mail-honesty.test', 'intercepted');

        $this->assertNull(CommunicationHostCircuitBreaker::where('host', 'mail-honesty.test')->first());
    }

    public function test_reasons_that_prove_login_succeeded_never_count(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        foreach (['send_rejected', 'no_sent_folder', 'append_failed'] as $reason) {
            $breaker->recordAuthFailureIfApplicable('mail-honesty.test', $reason);
        }

        $this->assertNull(CommunicationHostCircuitBreaker::where('host', 'mail-honesty.test')->first(), 'a reason that proves the login succeeded must never be counted as a login failure');
    }

    public function test_null_reason_never_counts(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('mail-honesty.test', null);

        $this->assertNull(CommunicationHostCircuitBreaker::where('host', 'mail-honesty.test')->first());
    }

    /** The exact incident: one Test Connection click, two real legs, both must count when both fail. */
    public function test_one_test_connection_click_with_smtp_unknown_and_imap_auth_failed_counts_both_legs(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        // SMTP leg: misclassified/unrecognised in the field -- must still count.
        $breaker->recordAuthFailureIfApplicable('mail.example-host.test', 'unknown');
        // IMAP leg: correctly classified.
        $breaker->recordAuthFailureIfApplicable('mail.example-host.test', 'auth_failed');

        $this->assertSame(2, CommunicationHostCircuitBreaker::where('host', 'mail.example-host.test')->value('auth_failure_count'), 'two real failed legs against the same host must both count, reaching our threshold of 2');
        $this->assertTrue($breaker->isAuthLocked('mail.example-host.test'), 'reaching the threshold must lock immediately -- this is exactly the scenario that must not silently under-count again');
    }

    private function seedCircuitBreakerEventType(): void
    {
        DB::table('notification_event_types')->insertOrIgnore([
            'key' => 'comms.host_circuit_breaker_open', 'pillar' => 'agent', 'group_label' => 'Communications',
            'label' => 'A mail host is refusing connections',
            'description' => 'Most or all mailboxes on one mail server are failing to connect.',
            'default_enabled' => 1, 'threshold_unit' => 'none', 'supports_in_app' => 1,
            'supports_email' => 0, 'supports_push' => 0, 'is_adapter' => 0, 'sort_order' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
