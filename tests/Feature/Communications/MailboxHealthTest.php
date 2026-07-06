<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\User;
use App\Notifications\Communications\MailboxPollFailureNotification;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\ImapMailboxPoller;
use App\Services\Communications\MailboxHealthRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-181 — mailbox health tracking. The "Active" badge was only the manual flag; a broken
 * mailbox showed green forever. These tests prove: honest badge derivation (all states +
 * staleness), failure recording without advancing last_polled_at, reset on success, the
 * episode-based admin alert (fires once at the threshold, not N+1…, resets on recovery),
 * threshold override, and the read-timeout classification (post-auth, still a failure).
 */
final class MailboxHealthTest extends TestCase
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
            'agency_id' => $this->agencyId, 'email_address' => 'office@agency.test',
            'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'username' => 'office@agency.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => false,
            'poll_interval_minutes' => 15, 'active' => true,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]);
    }

    // ── Badge derivation (model, pure) ────────────────────────────────────────

    public function test_health_states_derive_honestly(): void
    {
        // Inactive — manual off, regardless of poll state.
        $this->assertSame('inactive', $this->mailbox(['active' => false, 'last_polled_at' => now()])->pollHealth());

        // Pending — active, never polled, not yet overdue (created now, threshold 30m).
        $this->assertSame('pending', $this->mailbox(['active' => true, 'last_polled_at' => null])->pollHealth());

        // Healthy — recent successful poll, no error.
        $this->assertSame('healthy', $this->mailbox(['active' => true, 'last_polled_at' => now()->subMinutes(5)])->pollHealth());

        // Failing — a recorded error, even if last_polled_at looks recent.
        $this->assertSame('failing', $this->mailbox([
            'active' => true, 'last_polled_at' => now()->subMinute(), 'last_error' => 'auth_failed', 'last_error_at' => now(),
        ])->pollHealth());

        // Failing — stale: no successful poll within ~2 intervals (2×15 = 30m).
        $this->assertSame('failing', $this->mailbox(['active' => true, 'last_polled_at' => now()->subMinutes(31)])->pollHealth());

        // Failing — never polled AND overdue (created older than the stale window).
        $m = $this->mailbox(['active' => true, 'last_polled_at' => null]);
        $m->forceFill(['created_at' => now()->subHour()])->save();
        $this->assertSame('failing', $m->fresh()->pollHealth());
    }

    public function test_stale_threshold_is_two_poll_intervals(): void
    {
        $this->assertSame(30, $this->mailbox(['poll_interval_minutes' => 15])->staleThresholdMinutes());
        $this->assertSame(2, $this->mailbox(['poll_interval_minutes' => 0])->staleThresholdMinutes()); // clamped ≥1
    }

    // ── Failure recording via the poller (no last_polled_at advance) ───────────

    public function test_connect_failure_records_without_advancing_last_polled_at(): void
    {
        $mailbox = $this->mailbox();
        $this->assertNull($mailbox->last_polled_at);

        $poller = new class (app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            protected function connect(CommunicationMailbox $mailbox)
            {
                throw new \RuntimeException('Connection refused');
            }
        };

        $result = $poller->poll($mailbox);
        $mailbox->refresh();

        $this->assertSame('connect_failed', $result['reason']);
        $this->assertSame('connect_failed', $mailbox->last_error);
        $this->assertSame(1, $mailbox->consecutive_failures);
        $this->assertNotNull($mailbox->last_error_at);
        $this->assertNull($mailbox->last_polled_at, 'a connect failure must never advance last_polled_at (the truth signal)');
        $this->assertSame('failing', $mailbox->pollHealth());
    }

    public function test_login_rejection_is_classified_as_auth_failed(): void
    {
        $mailbox = $this->mailbox();

        $poller = new class (app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            protected function connect(CommunicationMailbox $mailbox)
            {
                throw new \RuntimeException('[AUTHENTICATIONFAILED] Invalid credentials');
            }
        };

        $poller->poll($mailbox);

        $this->assertSame('auth_failed', $mailbox->fresh()->last_error);
    }

    public function test_incomplete_credentials_records_failure(): void
    {
        $mailbox = $this->mailbox(['encrypted_password' => null]);

        $result = app(ImapMailboxPoller::class)->poll($mailbox);
        $mailbox->refresh();

        $this->assertSame('incomplete_credentials', $result['reason']);
        $this->assertSame('incomplete_credentials', $mailbox->last_error);
        $this->assertSame(1, $mailbox->consecutive_failures);
        $this->assertNull($mailbox->last_polled_at);
    }

    public function test_successful_poll_clears_prior_failure_state(): void
    {
        $mailbox = $this->mailbox([
            'last_error' => 'connect_failed', 'last_error_at' => now()->subHour(),
            'consecutive_failures' => 4, 'failure_notified_at' => now()->subHour(),
        ]);

        // A fake client whose INBOX read returns no messages → a clean, successful poll.
        $poller = new class (app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            protected function connect(CommunicationMailbox $mailbox)
            {
                $folder = new class {
                    public function query() { return $this; }
                    public function since($d) { return $this; }
                    public function get() { return []; }
                };

                return new class ($folder) {
                    public function __construct(private $folder) {}
                    public function getFolderByPath($path) { return $this->folder; }
                    public function disconnect(): void {}
                };
            }
        };

        $result = $poller->poll($mailbox);
        $mailbox->refresh();

        $this->assertSame('success', $result['status']);
        $this->assertNull($mailbox->last_error);
        $this->assertSame(0, $mailbox->consecutive_failures);
        $this->assertNull($mailbox->failure_notified_at, 'recovery must end the alert episode');
        $this->assertNotNull($mailbox->last_polled_at);
        $this->assertSame('healthy', $mailbox->pollHealth());
    }

    // ── Episode-based admin alert (MailboxHealthRecorder, no IMAP) ─────────────

    public function test_admin_alert_fires_once_at_threshold_and_resets_on_recovery(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $mailbox = $this->mailbox();
        $recorder = new MailboxHealthRecorder();

        // Default threshold 3: no alert on failures 1 and 2.
        $recorder->recordFailure($mailbox, 'connect_failed');
        $recorder->recordFailure($mailbox, 'connect_failed');
        Notification::assertNothingSentTo($admin);

        // Failure 3 → the episode alert fires exactly once.
        $recorder->recordFailure($mailbox, 'connect_failed');
        Notification::assertSentToTimes($admin, MailboxPollFailureNotification::class, 1);

        // Failures 4 and 5 → still exactly one (no storm).
        $recorder->recordFailure($mailbox, 'connect_failed');
        $recorder->recordFailure($mailbox, 'connect_failed');
        Notification::assertSentToTimes($admin, MailboxPollFailureNotification::class, 1);

        // Recovery ends the episode; a fresh streak alerts again.
        $recorder->recordSuccess($mailbox);
        $this->assertNull($mailbox->fresh()->failure_notified_at);

        $recorder->recordFailure($mailbox, 'connect_failed');
        $recorder->recordFailure($mailbox, 'connect_failed');
        $recorder->recordFailure($mailbox, 'connect_failed');
        Notification::assertSentToTimes($admin, MailboxPollFailureNotification::class, 2);
    }

    public function test_agency_threshold_override_is_honoured(): void
    {
        Notification::fake();
        $admin = $this->admin();
        DB::table('agencies')->where('id', $this->agencyId)->update(['communication_failure_alert_threshold' => 2]);

        $mailbox = $this->mailbox();
        $recorder = new MailboxHealthRecorder();

        $recorder->recordFailure($mailbox, 'auth_failed');
        Notification::assertNothingSentTo($admin);
        $recorder->recordFailure($mailbox, 'auth_failed');
        Notification::assertSentToTimes($admin, MailboxPollFailureNotification::class, 1);
    }

    // ── Read-timeout classification (post-auth: advances last_polled_at, still a failure) ──

    public function test_read_timeout_advances_last_polled_at_but_records_failure(): void
    {
        if (! function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl not available — the hard watchdog needs it.');
        }

        $mailbox = $this->mailbox();
        config(['communications.imap_poll_budget_seconds' => 1]);

        $poller = new class (app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            protected function connect(CommunicationMailbox $mailbox)
            {
                $folder = new class {
                    public function query() { return $this; }
                    public function since($d) { return $this; }
                    public function get() { sleep(5); return []; }
                };

                return new class ($folder) {
                    public function __construct(private $folder) {}
                    public function getFolderByPath($path) { return $this->folder; }
                    public function disconnect(): void {}
                };
            }
        };

        $result = $poller->poll($mailbox);
        $mailbox->refresh();

        $this->assertSame('read_timeout', $result['reason']);
        $this->assertSame('read_timeout', $mailbox->last_error);
        $this->assertSame(1, $mailbox->consecutive_failures);
        // Auth SUCCEEDED, so last_polled_at legitimately advanced (the finally-path) — the failure
        // is a read completion problem, not an auth problem. Badge still shows Failing via last_error.
        $this->assertNotNull($mailbox->last_polled_at);
        $this->assertSame('failing', $mailbox->pollHealth());
    }
}
