<?php

namespace Tests\Feature\Webinars;

use App\Mail\WebinarReminderMail;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The pre-webinar reminder.
 *
 * Spec: .ai/specs/webinar-registration.md §6.4
 */
class WebinarReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->create(['role' => 'super_admin']);
    }

    private function webinar(array $overrides = []): Webinar
    {
        return Webinar::create(array_merge([
            'slug'                   => 'w-' . uniqid(),
            'title'                  => 'CoreX OS — a walkthrough',
            'starts_at'              => Carbon::now()->addHours(20),
            'duration_minutes'       => 60,
            'access_ends_days_after' => 3,
            'reminder_hours_before'  => 24,
            'created_by_user_id'     => $this->owner->id,
        ], $overrides));
    }

    private function registrant(Webinar $webinar, array $overrides = []): WebinarRegistration
    {
        return WebinarRegistration::create(array_merge([
            'webinar_id'   => $webinar->id,
            'name'         => 'Jane Smith',
            'email'        => 'jane' . uniqid() . '@acme.co.za',
            'company_name' => 'Acme Properties',
        ], $overrides));
    }

    public function test_a_registrant_inside_the_lead_time_is_reminded(): void
    {
        $registration = $this->registrant($this->webinar());

        $this->artisan('webinars:send-reminders')->assertExitCode(0);

        Mail::assertQueued(WebinarReminderMail::class, 1);
        $this->assertNotNull($registration->fresh()->reminder_sent_at);
    }

    /**
     * Renders the reminder for real.
     *
     * assertQueued proves a Mailable was constructed, NOT that it renders — a Blade
     * template can queue perfectly and then throw when the worker builds it, which
     * fails silently in a background job. This asserts the body, and that the code is
     * absent from it (D6: by reminder time the plaintext no longer exists anywhere).
     */
    public function test_the_reminder_renders_with_the_join_link_and_no_access_code(): void
    {
        $webinar = $this->webinar(['join_url' => 'https://zoom.us/j/999']);
        $this->registrant($webinar);

        $this->artisan('webinars:send-reminders')->assertExitCode(0);

        Mail::assertQueued(WebinarReminderMail::class, function (WebinarReminderMail $mail) use ($webinar) {
            $html = $mail->render();

            $this->assertStringContainsString('https://zoom.us/j/999', $html);
            $this->assertStringContainsString($webinar->title, $html);
            $this->assertStringContainsString($webinar->demoAccessEndsAt()->format('j F Y'), $html);

            // Nothing code-shaped: the reminder must never promise a credential.
            $this->assertDoesNotMatchRegularExpression(
                '/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/',
                $html
            );

            return true;
        });
    }

    /** Outside the lead time nothing goes out — 20 hours' notice, 48h lead time. */
    public function test_a_registrant_outside_the_lead_time_is_left_alone(): void
    {
        $webinar = $this->webinar([
            'starts_at'             => Carbon::now()->addDays(10),
            'reminder_hours_before' => 24,
        ]);

        $registration = $this->registrant($webinar);

        $this->artisan('webinars:send-reminders')->assertExitCode(0);

        Mail::assertNothingQueued();
        $this->assertNull($registration->fresh()->reminder_sent_at);
    }

    /** The stamp is the idempotency — a second run in the same window sends nothing. */
    public function test_the_reminder_is_sent_exactly_once(): void
    {
        $this->registrant($this->webinar());

        $this->artisan('webinars:send-reminders');
        $this->artisan('webinars:send-reminders');
        $this->artisan('webinars:send-reminders');

        Mail::assertQueued(WebinarReminderMail::class, 1);
    }

    /**
     * Switching the feature on must not blast everyone who ever registered for a
     * webinar that has already happened.
     */
    public function test_past_webinars_are_never_reminded_about(): void
    {
        $webinar = $this->webinar(['starts_at' => Carbon::now()->subDays(2)]);
        $this->registrant($webinar);

        $this->artisan('webinars:send-reminders')->assertExitCode(0);

        Mail::assertNothingQueued();
    }

    public function test_archived_webinars_are_skipped(): void
    {
        $webinar = $this->webinar(['archived_at' => Carbon::now()]);
        $this->registrant($webinar);

        $this->artisan('webinars:send-reminders')->assertExitCode(0);

        Mail::assertNothingQueued();
    }

    /** Every due registrant on the webinar gets one. */
    public function test_the_whole_cohort_is_reminded(): void
    {
        $webinar = $this->webinar();

        $this->registrant($webinar);
        $this->registrant($webinar);
        $this->registrant($webinar);

        $this->artisan('webinars:send-reminders')->assertExitCode(0);

        Mail::assertQueued(WebinarReminderMail::class, 3);
    }

    /** Someone already reminded is not reminded again when a new person joins. */
    public function test_an_already_reminded_registrant_is_not_re_reminded(): void
    {
        $webinar = $this->webinar();

        $this->registrant($webinar, ['reminder_sent_at' => Carbon::now()->subHour()]);
        $this->registrant($webinar);

        $this->artisan('webinars:send-reminders')->assertExitCode(0);

        Mail::assertQueued(WebinarReminderMail::class, 1);
    }
}
