<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Http\Controllers\Compliance\CommunicationMailboxController;
use App\Jobs\Communications\PollMailboxJob;
use App\Models\Communications\CommunicationMailbox;
use App\Models\User;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\HostCircuitBreaker;
use App\Services\Communications\ImapMailboxPoller;
use App\Services\Communications\ImapSentFolderAppender;
use App\Services\Communications\MailboxHealthRecorder;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-09 (Johan, auth-lock safeguard) — Afrihost's unblock came with an
 * absolute condition: "no more than 3 failed login attempts... the existing
 * ... thresholds cannot be changed." These tests prove the retuned design
 * holds that budget under the shape that actually caused the ban (Test
 * Connection is TWO real logins per click) as well as under ordinary
 * polling, using ONLY fake hosts and in-process doubles that throw if a real
 * connection method is ever reached — NEVER a live IMAP/SMTP connection.
 *
 * Three signals, never conflated (see CLAUDE.md's standing note for this
 * subsystem):
 *  - mailbox-level poll_disabled_at (MailboxHealthRecorder) — should THIS
 *    mailbox be retried?
 *  - host-level auth_locked_at (HostCircuitBreaker) — can ANYONE attempt a
 *    real login against THIS host right now?
 *  - the existing general breaker's open/closed state — is this host merely
 *    slow/flaky? (untouched by this file; see HostCircuitBreakerTest.php)
 */
