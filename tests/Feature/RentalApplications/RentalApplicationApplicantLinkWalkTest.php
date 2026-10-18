<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\RentalApplicationInviteMail;
use App\Mail\RentalApplicationReturnedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rentals front-half walk (QA1, 8 Oct 2026) - the applicant's link, found by walking "agent sends the application ->
 * tenant opens it -> agent is told it came back":
 *  - the "application returned" mail takes the agent to the REVIEW screen (the plain show page is a read-only copy);
 *  - the public PDF holds the applicant's personal data, so it follows the same availability rules as the form (it used
 *    to serve a draft / expired / withdrawn link);
 *  - resending renews an expired link (it used to mail the same dead one) and never mails a link that was closed on purpose;
 *  - a draft application does not present its unusable link as shareable.
 * (The custom-answer autosave nesting is pinned by tests/js/rental-application-autosave-nesting.test.mjs.)
 */
final class RentalApplicationApplicantLinkWalkTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    private function application(string $status, array $extra = []): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => 'thandi-' . uniqid() . '@example.test', 'agent_id' => $this->agent->id, 'created_by_user_id' => $this->agent->id,
        ]);

        return RentalApplication::create($extra + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id, 'created_by_user_id' => $this->agent->id,
            'status' => $status, 'full_name' => 'Thandi Nkosi', 'email' => 'applicant-' . uniqid() . '@example.test',
            'token' => bin2hex(random_bytes(16)), 'token_expires_at' => now()->addDays(7),
        ]);
    }

    public function test_the_returned_mail_takes_the_agent_to_the_review_screen(): void
    {
        $app = $this->application('returned', ['submitted_at' => now()]);

        $mail = new RentalApplicationReturnedMail($app->load(['createdBy', 'contact', 'agency']));

        $this->assertSame(route('corex.rental-applications.review', $app), $mail->reviewUrl);
    }

    public function test_the_public_pdf_is_not_served_for_a_draft_expired_or_withdrawn_link(): void
    {
        foreach ([
            'draft' => $this->application('draft'),
            'expired' => $this->application('sent', ['token_expires_at' => now()->subDay()]),
            'withdrawn' => $this->application('withdrawn', ['token_expires_at' => now()]),
        ] as $label => $app) {
            $res = $this->get(route('rental-applications.public.pdf', $app->token));

            $res->assertOk();
            $res->assertViewIs('rental-applications.public.unavailable');
            $this->assertStringNotContainsString('attachment', (string) $res->headers->get('Content-Disposition'), "$label link must not download the PDF");
        }
    }

    public function test_resending_an_expired_link_renews_it_and_mails_it(): void
    {
        $app = $this->application('sent', ['token_expires_at' => now()->subDays(2)]);
        $token = $app->token;

        $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app))->assertSessionHas('success');

        $fresh = $app->fresh();
        $this->assertSame($token, $fresh->token, 'the link already in the applicant\'s inbox works again');
        $this->assertTrue($fresh->token_expires_at->isFuture(), 'an expired link was re-mailed unchanged before');
        Mail::assertQueued(RentalApplicationInviteMail::class, fn ($m) => $m->hasTo($app->email));
    }

    public function test_a_declined_or_withdrawn_application_is_not_mailed_a_link_that_is_closed(): void
    {
        foreach (['declined', 'withdrawn'] as $status) {
            $app = $this->application($status, ['token_expires_at' => now()]);

            $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app))->assertSessionHas('error');

            $this->assertTrue($app->fresh()->token_expires_at->lte(now()), "$status: the link stays closed");
        }
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_a_draft_does_not_show_its_link_as_shareable_but_a_sent_application_does(): void
    {
        $draft = $this->application('draft');
        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $draft))->assertOk()->getContent();
        $this->assertStringContainsString("link starts working once you send the application", $html);
        $this->assertStringNotContainsString('Online link:', $html);

        $sent = $this->application('sent');
        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $sent))->assertOk()->getContent();
        $this->assertStringContainsString('Online link:', $html);
    }
}
