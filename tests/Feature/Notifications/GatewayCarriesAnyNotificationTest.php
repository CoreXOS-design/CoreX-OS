<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\CommandCenter\NotificationEventType;
use App\Models\CommandCenter\UserNotificationPreference;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * AT-235 (S0) — the gateway can carry ANY notification class.
 *
 * This is the change that makes the whole consolidation possible.
 *
 * `fire()` could only ever send a PillarEventNotification — a generic title/body
 * alert it built itself. Every one of the 22 bypasses has its OWN notification class
 * with its own mail template, so a producer that switched to `fire()` would have LOST
 * its email and sent a generic stub. That is why 31 bypasses exist: the gateway
 * literally could not carry them. The door was locked.
 *
 * `send()` unlocks it: the gateway keeps WHO / WHETHER / WHERE (preference, open
 * hours, cooldown, ledger); the caller keeps WHAT (its own class, its own template).
 *
 * The invariant every test here defends: **resolved channels are a CEILING, never a
 * floor.** Nothing — not a producer, not an agency, not a class — may widen delivery
 * past what the user asked for.
 */
final class GatewayCarriesAnyNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private NotificationEventType $type;
    private NotificationDispatcher $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        NotificationFacade::fake();

        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);
        $this->user = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
        ]);

        $this->type = NotificationEventType::create([
            'key' => 'test.carried', 'pillar' => 'deal', 'group_label' => 'Finance',
            'label' => 'Carried notification', 'description' => '',
            'default_enabled' => true, 'threshold_unit' => 'none',
            'supports_in_app' => true, 'supports_email' => true, 'supports_push' => false,
            'is_adapter' => false, 'sort_order' => 900,
        ]);

        $this->gateway = app(NotificationDispatcher::class);
    }

    private function send(Notification $n): bool
    {
        return $this->gateway->send($this->user, 'test.carried', $this->user, $n, [
            'threshold_hit_at' => now(),
        ]);
    }

    // ── the unlock ──────────────────────────────────────────────────────────

    public function test_the_gateway_delivers_the_callers_own_notification_class(): void
    {
        $this->assertTrue($this->send(new CarriedTestNotification()));

        NotificationFacade::assertSentTo($this->user, CarriedTestNotification::class,
            fn ($n, array $channels) => in_array('database', $channels, true));
    }

    public function test_a_carried_notification_is_written_to_the_ledger(): void
    {
        $this->send(new CarriedTestNotification());

        $this->assertGreaterThan(
            0,
            NotificationDispatchLog::where('user_id', $this->user->id)->count(),
            'a gateway send must be recorded — the bypasses recorded nothing, which is why '
            . 'nobody could prove what had been sent'
        );
    }

    /** The whole point of AT-245's conversion: the admin can now turn it OFF. */
    public function test_a_user_can_switch_a_carried_notification_off(): void
    {
        UserNotificationPreference::create([
            'user_id'                    => $this->user->id,
            'notification_event_type_id' => $this->type->id,
            'enabled'                    => false,
        ]);

        $this->assertFalse($this->send(new CarriedTestNotification()));

        NotificationFacade::assertNothingSentTo($this->user);
        $this->assertSame(0, NotificationDispatchLog::where('user_id', $this->user->id)->count());
    }

    // ── the ceiling: capability + preference ────────────────────────────────

    /**
     * AT-235 C11 — the catalogue's supports_* flags existed and NOTHING READ THEM.
     * That became load-bearing the moment the gateway started carrying arbitrary
     * classes: ProformaCreatedNotification is database-only and has no toMail(), so a
     * user ticking "email" would have made the gateway resolve `mail` and blow up
     * inside the mailer.
     */
    public function test_a_channel_the_notification_cannot_render_is_never_used(): void
    {
        // The catalogue says email is supported and the user wants it…
        $this->assertTrue((bool) $this->type->supports_email);

        // …but this notification has no toMail(). It must simply not be emailed.
        $this->send(new DatabaseOnlyNotification());

        NotificationFacade::assertSentTo($this->user, DatabaseOnlyNotification::class,
            function ($n, array $channels) {
                $this->assertNotContains('mail', $channels,
                    'a class with no toMail() must never be handed the mail channel');
                $this->assertContains('database', $channels);
                return true;
            });
    }

    /** The ledger must record what was DELIVERED, not what was merely wanted. */
    public function test_the_ledger_does_not_claim_a_channel_that_was_not_delivered(): void
    {
        $this->send(new DatabaseOnlyNotification());

        $channels = NotificationDispatchLog::where('user_id', $this->user->id)
            ->pluck('channel')->all();

        $this->assertSame(['in_app'], $channels,
            'logging an email that was never sent would make the idempotency ledger lie — '
            . 'exactly the class of bug that produced the 1.9M storm');
    }

    /** An event type that does not support email must not email, whatever the user ticks. */
    public function test_the_catalogue_capability_caps_the_users_preference(): void
    {
        $this->type->update(['supports_email' => false]);

        $this->send(new CarriedTestNotification()); // this one CAN render mail

        NotificationFacade::assertSentTo($this->user, CarriedTestNotification::class,
            function ($n, array $channels) {
                $this->assertNotContains('mail', $channels,
                    'the event type does not support email — preference cannot widen past capability');
                return true;
            });
    }

    /**
     * ★ THE CONSENT CEILING — the single most important assertion in this file.
     *
     * CarriedTestNotification::via() asks for ['database','mail']. The user has NOT
     * enabled email (channel_email defaults to false). If the gateway honoured via()
     * — as every bypass does — this user would be emailed something they never asked
     * for, and would have no way to stop it.
     *
     * The gateway's resolved channels are a CEILING. A notification class cannot
     * widen them. Neither can a producer, nor an agency setting.
     *
     * This is the exact regression R2 nearly introduced: the fix that made the
     * agency's channel config work also bypassed via(), which is where the user's
     * email master switch was being checked — handing agencies the power to override
     * user consent. It had to be re-applied deliberately. Every stage of this
     * consolidation re-checks this.
     */
    public function test_a_notifications_via_cannot_widen_past_the_users_choice(): void
    {
        // The user has not enabled email; the class's via() demands it anyway.
        $this->send(new CarriedTestNotification());

        NotificationFacade::assertSentTo($this->user, CarriedTestNotification::class,
            function ($n, array $channels) {
                $this->assertContains('database', $channels, 'in-app was wanted');
                $this->assertNotContains('mail', $channels,
                    'THE CONSENT CEILING: the class asked for mail via() but the user never '
                    . 'enabled email — a notification class must NEVER be able to widen delivery '
                    . 'past what the user chose');
                return true;
            });

        $this->assertSame(
            ['in_app'],
            NotificationDispatchLog::where('user_id', $this->user->id)->pluck('channel')->all(),
            'and the ledger must agree — it records what was delivered'
        );
    }

    // ── no regression: fire() still works for its 8 callers ─────────────────

    public function test_fire_still_sends_the_generic_pillar_notification(): void
    {
        $this->assertTrue($this->gateway->fire($this->user, 'test.carried', $this->user, [
            'title'            => 'Generic alert',
            'body'             => 'Still works.',
            'threshold_hit_at' => now(),
        ]));

        NotificationFacade::assertSentTo($this->user, \App\Notifications\PillarEventNotification::class);
    }

    /** The dedup key is required on send() too — the storm cannot come back via the new door. */
    public function test_send_also_refuses_a_missing_dedup_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gateway->send($this->user, 'test.carried', $this->user, new CarriedTestNotification(), []);
    }
}

/** A caller's own class — in-app + mail, like most real producers. */
class CarriedTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        // Deliberately WIDE: if the gateway honoured via() instead of its own resolved
        // channels, this would email a user who asked for in-app only. It must not.
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'Carried', 'body' => 'From the caller’s own class'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())->subject('Carried')->line('From the caller’s own class');
    }
}

/** Like ProformaCreatedNotification: database-only, no toMail(). */
class DatabaseOnlyNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => 'DB only', 'body' => 'No mail template exists on this class'];
    }
}