final class HostAuthLockTest extends TestCase
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
            'agency_id' => $this->agencyId, 'email_address' => 'office-' . Str::random(6) . '@auth-lock-host.test',
            'imap_host' => 'auth-lock-host.test', 'imap_port' => 993, 'username' => 'office@auth-lock-host.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => false,
            'poll_interval_minutes' => 15, 'active' => true,
        ], $overrides));
    }

    // ── HostCircuitBreaker: the host-level budget itself ───────────────────

    public function test_a_fresh_host_is_not_auth_locked(): void
    {
        $this->assertFalse(app(HostCircuitBreaker::class)->isAuthLocked('auth-lock-host.test'));
    }

    public function test_a_non_auth_failure_never_counts_toward_the_auth_budget(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        foreach (['connect_failed', 'connect_timeout', 'read_timeout', null] as $reason) {
            $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', $reason);
        }

        $this->assertSame(0, $breaker->authFailureCount('auth-lock-host.test'));
        $this->assertFalse($breaker->isAuthLocked('auth-lock-host.test'));
    }

    public function test_auth_failures_trip_the_lock_at_two_not_three(): void
    {
        $breaker = app(HostCircuitBreaker::class);

        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $this->assertSame(1, $breaker->authFailureCount('auth-lock-host.test'));
        $this->assertFalse($breaker->isAuthLocked('auth-lock-host.test'), '"leave ourselves margin" -- 1 of the internal 2-failure budget must not lock yet');

        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $this->assertSame(2, $breaker->authFailureCount('auth-lock-host.test'));
        $this->assertTrue($breaker->isAuthLocked('auth-lock-host.test'), 'the 2nd real auth failure must trip the lock -- Johan: "Trip at 2. Leave ourselves margin."');

        $this->assertSame(HostCircuitBreaker::AUTH_FAILURE_LOCK_THRESHOLD, 2);
        $this->assertSame(HostCircuitBreaker::KNOWN_PROVIDER_LOGIN_LIMIT, 3, 'the real provider ceiling is tracked for display only, never as our own trip point');
    }

    public function test_three_different_mailboxes_each_failing_once_still_trips_the_shared_host_budget(): void
    {
        // Johan: "Three mailboxes each failing once is three failed logins
        // from the host's point of view ... with no single mailbox looking
        // guilty." Counted at the HOST, not per mailbox.
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed'); // mailbox A
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed'); // mailbox B -- different mailbox, same host

        $this->assertTrue($breaker->isAuthLocked('auth-lock-host.test'));
    }

    public function test_once_locked_the_count_does_not_climb_further(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $this->assertTrue($breaker->isAuthLocked('auth-lock-host.test'));

        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');

        $this->assertSame(2, $breaker->authFailureCount('auth-lock-host.test'), 'once locked, further calls are a no-op -- the count is frozen, not an ever-climbing tally');
    }

    public function test_an_auth_lock_never_self_heals(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $this->assertTrue($breaker->isAuthLocked('auth-lock-host.test'));

        // Nothing except the explicit human action clears it -- not time,
        // not a fresh evaluate()/recordPollOutcome() cycle on the general breaker.
        $breaker->evaluate('auth-lock-host.test', collect([$this->mailbox()]));
        $breaker->recordPollOutcome('auth-lock-host.test', true);
        $this->assertTrue($breaker->isAuthLocked('auth-lock-host.test'), 'no automatic path may ever clear an auth lock');

        $breaker->resetAuthLock('auth-lock-host.test');
        $this->assertFalse($breaker->isAuthLocked('auth-lock-host.test'));
        $this->assertSame(0, $breaker->authFailureCount('auth-lock-host.test'));
    }

    public function test_hosts_for_includes_a_distinct_smtp_host_even_when_outgoing_is_not_flagged_enabled(): void
    {
        // 2026-09-09 — Test Connection's SMTP leg is unconditional (see
        // PerMailboxMailTransportBuilder::send()): it fires whenever
        // smtp_host/username/password are populated, regardless of
        // outgoing_enabled. hostsFor() must therefore ALSO be unconditional,
        // or a real login to a locked smtp_host would slip past the guard.
        $breaker = app(HostCircuitBreaker::class);
        $mailbox = $this->mailbox([
            'outgoing_enabled' => false, 'smtp_host' => 'smtp.auth-lock-host.test',
        ]);

        $this->assertSame(['auth-lock-host.test', 'smtp.auth-lock-host.test'], $breaker->hostsFor($mailbox));
    }

    public function test_hosts_for_is_a_single_host_when_smtp_shares_the_imap_hostname(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $mailbox = $this->mailbox(['smtp_host' => 'auth-lock-host.test']);

        $this->assertSame(['auth-lock-host.test'], $breaker->hostsFor($mailbox));
    }

    // ── MailboxHealthRecorder: the mailbox-level "stop retrying THIS one" signal ──

    public function test_an_auth_failure_disables_the_mailbox_on_the_first_occurrence(): void
    {
        config(['communications.poll_disable_threshold' => 10]); // generic threshold, deliberately NOT reached
        $mailbox = $this->mailbox();

        app(MailboxHealthRecorder::class)->recordFailure($mailbox, 'auth_failed');
        $mailbox->refresh();

        $this->assertSame(1, $mailbox->consecutive_failures);
        $this->assertNotNull($mailbox->poll_disabled_at, 'Johan: "on the FIRST authentication failure for a mailbox, stop polling that mailbox immediately. Not after three. After one."');
        $this->assertNull($mailbox->next_poll_earliest_at, 'a disabled mailbox has no back-off window to wait out -- it simply does not run again');
    }

    public function test_a_non_auth_failure_still_uses_the_normal_disable_threshold(): void
    {
        config(['communications.poll_disable_threshold' => 10]);
        $mailbox = $this->mailbox();

        app(MailboxHealthRecorder::class)->recordFailure($mailbox, 'connect_failed');
        $mailbox->refresh();

        $this->assertSame(1, $mailbox->consecutive_failures);
        $this->assertNull($mailbox->poll_disabled_at, 'a merely slow/unreachable host is still the exponential back-off ladder, unchanged by this retune');
        $this->assertNotNull($mailbox->next_poll_earliest_at);
    }

    // ── PollMailboxJob: the EXECUTION-time re-check (the real race-condition backstop) ──

    public function test_poll_is_never_attempted_when_the_host_is_auth_locked(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $this->assertTrue($breaker->isAuthLocked('auth-lock-host.test'));

        $mailbox = $this->mailbox();
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function poll(CommunicationMailbox $mailbox): array
            {
                throw new \RuntimeException('poll() must never be called once the host auth lock is active');
            }
        };

        // No exception -- proves the job returned before ever reaching poll().
        (new PollMailboxJob($mailbox->id))->handle($poller, $breaker);

        $mailbox->refresh();
        $this->assertNull($mailbox->last_error, 'nothing was attempted, so no failure of any kind may be recorded');
        $this->assertNull($mailbox->last_polled_at);
    }

    public function test_poll_proceeds_normally_when_the_host_is_not_auth_locked(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $mailbox = $this->mailbox();
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public int $pollCalls = 0;

            public function poll(CommunicationMailbox $mailbox): array
            {
                $this->pollCalls++;

                return ['status' => 'success', 'reason' => null, 'stats' => []];
            }
        };

        (new PollMailboxJob($mailbox->id))->handle($poller, $breaker);

        $this->assertSame(1, $poller->pollCalls, 'the guard must not block a genuinely unlocked host');
    }

    public function test_a_polls_own_auth_failure_is_recorded_against_the_host_budget(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $mailbox = $this->mailbox();
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function poll(CommunicationMailbox $mailbox): array
            {
                return ['status' => 'error', 'reason' => 'auth_failed', 'stats' => []];
            }
        };

        (new PollMailboxJob($mailbox->id))->handle($poller, $breaker);

        $this->assertSame(1, $breaker->authFailureCount('auth-lock-host.test'), "a poll's own real login failure is exactly as real as a Test Connection click");
    }

    // ── PollMailboxes: dispatch-time hygiene (defence in depth; execution-time above is the real backstop) ──

    public function test_poll_mailboxes_dispatches_nothing_at_all_for_an_auth_locked_host(): void
    {
        Queue::fake();
        app(HostCircuitBreaker::class)->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        app(HostCircuitBreaker::class)->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');

        for ($i = 0; $i < 3; $i++) {
            $this->mailbox(['email_address' => "locked{$i}@auth-lock-host.test", 'last_polled_at' => null]);
        }
        // A healthy mailbox on a different host must be unaffected.
        $healthy = $this->mailbox(['imap_host' => 'other-host.test', 'email_address' => 'ok@other-host.test', 'last_polled_at' => null]);

        $this->artisan('communications:poll-mailboxes')->assertSuccessful();

        Queue::assertPushed(PollMailboxJob::class, 1);
        Queue::assertPushed(PollMailboxJob::class, fn (PollMailboxJob $job) => $job->mailboxId === $healthy->id);
    }

    // ── Test Connection controller: the primary risk surface (Johan) ──────

    public function test_test_connection_refuses_both_legs_without_any_real_attempt_when_locked(): void
    {
        $breaker = app(HostCircuitBreaker::class);
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $breaker->recordAuthFailureIfApplicable('auth-lock-host.test', 'auth_failed');
        $this->assertTrue($breaker->isAuthLocked('auth-lock-host.test'));

        $admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->actingAs($admin);
        $mailbox = $this->mailbox(['smtp_host' => 'auth-lock-host.test']);

        $transportBuilder = new class extends PerMailboxMailTransportBuilder {
            public function send(CommunicationMailbox $mailbox, \Illuminate\Mail\Mailable $mailable): string
            {
                throw new \RuntimeException('SMTP must never be attempted while the host auth lock is active');
            }
        };
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function connect(CommunicationMailbox $mailbox)
            {
                throw new \RuntimeException('IMAP must never be attempted while the host auth lock is active');
            }
        };
        $appender = new ImapSentFolderAppender($poller);

        $controller = new CommunicationMailboxController();
        $rateLimiter = app(\App\Services\Communications\MailboxConnectionRateLimiter::class);
        $response = $controller->testConnection(new Request(), $mailbox, $transportBuilder, $appender, $rateLimiter, $breaker);

        $flashed = $response->getSession()->get('test_connection_result');
        $this->assertFalse($flashed['smtp']['ok']);
        $this->assertFalse($flashed['imap_append']['ok']);
        $this->assertStringContainsString('login-failure limit', $flashed['smtp']['message']);
    }

    public function test_test_connection_records_a_real_smtp_auth_failure_against_the_host_budget(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->actingAs($admin);
        $mailbox = $this->mailbox(['smtp_host' => 'smtp.auth-lock-host.test']);

        $transportBuilder = new class extends PerMailboxMailTransportBuilder {
            public function send(CommunicationMailbox $mailbox, \Illuminate\Mail\Mailable $mailable): string
            {
                throw new OutgoingMailboxSendFailedException('auth_failed', 'Login failed.');
            }
        };
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function connect(CommunicationMailbox $mailbox)
            {
                throw new \RuntimeException('not exercised by this test');
            }
        };
        $appender = new class($poller) extends ImapSentFolderAppender {
            public function append(CommunicationMailbox $mailbox, string $rawMime): array
            {
                return ['ok' => false, 'reason' => 'connect_failed']; // IMAP leg: a DIFFERENT, non-auth failure
            }
        };

        $controller = new CommunicationMailboxController();
        $rateLimiter = app(\App\Services\Communications\MailboxConnectionRateLimiter::class);
        $breaker = app(HostCircuitBreaker::class);
        $controller->testConnection(new Request(), $mailbox, $transportBuilder, $appender, $rateLimiter, $breaker);

        $this->assertSame(1, $breaker->authFailureCount('smtp.auth-lock-host.test'), "the SMTP leg's own real login failure counts against ITS OWN host");
        $this->assertSame(0, $breaker->authFailureCount('auth-lock-host.test'), 'the IMAP leg failed for an unrelated reason -- must not count toward the auth budget');
    }
}
