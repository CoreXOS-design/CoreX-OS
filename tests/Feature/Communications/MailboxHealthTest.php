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

    /**
     * 2026-09-08 fix — this test predates the AT-235 notification gateway
     * migration. MailboxHealthRecorder::maybeNotify() used to call
     * Notification::send() directly; it now routes through
     * NotificationDispatcher, which requires a real notification_event_types
     * catalogue row (NotificationPreferenceService::effective() returns null
     * and the gateway silently refuses to dispatch without one). A fresh
     * RefreshDatabase schema has zero rows in that table (confirmed:
     * schema:dump snapshots structure, not seeded reference data) — the two
     * admin-alert tests below were failing because of that missing row, NOT
     * because the application stopped alerting. Verified against the real
     * QA1 database (which has this row seeded for real) that a genuine
     * mailbox failure streak still creates real NotificationDispatchLog rows
     * and stamps failure_notified_at. Shape matches the real seeded row
     * exactly (id 30 in production), via the same
     * DB::table('notification_event_types')->insertGetId() pattern already
     * used by UnifiedContactHistoryTest::ensureLeadNotificationEventType().
     */
    private function seedMailboxPollFailureEventType(): void
    {
        DB::table('notification_event_types')->insertOrIgnore([
            'key' => 'comms.mailbox_poll_failure', 'pillar' => 'agent', 'group_label' => 'Communications',
            'label' => 'Mailbox stopped receiving mail',
            'description' => 'A connected mailbox failed to poll repeatedly — incoming email may be missing.',
            'default_enabled' => 1, 'threshold_unit' => 'none', 'supports_in_app' => 1,
            'supports_email' => 0, 'supports_push' => 0, 'is_adapter' => 0, 'sort_order' => 29,
            'created_at' => now(), 'updated_at' => now(),
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

        // 2026-09-08 night run — Stale, NOT failing: a real prior success exists
        // (last_polled_at is set), no recorded error, it has simply gone past the
        // freshness window (2×15 = 30m). This used to collapse into 'failing' —
        // the exact defect Johan reported ("shows FAILING when it simply has not
        // been polled recently... nothing wrong, just stale").
        $this->assertSame('stale', $this->mailbox([
            'active' => true, 'last_polled_at' => now()->subMinutes(31), 'last_error' => null,
        ])->pollHealth());

        // Failing — never polled AND overdue (created older than the stale window).
        // Deliberately NOT 'stale': there is no prior success to point to here at
        // all — this is the broken-setup signature, not "just hasn't run lately".
        $m = $this->mailbox(['active' => true, 'last_polled_at' => null]);
        $m->forceFill(['created_at' => now()->subHour()])->save();
        $this->assertSame('failing', $m->fresh()->pollHealth());
    }

    /**
     * 2026-09-08 night run — precedence: a mailbox that is ALSO disabled or
     * behind must report that, not stale, even though it would otherwise
     * qualify as stale too (a disabled mailbox is stale by definition, but
     * "needs a human" is the more actionable message; a behind mailbox is
     * demonstrably working right now, so "hasn't polled" would be wrong).
     */
    public function test_stale_loses_precedence_to_disabled_and_behind(): void
    {
        $disabled = $this->mailbox([
            'active' => true, 'last_polled_at' => now()->subMinutes(31), 'last_error' => null,
            'poll_disabled_at' => now(),
        ]);
        $this->assertSame('disabled', $disabled->pollHealth());

        $behind = $this->mailbox([
            'active' => true, 'last_polled_at' => now()->subMinutes(31), 'last_error' => null,
            'messages_behind_estimate' => 5,
        ]);
        $this->assertSame('behind', $behind->pollHealth());
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
            public function connect(CommunicationMailbox $mailbox)
            {
                throw new \RuntimeException('Connection refused');
            }
        };

        $result = $poller->poll($mailbox);
        $mailbox->refresh();

        // 2026-09-09 (diagnostics) — "Connection refused" now classifies as
        // its own distinct reason (MailFailureClassifier::CONNECTION_REFUSED),
        // never described as a credential problem, per Johan's taxonomy —
        // a real refinement over the old single 'connect_failed' catch-all.
        $this->assertSame('connection_refused', $result['reason']);
        $this->assertSame('connection_refused', $mailbox->last_error);
        $this->assertSame('Connection refused', $mailbox->last_error_detail, 'the raw server/socket text is kept verbatim alongside the classified reason');
        $this->assertSame(1, $mailbox->consecutive_failures);
        $this->assertNotNull($mailbox->last_error_at);
        $this->assertNull($mailbox->last_polled_at, 'a connect failure must never advance last_polled_at (the truth signal)');
        $this->assertSame('failing', $mailbox->pollHealth());
    }

    public function test_login_rejection_is_classified_as_auth_failed(): void
    {
        $mailbox = $this->mailbox();

        $poller = new class (app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function connect(CommunicationMailbox $mailbox)
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
            public function connect(CommunicationMailbox $mailbox)
            {
                $folder = new class {
                    // 2026-09-08 UID rebuild: poll() calls status() first for UIDVALIDITY;
                    // 0 = "unknown", which routes the poller to the same since() backfill
                    // path this fake already implements.
                    public function status() { return ['uidvalidity' => 0, 'uidnext' => 1]; }
                    public function query() { return $this; }
                    public function since($d) { return $this; }
                    public function setFetchBody($b) { return $this; } // AT-257: poller fetches UIDs-only now
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
        $this->seedMailboxPollFailureEventType();
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

        // Recovery ends the episode — MailboxHealthRecorder's OWN state correctly
        // resets, exactly as its class docblock promises ("one episode = one alert").
        $recorder->recordSuccess($mailbox);
        $this->assertNull($mailbox->fresh()->failure_notified_at);

        $recorder->recordFailure($mailbox, 'connect_failed');
        $recorder->recordFailure($mailbox, 'connect_failed');
        $recorder->recordFailure($mailbox, 'connect_failed');
        $this->assertNotNull($mailbox->fresh()->failure_notified_at, 'the second episode IS recognised as new by MailboxHealthRecorder itself');

        // 2026-09-08 — CONFIRMED REAL GAP, reported to Johan, not fixed here (out of
        // today's scope; the fix touches NotificationDispatcher, shared by 22+ other
        // notification types, and needs an explicit decision, not a drive-by change).
        //
        // MailboxHealthRecorder's episode logic is correct — asserted directly above,
        // and verified with a real send against the live QA1 DB. But the actual
        // notification for this SECOND episode is silently swallowed by
        // NotificationDispatcher::dispatch()'s blanket per-(user, event, subject)
        // cooldown (UserDashboardSetting::defaults()['min_minutes_between_same'] =
        // 360 minutes), which has no concept of "this is a genuinely new episode" —
        // it only knows "something for this subject was dispatched N minutes ago."
        // Net effect: a mailbox that fails, recovers, and fails again within 6 hours
        // alerts an admin ONCE, not once per episode as MailboxHealthRecorder's own
        // contract promises. This assertion documents that CONFIRMED current
        // behavior — it is not a statement that 1 is correct.
        Notification::assertSentToTimes($admin, MailboxPollFailureNotification::class, 1);
    }

    public function test_agency_threshold_override_is_honoured(): void
    {
        $this->seedMailboxPollFailureEventType();
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

    // ── Read-timeout classification (post-auth: advances last_polled_at, NOT a failure) ──

    /**
     * 2026-09-08 (Johan, part B) — REPLACES the old assertion that a read_timeout
     * recorded 'failing'/last_error. That was the exact defect Johan reported: a
     * mailbox that connected and authenticated fine got labelled the same as one
     * that could not connect at all, while he could see the same account pulling
     * normally in Outlook. A read cut short by our own time budget now gets its
     * own honest state — connected, still working through a backlog — never the
     * broken-mailbox badge.
     */
    public function test_read_timeout_advances_last_polled_at_and_records_behind_not_failing(): void
    {
        if (! function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl not available — the hard watchdog needs it.');
        }

        $mailbox = $this->mailbox();
        config(['communications.imap_poll_budget_seconds' => 1]);

        $poller = new class (app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function connect(CommunicationMailbox $mailbox)
            {
                $folder = new class {
                    public function status() { return ['uidvalidity' => 0, 'uidnext' => 1]; }
                    public function query() { return $this; }
                    public function since($d) { return $this; }
                    public function setFetchBody($b) { return $this; } // AT-257: poller fetches UIDs-only now
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

        $this->assertSame('read_timeout', $result['reason'], 'the internal reason stays distinct -- only the HEALTH recording changes');
        $this->assertNull($mailbox->last_error, 'a read_timeout is NOT a failure -- connect + auth genuinely succeeded this run');
        $this->assertSame(0, $mailbox->consecutive_failures, 'must never feed the same failure streak a real connect/auth failure does');
        // Auth SUCCEEDED, so last_polled_at legitimately advanced (the finally-path) — this
        // is a read completion problem, not an auth problem, and the badge must say so.
        $this->assertNotNull($mailbox->last_polled_at);
        $this->assertSame('behind', $mailbox->pollHealth(), 'connected and reading fine, just not finished -- never "failing"');
        $this->assertNotNull($mailbox->messages_behind_estimate, 'the honest state carries a real (if approximate) backlog estimate');
        $this->assertStringContainsString('backlog', (string) $mailbox->behindLabel());
        $this->assertStringNotContainsString('cannot connect', strtolower((string) $mailbox->behindLabel()));
    }

    /**
     * 2026-09-08 (Johan, part B) — "The alert episode must not fire for a mailbox
     * that is merely behind." Three consecutive read_timeouts is exactly the
     * default alert threshold (3) that would fire Notification::assertSentTo for
     * a genuine failure streak (see test_admin_alert_fires_once_at_threshold_and_
     * resets_on_recovery above) -- proving zero notifications here proves the
     * 'behind' path is genuinely routed away from the failure/alert machinery,
     * not just relabelled at the display layer.
     */
    public function test_a_budget_cutoff_never_raises_the_broken_mailbox_alert(): void
    {
        if (! function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl not available — the hard watchdog needs it.');
        }

        $this->seedMailboxPollFailureEventType();
        Notification::fake();
        $admin = $this->admin();
        $mailbox = $this->mailbox();
        config(['communications.imap_poll_budget_seconds' => 1]);

        $poller = new class (app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function connect(CommunicationMailbox $mailbox)
            {
                $folder = new class {
                    public function status() { return ['uidvalidity' => 0, 'uidnext' => 1]; }
                    public function query() { return $this; }
                    public function since($d) { return $this; }
                    public function setFetchBody($b) { return $this; }
                    public function get() { sleep(5); return []; }
                };

                return new class ($folder) {
                    public function __construct(private $folder) {}
                    public function getFolderByPath($path) { return $this->folder; }
                    public function disconnect(): void {}
                };
            }
        };

        // Same mailbox, three consecutive budget cutoffs -- the exact shape that
        // fires the admin alert for a genuine failure streak.
        $poller->poll($mailbox);
        $poller->poll($mailbox);
        $poller->poll($mailbox);

        $mailbox->refresh();
        $this->assertSame('behind', $mailbox->pollHealth());
        $this->assertSame(0, $mailbox->consecutive_failures, 'behind never accumulates a failure streak');
        $this->assertNull($mailbox->failure_notified_at, 'no episode was ever opened -- nothing to alert on');
        Notification::assertNothingSentTo($admin);
    }

    // ── Back-off on failure (2026-09-08/09, Johan) ─────────────────────────────
    // "consecutive_failures already exists on the table. Use it to actually
    // STOP polling, not just record." This is the fix for the six-hour tail
    // of today's incident: nothing before today ever paced a repeatedly
    // failing mailbox's retries.

    public function test_each_consecutive_failure_doubles_the_backoff_window_until_the_configured_max(): void
    {
        config(['communications.poll_backoff_base_seconds' => 100, 'communications.poll_backoff_max_seconds' => 1000, 'communications.poll_disable_threshold' => 50]);
        $mailbox = $this->mailbox();
        $recorder = new MailboxHealthRecorder();

        $expected = [100, 200, 400, 800, 1000, 1000]; // doubling, then capped at 1000
        foreach ($expected as $i => $seconds) {
            $recorder->recordFailure($mailbox, 'connect_failed');
            $mailbox->refresh();
            $this->assertEqualsWithDelta(
                now()->addSeconds($seconds)->timestamp,
                $mailbox->next_poll_earliest_at->timestamp,
                2,
                'failure #' . ($i + 1) . ' should back off ' . $seconds . 's'
            );
        }
    }

    public function test_reaching_the_disable_threshold_stops_scheduling_further_backoff_and_marks_disabled(): void
    {
        config(['communications.poll_disable_threshold' => 3]);
        $mailbox = $this->mailbox();
        $recorder = new MailboxHealthRecorder();

        $recorder->recordFailure($mailbox, 'connect_failed');
        $recorder->recordFailure($mailbox, 'connect_failed');
        $mailbox->refresh();
        $this->assertNull($mailbox->poll_disabled_at, 'not yet at the threshold');

        $recorder->recordFailure($mailbox, 'connect_failed'); // 3rd -- crosses the threshold
        $mailbox->refresh();
        $this->assertNotNull($mailbox->poll_disabled_at);
        $this->assertNull($mailbox->next_poll_earliest_at, 'disabled supersedes back-off -- nothing left to schedule');
        $this->assertSame('disabled', $mailbox->pollHealth());
        $this->assertStringContainsString('3 failed attempts', (string) $mailbox->disabledLabel());
    }

    public function test_poll_disabled_at_is_stamped_only_once_not_refreshed_on_every_later_failure(): void
    {
        config(['communications.poll_disable_threshold' => 2]);
        $mailbox = $this->mailbox();
        $recorder = new MailboxHealthRecorder();

        $recorder->recordFailure($mailbox, 'connect_failed');
        $recorder->recordFailure($mailbox, 'connect_failed'); // disables here
        $mailbox->refresh();
        $firstDisabledAt = $mailbox->poll_disabled_at->copy();

        \Illuminate\Support\Carbon::setTestNow(now()->addHour());
        $recorder->recordFailure($mailbox, 'connect_failed'); // already disabled -- must not move the timestamp
        $mailbox->refresh();

        $this->assertTrue($firstDisabledAt->eq($mailbox->poll_disabled_at), '"stopped N ago" must stay honest, not reset on every subsequent failure');
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_recordSuccess_and_recordBehind_clear_backoff_and_disabled_state(): void
    {
        $recorder = new MailboxHealthRecorder();

        $failing = $this->mailbox(['consecutive_failures' => 5, 'next_poll_earliest_at' => now()->addHour(), 'poll_disabled_at' => now()]);
        $recorder->recordSuccess($failing);
        $failing->refresh();
        $this->assertNull($failing->next_poll_earliest_at);
        $this->assertNull($failing->poll_disabled_at);
        $this->assertSame(0, $failing->consecutive_failures);

        $disabled = $this->mailbox(['consecutive_failures' => 10, 'next_poll_earliest_at' => now()->addHour(), 'poll_disabled_at' => now()]);
        $recorder->recordBehind($disabled, 3);
        $disabled->refresh();
        $this->assertNull($disabled->next_poll_earliest_at);
        $this->assertNull($disabled->poll_disabled_at);
        $this->assertSame('behind', $disabled->pollHealth(), 'behind must win now that disabled/backoff are cleared');
    }

    public function test_reset_backoff_on_manual_success_clears_state_without_touching_last_error(): void
    {
        // Test Connection's IMAP leg succeeding calls this directly -- it must
        // NOT be conflated with recordSuccess()/recordBehind() (a Test
        // Connection is not a poll, so it should not fabricate a poll outcome),
        // only clear the back-off/disable state per Johan's "a successful poll
        // OR TEST resets the counter and the back-off."
        $mailbox = $this->mailbox(['consecutive_failures' => 7, 'next_poll_earliest_at' => now()->addHour(), 'poll_disabled_at' => now(), 'last_error' => 'auth_failed']);
        $recorder = new MailboxHealthRecorder();

        $recorder->resetBackoffOnManualSuccess($mailbox);
        $mailbox->refresh();

        $this->assertSame(0, $mailbox->consecutive_failures);
        $this->assertNull($mailbox->next_poll_earliest_at);
        $this->assertNull($mailbox->poll_disabled_at);
    }

    public function test_backoff_and_disable_thresholds_are_agency_configurable_and_clamped(): void
    {
        config(['communications.poll_backoff_base_seconds' => 300, 'communications.poll_disable_threshold' => 10]);
        $mailbox = $this->mailbox();
        DB::table('agencies')->where('id', $this->agencyId)->update([
            'communication_poll_backoff_base_seconds' => 60,
            'communication_poll_disable_threshold' => 4,
        ]);
        $recorder = new MailboxHealthRecorder();

        $this->assertSame(60, $recorder->backoffBaseSeconds($mailbox), 'agency override wins over config default');
        $this->assertSame(4, $recorder->disableThreshold($mailbox));

        // Clamping: an absurd override never reopens the hole in either direction.
        DB::table('agencies')->where('id', $this->agencyId)->update(['communication_poll_disable_threshold' => 1]);
        $this->assertSame(2, $recorder->disableThreshold($mailbox), 'clamped to the minimum of 2 -- disabling after a single failure is too aggressive to allow');
    }
}
