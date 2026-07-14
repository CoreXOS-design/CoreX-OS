<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\CommandCenter\UserNotificationPreference;
use App\Models\Communications\CommunicationMailbox;
use App\Models\User;
use App\Notifications\Communications\MailboxPollFailureNotification;
use App\Services\Communications\MailboxHealthRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AT-235 (S2, slice b) — the Communications producers go through the gateway.
 *
 * MailboxHealthRecorder used a raw `Notification::send($admins, …)`: an admin could
 * not switch it off, it honoured no open-hours window or cooldown, and it wrote
 * nothing to the ledger.
 *
 * It is also the clearest example of a PERSISTENT condition. A failing mailbox is not
 * an event — the poller re-runs every few minutes while it is down. It carried its own
 * private idempotency (a `failure_notified_at` marker column): a FIFTH mechanism,
 * invisible to everything else. The marker is now stamped BEFORE the send and handed
 * to the gateway as the dedup key, so both guards agree on one identity.
 *
 * ── WHICH GUARD IS DOING THE WORK — read this before trusting a green ───────────
 * TWO guards now protect this alert, and they are NOT the same thing:
 *
 *   1. failure_notified_at (the caller's marker) — short-circuits maybeNotify() so the
 *      gateway is never even reached on a repeat poll. This is the FIRST guard.
 *   2. The gateway's dedup key (the episode marker) — defence in depth, and the thing
 *      that makes the episode identity visible to the ledger and to everything else.
 *
 * The behaviour tests below (alert once per episode) pass on guard 1 ALONE. I verified
 * that by breaking the gateway key and watching them stay green — so they do NOT prove
 * the gateway dedups, and it would be dishonest to claim they do. They prove the
 * user-facing guarantee, which is worth having, and nothing more.
 *
 * test_the_gateway_itself_dedups_the_episode() below reaches PAST the marker and
 * exercises guard 2 directly. That one goes red if the gateway key is broken.
 *
 * (Cooldown is 0 throughout so it cannot mask anything either. That masking has already
 * produced five green tests that proved nothing.)
 */
final class CommsProducersThroughGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private CommunicationMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // ⚠️ SEED THE CATALOGUE — do NOT rely on the registering migration.
        //
        // `schema:dump` writes the schema and the `migrations` table, but NONE of the
        // data a migration inserted. So on a fresh test database the registering
        // migration is marked ALREADY-RUN, never re-executes, and its catalogue row
        // simply does not exist — the gateway then finds no event type and sends
        // nothing, silently. That is AT-162 (reference data that does not travel) in
        // test form, and it is exactly what this seeder is for.
        $this->seed(\Database\Seeders\NotificationEventTypeSeeder::class);

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach']);

        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
        ]);

        // Isolate the dedup key: the cooldown must not hold these tests up.
        UserDashboardSetting::updateOrCreate(
            ['user_id' => $this->admin->id],
            array_merge(UserDashboardSetting::defaults(), ['min_minutes_between_same' => 0])
        );

        $this->mailbox = CommunicationMailbox::create([
            'agency_id'            => $this->agency->id,
            'user_id'              => $this->admin->id,
            'email_address'        => 'info@hfcoastal.co.za',
            'imap_host'            => 'imap.hfcoastal.co.za',
            'username'             => 'info@hfcoastal.co.za',
            'consecutive_failures' => 0,
        ]);
    }

    /** Drive N consecutive failed polls (threshold defaults to 3). */
    private function failPolls(int $times): void
    {
        $recorder = app(MailboxHealthRecorder::class);

        for ($i = 0; $i < $times; $i++) {
            $recorder->recordFailure($this->mailbox->fresh(), 'IMAP login rejected');
        }
    }

    // ── the producer is now a citizen ───────────────────────────────────────

    public function test_a_failing_mailbox_alerts_the_admin_through_the_gateway(): void
    {
        $this->failPolls(3);

        Notification::assertSentTo($this->admin, MailboxPollFailureNotification::class);

        $this->assertGreaterThan(
            0,
            NotificationDispatchLog::where('user_id', $this->admin->id)->count(),
            'the dispatch is now RECORDED — the raw send wrote nothing anywhere'
        );
    }

    /** The whole point: an admin can now turn this off. Before, they could not. */
    public function test_an_admin_can_switch_the_mailbox_alert_off(): void
    {
        $type = NotificationEventType::where('key', 'comms.mailbox_poll_failure')->firstOrFail();

        UserNotificationPreference::create([
            'user_id'                    => $this->admin->id,
            'notification_event_type_id' => $type->id,
            'enabled'                    => false,
        ]);

        $this->failPolls(3);

        Notification::assertNothingSentTo($this->admin);
    }

    // ── THE PERSISTENT CONDITION — the fact is the EPISODE ──────────────────

    /**
     * The poller keeps running while the mailbox is down. It must alert ONCE for the
     * episode — not once per poll. This is the same shape as the 1.9M storm, and the
     * reason the dedup key here is the episode marker rather than the clock.
     */
    public function test_a_mailbox_that_keeps_failing_alerts_once_not_once_per_poll(): void
    {
        $this->failPolls(3);   // crosses the threshold → alert
        $before = NotificationDispatchLog::where('user_id', $this->admin->id)->count();
        $this->assertGreaterThan(0, $before);

        // It stays down. The poller keeps hammering, minutes apart.
        for ($i = 0; $i < 10; $i++) {
            $this->travel(5)->minutes();
            $this->failPolls(1);
        }

        $this->assertSame(
            $before,
            NotificationDispatchLog::where('user_id', $this->admin->id)->count(),
            'a mailbox that is STILL down is the same fact — ten more polls must not mean '
            . 'ten more alerts'
        );
    }

    /** Recovery ends the episode; a NEW failure episode is a new fact and alerts again. */
    public function test_a_new_episode_after_recovery_alerts_again(): void
    {
        $this->failPolls(3);
        $first = NotificationDispatchLog::where('user_id', $this->admin->id)->count();

        // The mailbox recovers — this clears failure_notified_at, ending the episode.
        app(MailboxHealthRecorder::class)->recordSuccess($this->mailbox->fresh());

        // …and later fails again. That is a genuinely NEW fact.
        $this->travel(2)->hours();
        $this->failPolls(3);

        $this->assertGreaterThan(
            $first,
            NotificationDispatchLog::where('user_id', $this->admin->id)->count(),
            'dedup must not silence a genuinely new outage — it is a different episode'
        );
    }

    /** Below the threshold, nothing is sent at all. */
    public function test_a_single_blip_does_not_alert(): void
    {
        $this->failPolls(1);

        Notification::assertNothingSentTo($this->admin);
        $this->assertSame(0, NotificationDispatchLog::where('user_id', $this->admin->id)->count());
    }

    /**
     * GUARD 2, TESTED DIRECTLY. The tests above pass on the caller's marker alone, so
     * they say nothing about the gateway. This reaches past the marker and hands the
     * gateway the same episode twice — the second must be deduped.
     *
     * This is the guard that survives if someone ever removes or breaks the marker,
     * and it is the one that makes the episode visible in the ledger.
     */
    public function test_the_gateway_itself_dedups_the_episode(): void
    {
        $gateway = app(\App\Services\CommandCenter\NotificationDispatcher::class);
        $episode = now();

        $send = fn () => $gateway->send(
            $this->admin,
            'comms.mailbox_poll_failure',
            $this->mailbox,
            new MailboxPollFailureNotification($this->mailbox, 'IMAP login rejected', 3),
            ['threshold_hit_at' => $episode],
        );

        $this->assertTrue($send(), 'first alert for the episode goes out');

        $this->travel(30)->minutes();

        $this->assertFalse($send(), 'the SAME episode must not alert twice, even without the marker');

        $this->assertSame(
            1,
            NotificationDispatchLog::where('user_id', $this->admin->id)->count(),
            'one episode, one ledger row'
        );
    }

    /** Capability caps preference: this notification is database-only (no toMail()). */
    public function test_the_alert_is_never_emailed_because_the_class_cannot_render_mail(): void
    {
        $this->failPolls(3);

        Notification::assertSentTo($this->admin, MailboxPollFailureNotification::class,
            function ($n, array $channels) {
                $this->assertNotContains('mail', $channels,
                    'MailboxPollFailureNotification has no toMail() — the gateway must never '
                    . 'hand it the mail channel, whatever the user ticks (AT-235 C11)');
                return true;
            });
    }
}
