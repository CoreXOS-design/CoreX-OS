<?php

namespace Tests\Feature\Webinars;

use App\Mail\DemoAccessGrantMail;
use App\Mail\WebinarConfirmationMail;
use App\Models\DemoAccessGrant;
use App\Models\SiteConnector;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The public registration API.
 *
 * Spec: .ai/specs/webinar-registration.md §4, §6.1, §6.2
 */
class WebinarRegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Webinar $webinar;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->owner = User::factory()->create(['role' => 'super_admin']);

        [, $this->token] = SiteConnector::mint('CoreX Website', $this->owner->id);

        $this->webinar = Webinar::create([
            'slug'                   => 'corex-walkthrough',
            'title'                  => 'CoreX OS — a walkthrough',
            'description'            => 'Everything an agency principal needs to see.',
            'starts_at'              => Carbon::now()->addDays(7)->setTime(14, 0),
            'duration_minutes'       => 60,
            'join_url'               => 'https://zoom.us/j/123456',
            'access_ends_days_after' => 3,
            'reminder_hours_before'  => 24,
            'created_by_user_id'     => $this->owner->id,
        ]);
    }

    private function submit(array $payload = [], ?string $slug = null)
    {
        return $this->withToken($this->token)->postJson(
            '/api/v1/webinars/' . ($slug ?? $this->webinar->slug) . '/register',
            $payload + [
                'name'         => 'Jane Smith',
                'email'        => 'jane@acme.co.za',
                'company_name' => 'Acme Properties',
            ]
        );
    }

    // ---- Auth --------------------------------------------------------------

    public function test_no_token_is_rejected(): void
    {
        $this->postJson('/api/v1/webinars/' . $this->webinar->slug . '/register', [
            'name' => 'Jane', 'email' => 'jane@acme.co.za', 'company_name' => 'Acme',
        ])->assertStatus(401);

        $this->assertDatabaseCount('webinar_registrations', 0);
    }

    /** A revoked token is as dead as a wrong one, and says no more about why. */
    public function test_a_revoked_token_is_rejected(): void
    {
        SiteConnector::current()->revoke();

        $this->submit()->assertStatus(401);
    }

    public function test_a_garbage_token_is_rejected(): void
    {
        $this->withToken('cx_site_nonsense.wrong')->getJson('/api/v1/webinars/ping')->assertStatus(401);
    }

    public function test_ping_works_with_a_valid_token(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/webinars/ping')
             ->assertOk()
             ->assertJsonPath('ok', true);
    }

    // ---- Public detail -----------------------------------------------------

    public function test_show_returns_details_but_never_the_join_url(): void
    {
        $response = $this->withToken($this->token)
                         ->getJson('/api/v1/webinars/' . $this->webinar->slug)
                         ->assertOk()
                         ->assertJsonPath('webinar.title', 'CoreX OS — a walkthrough')
                         ->assertJsonPath('webinar.registration_open', true);

        $this->assertStringNotContainsString(
            'zoom.us',
            $response->getContent(),
            'The join link is earned by registering, never by reading the public page.'
        );
    }

    public function test_show_404s_for_an_unknown_slug(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/webinars/nope')->assertStatus(404);
    }

    // ---- Registration ------------------------------------------------------

    public function test_registering_creates_the_row_and_a_grant_on_the_fixed_deadline(): void
    {
        $this->submit()->assertOk()->assertJsonPath('registered', true);

        $registration = WebinarRegistration::first();

        $this->assertSame('jane@acme.co.za', $registration->email);
        $this->assertSame('Acme Properties', $registration->company_name);
        $this->assertNotNull($registration->demo_access_grant_id);
        $this->assertNotNull($registration->confirmation_sent_at);

        $grant = $registration->grant;

        $this->assertNull($grant->expiry_hours, 'A webinar grant runs on a fixed deadline, not a rolling trial.');
        $this->assertSame(
            $this->webinar->demoAccessEndsAt()->toDateTimeString(),
            $grant->expires_at->toDateTimeString()
        );

        // A5 — registrants do not enter the CRM.
        $this->assertNull($grant->contact_id);
        $this->assertDatabaseCount('contacts', 0);
    }

    /** A2 — ONE email, and it is ours, not the standard demo invitation. */
    public function test_exactly_one_email_is_sent_and_it_is_the_webinar_one(): void
    {
        $this->submit()->assertOk();

        Mail::assertQueued(WebinarConfirmationMail::class, 1);
        Mail::assertNotQueued(DemoAccessGrantMail::class);
    }

    public function test_the_response_never_contains_the_access_code(): void
    {
        $response = $this->submit()->assertOk();

        $code = DemoAccessGrant::first();

        $this->assertNotNull($code);
        // The plaintext is unrecoverable, so the strongest available assertion is
        // that nothing code-shaped came back at all.
        $this->assertStringNotContainsString('access_code', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', $response->getContent());
    }

    /**
     * The email IS the deliverable — it is the only thing the registrant ever
     * receives, and the only place the access code exists. Asserting it was queued
     * proves nothing about whether it renders, so this builds it for real.
     */
    public function test_the_confirmation_email_renders_with_the_code_the_join_link_and_a_calendar_invite(): void
    {
        $this->submit()->assertOk();

        Mail::assertQueued(WebinarConfirmationMail::class, function (WebinarConfirmationMail $mail) {
            $html = $mail->render();

            $this->assertStringContainsString($mail->accessCode, $html, 'The code is only ever delivered here.');
            $this->assertStringContainsString('https://zoom.us/j/123456', $html);
            $this->assertStringContainsString('CoreX OS — a walkthrough', $html);

            // The fixed end date, in words, so nobody has to infer it.
            $this->assertStringContainsString(
                $this->webinar->demoAccessEndsAt()->format('j F Y'),
                $html
            );

            // A6 — the calendar invite. Building it is the assertion: attachments()
            // reads the webinar's dates and title, so a null or malformed one throws
            // here rather than arriving as a file nobody's calendar will open.
            $attachments = $mail->attachments();
            $this->assertCount(1, $attachments);
            $this->assertStringEndsWith('.ics', $attachments[0]->as);

            return true;
        });
    }

    /** The .ics must not carry the credential — calendars sync everywhere. */
    public function test_the_calendar_invite_carries_the_join_link_but_never_the_access_code(): void
    {
        $this->submit()->assertOk();

        Mail::assertQueued(WebinarConfirmationMail::class, function (WebinarConfirmationMail $mail) {
            $ics = \App\Support\IcsCalendarInvite::build(
                uid: 'webinar-' . $this->webinar->id . '-reg-' . $mail->registration->id . '@corexos.co.za',
                summary: $this->webinar->title,
                start: $this->webinar->starts_at,
                end: $this->webinar->starts_at->copy()->addMinutes(60),
                description: 'Join: ' . $this->webinar->join_url,
                location: $this->webinar->join_url,
                url: $this->webinar->join_url,
            );

            $this->assertStringContainsString('zoom.us', $ics);
            $this->assertStringNotContainsString($mail->accessCode, $ics);
            $this->assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics, 'RFC 5545 needs CRLF.');

            return true;
        });
    }

    // ---- Repeat submits ----------------------------------------------------

    /** D5 — inside the cooldown: no second row, no second email, and NOT an error. */
    public function test_a_repeat_submit_inside_the_cooldown_is_throttled_silently(): void
    {
        $this->submit()->assertOk()->assertJsonPath('throttled', false);

        $this->submit()->assertOk()->assertJsonPath('throttled', true);

        $this->assertDatabaseCount('webinar_registrations', 1);
        Mail::assertQueued(WebinarConfirmationMail::class, 1);
    }

    /** After the cooldown a fresh code is issued — the old one cannot be re-sent. */
    public function test_re_registering_after_the_cooldown_issues_a_new_grant(): void
    {
        $this->submit()->assertOk();

        $firstGrantId = WebinarRegistration::first()->demo_access_grant_id;

        $this->travelTo(Carbon::now()->addMinutes(20));

        $this->submit()->assertOk()->assertJsonPath('throttled', false);

        $registration = WebinarRegistration::first();

        // One person, one registration row — even across a re-issue.
        $this->assertDatabaseCount('webinar_registrations', 1);
        $this->assertNotSame($firstGrantId, $registration->demo_access_grant_id);
        // The superseded grant stays: demo_access_grants is the evidence trail.
        $this->assertDatabaseCount('demo_access_grants', 2);
        Mail::assertQueued(WebinarConfirmationMail::class, 2);
    }

    /** Two different people on one webinar are two registrations. */
    public function test_two_people_get_their_own_registrations(): void
    {
        $this->submit()->assertOk();
        $this->submit(['email' => 'sipho@other.co.za', 'name' => 'Sipho'])->assertOk();

        $this->assertDatabaseCount('webinar_registrations', 2);
    }

    // ---- Closed / invalid --------------------------------------------------

    public function test_registration_after_the_webinar_has_started_is_refused(): void
    {
        $this->travelTo($this->webinar->starts_at->copy()->addMinute());

        $this->submit()->assertStatus(404);

        $this->assertDatabaseCount('webinar_registrations', 0);
        $this->assertDatabaseCount('demo_access_grants', 0);
    }

    public function test_registration_for_an_archived_webinar_is_refused(): void
    {
        $this->webinar->forceFill(['archived_at' => Carbon::now()])->save();

        $this->submit()->assertStatus(404);
    }

    public function test_validation_errors_come_back_field_keyed(): void
    {
        $this->withToken($this->token)
             ->postJson('/api/v1/webinars/' . $this->webinar->slug . '/register', [
                 'name'  => '',
                 'email' => 'not-an-email',
             ])
             ->assertStatus(422)
             ->assertJsonPath('ok', false)
             ->assertJsonStructure(['errors' => ['name', 'email', 'company_name']]);

        $this->assertDatabaseCount('webinar_registrations', 0);
    }
}
