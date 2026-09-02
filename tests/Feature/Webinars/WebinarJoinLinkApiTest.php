<?php

namespace Tests\Feature\Webinars;

use App\Events\Webinars\WebinarJoinLinkSent;
use App\Mail\WebinarJoinLinkMail;
use App\Models\SiteConnector;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * POST /api/v1/webinars/{slug}/join-link — save the joining link and tell the cohort.
 *
 * Spec: .ai/specs/webinar-registration.md §4.4
 *
 * This button puts an email in every registrant's inbox and cannot be undone, so the
 * tests that matter most are the ones asserting what does NOT happen: no mail on a 422,
 * no mail on an archived webinar, and nothing half-saved when the send throws.
 *
 * ══ WHY Mail::fake() IS NOT IN setUp() ══
 *
 * phpunit.xml pins QUEUE_CONNECTION=sync, and Mail::fake() intercepts before anything
 * is queued — so under the default test setup NOTHING ever reaches the `jobs` table and
 * any assertion that `jobs` is empty passes for free, whether or not the rollback it
 * claims to prove actually happened. The atomicity tests below therefore opt INTO the
 * real database queue, and every other test opts into the fake explicitly.
 */
class WebinarJoinLinkApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webinar $webinar;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.corex_website_url' => 'https://corexweb.co.za']);

        $this->owner = User::factory()->create(['role' => 'super_admin']);

        [, $this->token] = SiteConnector::mint('CoreX Website', $this->owner->id);

        // The situation this endpoint exists for: a webinar created before its Zoom
        // link existed, so join_url is NULL and everyone who registered got a
        // confirmation with no way in.
        $this->webinar = Webinar::create([
            'slug'                   => 'corex-walkthrough',
            'title'                  => 'CoreX OS — a walkthrough',
            'description'            => 'Everything an agency principal needs to see.',
            'starts_at'              => Carbon::now()->addDays(7)->setTime(14, 0),
            'duration_minutes'       => 60,
            'join_url'               => null,
            'access_ends_days_after' => 3,
            'reminder_hours_before'  => 24,
            'created_by_user_id'     => $this->owner->id,
        ]);
    }

    private function api()
    {
        return $this->withToken($this->token);
    }

    private function registrant(string $name, string $email): WebinarRegistration
    {
        return WebinarRegistration::create([
            'webinar_id'   => $this->webinar->id,
            'name'         => $name,
            'email'        => $email,
            'company_name' => 'Acme Properties',
            'phone'        => '+27 82 000 0000',
            'source'       => 'website',
        ]);
    }

    private function url(?string $slug = null): string
    {
        return '/api/v1/webinars/' . ($slug ?? $this->webinar->slug) . '/join-link';
    }

    /** Opt into the REAL database queue, so `jobs` rows actually get written. */
    private function useRealQueue(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->delete();
    }

    // ---- Auth --------------------------------------------------------------

    public function test_it_refuses_a_request_with_no_token(): void
    {
        Mail::fake();

        $this->postJson($this->url(), ['join_url' => 'https://zoom.us/j/1'])
            ->assertStatus(401);
    }

    // ---- The happy path ----------------------------------------------------

    public function test_it_saves_the_link_and_queues_one_mail_per_registration(): void
    {
        Mail::fake();

        $this->registrant('Jane Smith', 'jane@acme.co.za');
        $this->registrant('Thabo Dlamini', 'thabo@ridge.co.za');
        $this->registrant('Sipho Ncube', 'sipho@coastal.co.za');

        $this->api()
            ->postJson($this->url(), ['join_url' => 'https://zoom.us/j/123456789'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('join_url', 'https://zoom.us/j/123456789')
            ->assertJsonPath('notified', 3);

        $this->assertSame('https://zoom.us/j/123456789', $this->webinar->fresh()->join_url);

        Mail::assertQueued(WebinarJoinLinkMail::class, 3);

        foreach (['jane@acme.co.za', 'thabo@ridge.co.za', 'sipho@coastal.co.za'] as $email) {
            Mail::assertQueued(
                WebinarJoinLinkMail::class,
                fn (WebinarJoinLinkMail $mail) => $mail->hasTo($email)
            );
        }

        // Every registrant is stamped, so "was this person told?" has an answer.
        $this->assertSame(
            0,
            WebinarRegistration::where('webinar_id', $this->webinar->id)
                ->whereNull('join_link_sent_at')
                ->count()
        );
    }

    /**
     * THE POINT OF A SEPARATE MAILABLE. The access code went out once, at
     * registration; CoreX holds bcrypt(code) alone and cannot reproduce it. This mail
     * carries the link and must not restate or re-issue a credential.
     */
    public function test_the_mail_carries_the_link_and_no_credentials(): void
    {
        $registration = $this->registrant('Jane Smith', 'jane@acme.co.za');

        $rendered = (new WebinarJoinLinkMail($registration, 'https://zoom.us/j/123456789'))->render();

        $this->assertStringContainsString('https://zoom.us/j/123456789', $rendered);

        $this->assertStringNotContainsStringIgnoringCase('access code is', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('your code:', $rendered);
        $this->assertStringNotContainsStringIgnoringCase('password', $rendered);
    }

    public function test_the_registrations_endpoint_reports_the_new_join_url(): void
    {
        Mail::fake();

        $this->registrant('Jane Smith', 'jane@acme.co.za');

        // Before: the website's badge reads "Not set yet".
        $this->api()
            ->getJson("/api/v1/webinars/{$this->webinar->slug}/registrations")
            ->assertOk()
            ->assertJsonPath('webinar.join_url', null);

        $this->api()->postJson($this->url(), ['join_url' => 'https://zoom.us/j/999'])->assertOk();

        // After: "Link is set" — which is what stops an operator sending it twice.
        $this->api()
            ->getJson("/api/v1/webinars/{$this->webinar->slug}/registrations")
            ->assertOk()
            ->assertJsonPath('webinar.join_url', 'https://zoom.us/j/999');
    }

    public function test_a_webinar_with_no_registrants_saves_the_link_and_notifies_nobody(): void
    {
        Mail::fake();

        $this->api()
            ->postJson($this->url(), ['join_url' => 'https://zoom.us/j/1'])
            ->assertOk()
            ->assertJsonPath('notified', 0);

        $this->assertSame('https://zoom.us/j/1', $this->webinar->fresh()->join_url);

        Mail::assertNothingQueued();
    }

    /**
     * Re-sending is the point, not an accident: Zoom links get regenerated, and the
     * people already told are precisely the people who most need telling again.
     */
    public function test_sending_again_remails_the_whole_cohort_and_restamps(): void
    {
        Mail::fake();

        $registration = $this->registrant('Jane Smith', 'jane@acme.co.za');

        $this->api()->postJson($this->url(), ['join_url' => 'https://zoom.us/j/first'])->assertOk();

        $firstStamp = $registration->fresh()->join_link_sent_at;
        $this->assertNotNull($firstStamp);

        Carbon::setTestNow(Carbon::now()->addMinutes(5));

        $this->api()
            ->postJson($this->url(), ['join_url' => 'https://zoom.us/j/second'])
            ->assertOk()
            // The full count, never a delta.
            ->assertJsonPath('notified', 1);

        Mail::assertQueued(WebinarJoinLinkMail::class, 2);

        $this->assertSame('https://zoom.us/j/second', $this->webinar->fresh()->join_url);
        $this->assertTrue($registration->fresh()->join_link_sent_at->greaterThan($firstStamp));

        Carbon::setTestNow();
    }

    /**
     * The mail renders the link THIS send promised, not whatever join_url happens to
     * hold when the worker gets to it — the operator may have pressed again by then.
     */
    public function test_each_mail_captures_the_link_it_was_sent_with(): void
    {
        Mail::fake();

        $this->registrant('Jane Smith', 'jane@acme.co.za');

        $this->api()->postJson($this->url(), ['join_url' => 'https://zoom.us/j/first'])->assertOk();
        $this->api()->postJson($this->url(), ['join_url' => 'https://zoom.us/j/second'])->assertOk();

        Mail::assertQueued(
            WebinarJoinLinkMail::class,
            fn (WebinarJoinLinkMail $mail) => $mail->joinUrl === 'https://zoom.us/j/first'
        );
        Mail::assertQueued(
            WebinarJoinLinkMail::class,
            fn (WebinarJoinLinkMail $mail) => $mail->joinUrl === 'https://zoom.us/j/second'
        );
    }

    public function test_it_dispatches_the_domain_event_once_per_registration(): void
    {
        Mail::fake();
        Event::fake([WebinarJoinLinkSent::class]);

        $this->registrant('Jane Smith', 'jane@acme.co.za');
        $this->registrant('Thabo Dlamini', 'thabo@ridge.co.za');

        $this->api()->postJson($this->url(), ['join_url' => 'https://zoom.us/j/1'])->assertOk();

        Event::assertDispatchedTimes(WebinarJoinLinkSent::class, 2);
    }

    // ---- Validation --------------------------------------------------------

    public function test_it_rejects_a_bad_link_and_changes_nothing(): void
    {
        $cases = [
            'missing'   => null,
            'blank'     => '',
            'not a url' => 'not-a-url',
            'too long'  => 'https://zoom.us/j/' . str_repeat('9', 500),
        ];

        foreach ($cases as $label => $value) {
            Mail::fake();

            $payload = $value === null ? [] : ['join_url' => $value];

            $this->api()
                ->postJson($this->url(), $payload)
                ->assertStatus(422, "case: {$label}")
                ->assertJsonValidationErrors('join_url');

            // Nothing saved, nothing queued, nothing stamped.
            $this->assertNull($this->webinar->fresh()->join_url, "case: {$label}");
            Mail::assertNothingQueued();
            $this->assertSame(0, WebinarRegistration::whereNotNull('join_link_sent_at')->count());
        }
    }

    /**
     * The website renders CoreX's message verbatim against its own field, so the
     * wording is part of the contract — not an implementation detail.
     */
    public function test_the_messages_match_the_admin_form(): void
    {
        Mail::fake();

        $this->api()
            ->postJson($this->url(), ['join_url' => 'not-a-url'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.join_url.0',
                'The joining link needs to be a full web address, starting with https://'
            );

        $this->api()
            ->postJson($this->url(), [])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.join_url.0',
                'Paste the joining link before sending it to registrants.'
            );
    }

    // ---- 404s --------------------------------------------------------------

    public function test_an_unknown_slug_is_a_404_and_mails_nobody(): void
    {
        Mail::fake();

        $this->api()
            ->postJson($this->url('no-such-webinar'), ['join_url' => 'https://zoom.us/j/1'])
            ->assertStatus(404)
            ->assertJsonPath('ok', false);

        Mail::assertNothingQueued();
    }

    /**
     * A cancelled webinar's cohort has already been told it is off. Mailing them a
     * joining link for it is worse than doing nothing.
     */
    public function test_an_archived_webinar_is_a_404_and_mails_nobody(): void
    {
        Mail::fake();

        $this->registrant('Jane Smith', 'jane@acme.co.za');

        $this->webinar->update(['archived_at' => Carbon::now()]);

        $this->api()
            ->postJson($this->url(), ['join_url' => 'https://zoom.us/j/1'])
            ->assertStatus(404)
            ->assertJsonPath('ok', false);

        $this->assertNull($this->webinar->fresh()->join_url);
        Mail::assertNothingQueued();
        $this->assertNull(WebinarRegistration::first()->join_link_sent_at);
    }

    // ---- The guard rail ----------------------------------------------------

    /**
     * THE POSITIVE CONTROL for the rollback test below.
     *
     * Without this, "the jobs table is empty after a failure" proves nothing — the
     * table would also be empty if the send never queued anything at all, which is
     * exactly what happens under phpunit.xml's sync queue. This asserts that on the
     * happy path, real rows really do land in `jobs`, on the `default` queue.
     */
    public function test_a_successful_send_really_writes_jobs_on_the_default_queue(): void
    {
        $this->useRealQueue();

        $this->registrant('Jane Smith', 'jane@acme.co.za');
        $this->registrant('Thabo Dlamini', 'thabo@ridge.co.za');

        $this->api()
            ->postJson($this->url(), ['join_url' => 'https://zoom.us/j/123'])
            ->assertOk()
            ->assertJsonPath('notified', 2);

        $this->assertSame(2, DB::table('jobs')->count());

        // The workers run `queue:work` with no --queue flag and drain `default` only.
        // Anything on another queue is stranded forever.
        $this->assertSame(0, DB::table('jobs')->where('queue', '!=', 'default')->count());
    }

    /**
     * THE TEST THIS FEATURE EXISTS TO PASS.
     *
     * The website reports "saved and emailed to N" on a 200 and the operator believes
     * it. A failure part-way through must therefore leave NOTHING behind — not a saved
     * join_url with no mails, and not queued mails with no saved link.
     *
     * The failure is injected on the SECOND registrant, so by the time it throws the
     * first has already been queued into `jobs` and stamped. Anything less than a real
     * transaction leaves that first registrant's mail behind, and it would deliver a
     * joining link that was never saved.
     */
    public function test_a_failure_mid_send_rolls_back_the_link_the_stamps_and_the_jobs(): void
    {
        $this->useRealQueue();

        $this->registrant('Jane Smith', 'jane@acme.co.za');
        $this->registrant('Thabo Dlamini', 'thabo@ridge.co.za');

        // Thrown from inside the send loop — the event is dispatched per registration,
        // inside the transaction, immediately after the stamp.
        $seen = 0;
        Event::listen(WebinarJoinLinkSent::class, function () use (&$seen) {
            $seen++;

            if ($seen === 2) {
                throw new \RuntimeException('Something exploded mid-cohort');
            }
        });

        try {
            $this->api()->postJson($this->url(), ['join_url' => 'https://zoom.us/j/boom']);
        } catch (\Throwable) {
            // The 500 is not what is being asserted; the absence of wreckage is.
        }

        $this->assertSame(2, $seen, 'The send did not reach the second registrant — the test proves nothing.');

        $this->assertNull(
            $this->webinar->fresh()->join_url,
            'join_url was saved even though the send failed — the operator would be told the cohort was emailed.'
        );

        $this->assertSame(
            0,
            WebinarRegistration::whereNotNull('join_link_sent_at')->count(),
            'A registration was stamped as told even though the send failed.'
        );

        $this->assertSame(
            0,
            DB::table('jobs')->count(),
            'A queued mail survived a failed send — it would deliver a link that was never saved.'
        );
    }

    // ---- Meeting ID and passcode -------------------------------------------

    /**
     * THE WHOLE REASON THESE ARE SEPARATE COLUMNS.
     *
     * The passcode is not in the link. The link's `pwd` is an encoded token; the
     * passcode a person types into the Zoom app is a different, short, CASE-SENSITIVE
     * string. Anyone who "derives" one from the other ships a mail that cannot get a
     * registrant in — so this pins both values byte-for-byte, spaces and case included.
     */
    public function test_it_saves_the_meeting_id_and_passcode_verbatim(): void
    {
        Mail::fake();

        $this->registrant('Jane Smith', 'jane@acme.co.za');

        $this->api()
            ->postJson($this->url(), [
                'join_url'        => 'https://zoom.us/j/82437708791?pwd=qYHFilPvbAdY4EVMBurh9XYun4Rcga.1',
                'join_meeting_id' => '824 3770 8791',
                'join_passcode'   => '0ABcMc',
            ])
            ->assertOk()
            ->assertJsonPath('join_meeting_id', '824 3770 8791')
            ->assertJsonPath('join_passcode', '0ABcMc');

        $fresh = $this->webinar->fresh();

        // Internal spaces intact — not collapsed, not stripped.
        $this->assertSame('824 3770 8791', $fresh->join_meeting_id);

        // Case intact. "0abcmc" and "0ABCMC" are both wrong and both let a registrant in
        // to nothing.
        $this->assertSame('0ABcMc', $fresh->join_passcode);
    }

    public function test_the_mail_shows_the_meeting_id_and_passcode_when_set(): void
    {
        $registration = $this->registrant('Jane Smith', 'jane@acme.co.za');

        $rendered = (new WebinarJoinLinkMail(
            $registration,
            'https://zoom.us/j/82437708791',
            '824 3770 8791',
            '0ABcMc',
        ))->render();

        $this->assertStringContainsString('824 3770 8791', $rendered);
        $this->assertStringContainsString('0ABcMc', $rendered);

        // Rendered as-is, never upper-cased for looks.
        $this->assertStringNotContainsString('0ABCMC', $rendered);
    }

    public function test_the_mail_omits_the_lines_cleanly_when_they_are_not_set(): void
    {
        $registration = $this->registrant('Jane Smith', 'jane@acme.co.za');

        $rendered = (new WebinarJoinLinkMail($registration, 'https://zoom.us/j/1'))->render();

        // No orphan label sitting above nothing.
        $this->assertStringNotContainsString('Meeting ID', $rendered);
        $this->assertStringNotContainsString('Passcode', $rendered);
    }

    /**
     * A cohort mailed BEFORE this feature existed has jobs sitting in the queue that
     * were serialised against the two-argument mailable. unserialize() never runs the
     * constructor, so those jobs restore with the new properties absent — and a typed
     * property with no class-level default is fatal on first read. This is the
     * regression test for that: an in-flight send must still render.
     */
    public function test_a_mailable_restored_without_the_new_fields_still_renders(): void
    {
        $registration = $this->registrant('Jane Smith', 'jane@acme.co.za');

        $mail = new WebinarJoinLinkMail($registration, 'https://zoom.us/j/1');

        $restored = unserialize(serialize($mail));

        $this->assertNull($restored->joinMeetingId);
        $this->assertStringContainsString('https://zoom.us/j/1', $restored->render());
    }

    // ---- The three-way contract: set / clear / leave alone -------------------

    /**
     * "" CLEARS, AN ABSENT KEY DOES NOT.
     *
     * The console pre-fills its form from GET /registrations and posts every box back,
     * sending "" for one the operator emptied. If an absent key were also treated as ""
     * — or if these were read with $request->input() — then any client that posts only
     * join_url would silently wipe the Meeting ID and passcode off the webinar on every
     * re-send, with no error and nothing to notice until a registrant cannot get in.
     */
    public function test_an_empty_string_clears_a_value_and_an_absent_key_leaves_it_alone(): void
    {
        Mail::fake();

        $this->webinar->update([
            'join_meeting_id' => '824 3770 8791',
            'join_passcode'   => '0ABcMc',
        ]);

        // Absent keys: a re-send of the link alone must not blank either field.
        $this->api()
            ->postJson($this->url(), ['join_url' => 'https://zoom.us/j/2'])
            ->assertOk();

        $fresh = $this->webinar->fresh();
        $this->assertSame('824 3770 8791', $fresh->join_meeting_id);
        $this->assertSame('0ABcMc', $fresh->join_passcode);

        // "" on one field only: it clears, and the other is untouched.
        $this->api()
            ->postJson($this->url(), [
                'join_url'      => 'https://zoom.us/j/3',
                'join_passcode' => '',
            ])
            ->assertOk();

        $fresh = $this->webinar->fresh();
        $this->assertSame('824 3770 8791', $fresh->join_meeting_id);
        $this->assertNull($fresh->join_passcode);
    }

    public function test_the_registrations_endpoint_reports_all_three_so_a_resend_cannot_blank_them(): void
    {
        Mail::fake();

        $this->registrant('Jane Smith', 'jane@acme.co.za');

        $this->api()->postJson($this->url(), [
            'join_url'        => 'https://zoom.us/j/999',
            'join_meeting_id' => '824 3770 8791',
            'join_passcode'   => '0ABcMc',
        ])->assertOk();

        $this->api()
            ->getJson("/api/v1/webinars/{$this->webinar->slug}/registrations")
            ->assertOk()
            ->assertJsonPath('webinar.join_url', 'https://zoom.us/j/999')
            ->assertJsonPath('webinar.join_meeting_id', '824 3770 8791')
            ->assertJsonPath('webinar.join_passcode', '0ABcMc');
    }

    public function test_an_over_long_meeting_id_is_rejected_and_nothing_is_saved_or_sent(): void
    {
        Mail::fake();

        $this->registrant('Jane Smith', 'jane@acme.co.za');

        $this->api()
            ->postJson($this->url(), [
                'join_url'        => 'https://zoom.us/j/1',
                'join_meeting_id' => str_repeat('9', 101),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['join_meeting_id']);

        $this->assertNull($this->webinar->fresh()->join_url);

        Mail::assertNothingQueued();
    }
}
