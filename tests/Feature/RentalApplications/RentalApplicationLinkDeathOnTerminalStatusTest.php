<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationDeclineReasonTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Applicant link lifetime, 2026-09-14 — Johan, final version after two
 * rejected designs (a 7-day grace window on decline, then a revive link in
 * the decline email): "the link dies. done. declined is declined. if the
 * applicant wants to do anything it will be from the agent's side sending
 * a new link to reopen the application. easy simple and nothing open for
 * whatever time period." Unconditional for both withdrawn and declined, no
 * setting, no exposure window. Reuses the SAME token_expires_at->isPast()
 * check show()/pdf()/viewDocument() already run on every request — no new
 * enforcement mechanism. reopen() remains the only way back in, unchanged,
 * and was never gated by any of this (it already ignores token_expires_at
 * and extends the same token unconditionally on success).
 */
final class RentalApplicationLinkDeathOnTerminalStatusTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $plainAgent;
    private User $authoriser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'email' => 'thabo@example.co.za',
        ]);
        $this->plainAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->authoriser = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agency->update(['rental_application_ro_user_ids' => [$this->authoriser->id]]);
        $this->agency->refresh();
    }

    private function returnedApplication(): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->plainAgent->id, 'status' => 'returned',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
            'full_name' => 'Thabo Mokoena', 'id_number' => '8505125800086',
            'submitted_at' => now()->subDay(), 'current_generation' => 1,
        ]);
    }

    private function underAssessmentApplication(): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->plainAgent->id, 'status' => 'under_assessment',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
            'full_name' => 'Thabo Mokoena', 'id_number' => '8505125800086',
            // isPendingAuthorisation() (RentalApplication.php:409-412) requires
            // BOTH — without this, guardCanDecide() aborts 422 before the
            // status write this test is actually about ever runs.
            'submitted_at' => now()->subDay(), 'submitted_for_approval_at' => now()->subHour(), 'current_generation' => 1,
        ]);
    }

    public function test_marking_an_application_withdrawn_kills_the_link_immediately(): void
    {
        $application = $this->returnedApplication();
        $this->assertTrue($application->token_expires_at->isFuture());

        $response = $this->actingAs($this->plainAgent)->post(
            route('corex.rental-applications.update-status', $application),
            ['status' => 'withdrawn', 'note' => 'Applicant called to say they found another place.'],
        );

        $response->assertRedirect();
        $application->refresh();
        $this->assertSame('withdrawn', $application->status);
        $this->assertTrue($application->token_expires_at->isPast(), 'Withdrawing must kill the link immediately.');

        $visit = $this->get(route('rental-applications.public.show', $application->token));
        $visit->assertOk();
        $visit->assertSee('This link has expired', false);
    }

    public function test_marking_an_application_under_assessment_does_not_touch_the_link(): void
    {
        $application = $this->returnedApplication();
        $originalExpiry = $application->token_expires_at;

        $this->actingAs($this->plainAgent)->post(
            route('corex.rental-applications.update-status', $application),
            ['status' => 'under_assessment'],
        );

        $this->assertTrue($originalExpiry->equalTo($application->fresh()->token_expires_at), 'Only withdraw/decline may touch the link — every other transition must leave it alone.');
    }

    public function test_declining_an_application_kills_the_link_immediately(): void
    {
        $template = RentalApplicationDeclineReasonTemplate::create([
            'agency_id' => $this->agency->id, 'reason' => 'Affordability',
            'guidance' => 'General tips that help going forward.', 'sort_order' => 1,
        ]);
        $application = $this->underAssessmentApplication();
        $this->assertTrue($application->token_expires_at->isFuture());

        $response = $this->actingAs($this->authoriser)->post(
            route('corex.rental-applications.authorisation.decline', $application),
            ['reason' => 'Does not meet affordability guideline.', 'decline_reason_template_id' => $template->id],
        );

        $response->assertRedirect();
        $application->refresh();
        $this->assertSame('declined', $application->status);
        $this->assertTrue($application->token_expires_at->isPast(), 'Declining must kill the link immediately — no grace window, per Johan\'s final ruling.');

        $visit = $this->get(route('rental-applications.public.show', $application->token));
        $visit->assertOk();
        $visit->assertSee('This link has expired', false);
        $visit->assertSee('Please contact your agent for a new link', false);
    }

    /**
     * The one way back in, unchanged and unaffected by any of this —
     * reopen() already ignores token_expires_at and extends the SAME
     * token unconditionally on success (RentalApplicationReviewController.php:711-721).
     * A declined application's link being dead must never block the agent
     * from reviving it.
     */
    public function test_reopen_still_works_on_a_declined_application_after_its_link_has_died(): void
    {
        $template = RentalApplicationDeclineReasonTemplate::create([
            'agency_id' => $this->agency->id, 'reason' => 'Affordability',
            'guidance' => 'General tips that help going forward.', 'sort_order' => 1,
        ]);
        $application = $this->underAssessmentApplication();
        $this->actingAs($this->authoriser)->post(
            route('corex.rental-applications.authorisation.decline', $application),
            ['reason' => 'Does not meet affordability guideline.', 'decline_reason_template_id' => $template->id],
        );
        $application->refresh();
        $this->assertTrue($application->token_expires_at->isPast());

        $response = $this->actingAs($this->authoriser)->postJson(
            route('corex.rental-applications.review.reopen', $application),
            ['note' => 'Applicant called with more documents — giving them another chance.'],
        );

        $response->assertOk();
        $application->refresh();
        $this->assertSame('reopened', $application->status);
        $this->assertTrue($application->token_expires_at->isFuture(), 'reopen() must revive the SAME link regardless of it having already expired.');

        $visit = $this->get(route('rental-applications.public.show', $application->token));
        $visit->assertOk();
        $visit->assertDontSee('This link has expired');
    }
}
