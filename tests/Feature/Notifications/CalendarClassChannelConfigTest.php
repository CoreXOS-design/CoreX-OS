<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\User;
use App\Notifications\EventDueReminderNotification;
use App\Services\CommandCenter\Calendar\CalendarNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AT-235 (R2) — the agency's per-class channel config was INERT.
 *
 * `calendar_event_class_settings` lets an agency say, per event class and per RAG
 * colour, WHO gets told and on WHICH channels (`green/amber/red_notifications`:
 * role → channels). `CalendarNotificationDispatcher` resolved that into
 * `$viaChannels`, checked it was non-empty… and then called `$user->notify()`
 * WITHOUT it. Delivery fell through to `EventDueReminderNotification::via()`, which
 * returns `database` + `mail`-if-notify_email regardless of what the agency chose.
 *
 * So a class configured "in-app only" still sent email, and one configured "email
 * only" still wrote an in-app row. The admin set the channel and the code ignored
 * it — a settings screen that lies.
 *
 * And because forcing the channel list bypasses `via()` — which is where the user's
 * notify_email master switch was being checked — the fix has to re-apply that veto
 * deliberately, or the agency's config could un-mute a user who turned email off.
 */
final class CalendarClassChannelConfigTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach']);
        $this->agent  = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
            'is_active' => true,
        ]);
    }

    /** Configure the class so the AGENT role is notified on red, via $channels. */
    private function classConfiguredWith(array $channels, bool $active = true): void
    {
        CalendarEventClassSetting::create(array_merge($this->classDefaults(), [
            'is_active'         => $active,
            'red_notifications' => ['agent' => $channels],
        ]));
    }

    /** The RAG columns are NOT NULL with no default — supply the whole row. */
    private function classDefaults(): array
    {
        return [
            'agency_id'           => $this->agency->id,
            'event_class'         => 'viewing',
            'label'               => 'Viewing',
            'is_active'           => true,
            'green_days'          => 7,
            'amber_days'          => 3,
            'red_days'            => 1,
            'green_visibility'    => 'all',
            'amber_visibility'    => 'all',
            'red_visibility'      => 'all',
            'green_notifications' => [],
            'amber_notifications' => [],
            'red_notifications'   => [],
        ];
    }

    private function event(): CalendarEvent
    {
        return CalendarEvent::create([
            'agency_id'  => $this->agency->id,
            'branch_id'  => $this->branch->id,
            'user_id'    => $this->agent->id,
            'category'   => 'viewing',
            'event_type' => 'viewing',
            'title'      => 'Viewing — 14 Marine Drive, Shelly Beach',
            'event_date' => now()->addDay()->toDateString(),
            'start_at'   => now()->addDay(),
            'end_at'     => now()->addDay()->addHour(),
        ]);
    }

    private function transitionToRed(): void
    {
        app(CalendarNotificationDispatcher::class)
            ->onColourTransition($this->event(), 'amber', 'red');
    }

    // ── the bug ─────────────────────────────────────────────────────────────

    public function test_a_class_set_to_in_app_only_does_not_send_email(): void
    {
        $this->classConfiguredWith(['in_app']);

        $this->transitionToRed();

        Notification::assertSentTo($this->agent, EventDueReminderNotification::class,
            function ($notification, array $channels) {
                $this->assertContains('database', $channels, 'in-app was configured — it must be sent');
                $this->assertNotContains('mail', $channels,
                    'THE BUG: the agency configured this class as IN-APP ONLY, and it emailed anyway');
                return true;
            });
    }

    public function test_a_class_set_to_email_only_does_not_write_an_in_app_row(): void
    {
        $this->classConfiguredWith(['email']);

        $this->transitionToRed();

        Notification::assertSentTo($this->agent, EventDueReminderNotification::class,
            function ($notification, array $channels) {
                $this->assertContains('mail', $channels, 'email was configured — it must be sent');
                $this->assertNotContains('database', $channels,
                    'THE BUG: the agency configured this class as EMAIL ONLY, and it wrote an in-app row anyway');
                return true;
            });
    }

    public function test_a_class_set_to_both_sends_both(): void
    {
        $this->classConfiguredWith(['in_app', 'email']);

        $this->transitionToRed();

        Notification::assertSentTo($this->agent, EventDueReminderNotification::class,
            function ($notification, array $channels) {
                $this->assertContains('database', $channels);
                $this->assertContains('mail', $channels);
                return true;
            });
    }

    // ── the veto: the agency cannot un-mute a user ──────────────────────────

    /**
     * The agency's class config decides which channels are ELIGIBLE. The user's
     * master switch still VETOES. An agency turning email on for a class must not
     * override a user who turned email off — that veto used to happen by accident
     * inside via(), which the fix bypasses, so it now has to be deliberate.
     */
    public function test_the_agency_config_cannot_email_a_user_who_muted_email(): void
    {
        $this->classConfiguredWith(['in_app', 'email']);

        UserDashboardSetting::updateOrCreate(
            ['user_id' => $this->agent->id],
            array_merge(UserDashboardSetting::defaults(), [
                'notify_email'  => false, // the user said no email
                'notify_in_app' => true,
            ])
        );

        $this->transitionToRed();

        Notification::assertSentTo($this->agent, EventDueReminderNotification::class,
            function ($notification, array $channels) {
                $this->assertContains('database', $channels, 'in-app is still wanted');
                $this->assertNotContains('mail', $channels,
                    'the agency must not be able to un-mute a user who turned email off');
                return true;
            });
    }

    /** If the user has muted every channel the class asked for, nothing is sent. */
    public function test_nothing_is_sent_when_the_user_muted_every_configured_channel(): void
    {
        $this->classConfiguredWith(['email']);

        UserDashboardSetting::updateOrCreate(
            ['user_id' => $this->agent->id],
            array_merge(UserDashboardSetting::defaults(), ['notify_email' => false])
        );

        $this->transitionToRed();

        Notification::assertNothingSentTo($this->agent);
    }

    /** An inactive class sends nothing at all. */
    public function test_an_inactive_class_sends_nothing(): void
    {
        $this->classConfiguredWith(['in_app', 'email'], active: false);

        $this->transitionToRed();

        Notification::assertNothingSentTo($this->agent);
    }
}
