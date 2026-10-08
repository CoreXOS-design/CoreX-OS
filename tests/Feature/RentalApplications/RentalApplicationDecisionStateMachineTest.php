<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationDeclineReasonTemplate;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplicationStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rentals front-half walk (QA1, 8 Oct 2026) - the application's decision state machine. Each test pins one defect the walk
 * found and fixed:
 *  - submit-for-approval and the agent's status control only work from returned / under_assessment (a decided or withdrawn
 *    application could be dragged back into the authoriser's queue by a crafted POST);
 *  - one-step approval is refused for a withdrawn application and runs the same hand-off checks the two-step path runs;
 *  - a decision made after the applicant was already told (override flip, reopen, resubmit) can be told again;
 *  - "send approval" refuses when there is nowhere to send, instead of stamping applicant_notified_at;
 *  - a withdrawal updates the contact's cached rental status;
 *  - the authoriser's request-more-info is scoped to the records the authoriser may see.
 */
final class RentalApplicationDecisionStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $authoriser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Bus::fake();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = $this->user('agent');
        // Admin + CO tier: the realistic override-tier authoriser.
        $this->authoriser = $this->user('admin');
        $this->agency->update(['rental_application_co_user_ids' => [$this->authoriser->id]]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => ($branch ?? $this->branch)->id, 'role' => $role]);
    }

    private function application(string $status, array $extra = []): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => 'thandi-' . uniqid() . '@example.test', 'agent_id' => $this->agent->id, 'created_by_user_id' => $this->agent->id,
        ]);

        return RentalApplication::create($extra + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => $status, 'full_name' => 'Thandi Nkosi',
            'email' => 'applicant-' . uniqid() . '@example.test', 'token' => bin2hex(random_bytes(16)),
            'token_expires_at' => now()->addDays(7), 'submitted_at' => now()->subDay(),
        ]);
    }

    private function setting(array $values): void
    {
        $row = RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->first()
            ?? new RentalApplicationQualifyingSetting(['agency_id' => $this->agency->id]);
        $row->fill($values)->save();
    }

    private function declineTemplateId(): int
    {
        return RentalApplicationDeclineReasonTemplate::create([
            'agency_id' => $this->agency->id, 'reason' => 'Income requirements not met', 'guidance' => 'Thank you for applying.', 'sort_order' => 0,
        ])->id;
    }

    private function unsortedPdf(RentalApplication $app): Document
    {
        return Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'source_type' => 'rental_application', 'source_id' => $app->id,
            'original_name' => 'payslip.pdf', 'storage_path' => 'x/payslip.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf',
        ]);
    }

    // ── hand-off only from returned / under_assessment ────────────────────

    public function test_a_decided_or_withdrawn_application_cannot_be_handed_to_the_authoriser_again(): void
    {
        foreach (['approved', 'declined', 'withdrawn'] as $status) {
            $app = $this->application($status);

            $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.submit-for-approval', $app))->assertStatus(422);

            $app->refresh();
            $this->assertSame($status, $app->status, "$status must stay $status");
            $this->assertNull($app->submitted_for_approval_at, "$status must not become 'pending authorisation'");
        }
    }

    public function test_a_returned_application_is_handed_over_and_the_history_records_the_real_from_status(): void
    {
        $app = $this->application('returned');

        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.submit-for-approval', $app))->assertOk();

        $app->refresh();
        $this->assertSame('under_assessment', $app->status);
        $this->assertNotNull($app->submitted_for_approval_at);
        $row = RentalApplicationStatusHistory::where('rental_application_id', $app->id)->latest('id')->first();
        $this->assertSame('returned', $row->from_status, 'history said from = to before');
        $this->assertSame('under_assessment', $row->to_status);
    }

    // ── the agent's own status control ────────────────────────────────────

    public function test_the_status_control_refuses_to_move_a_decided_application(): void
    {
        foreach (['approved', 'declined'] as $status) {
            $app = $this->application($status);

            $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $app), ['status' => 'under_assessment'])
                ->assertSessionHas('error');
            // Declined is final. (An APPROVED application with no lease yet may be withdrawn - RentalFrontHalfDecisionsTest.)
            if ($status === 'declined') {
                $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $app), ['status' => 'withdrawn', 'note' => 'told me'])
                    ->assertSessionHas('error');
            }

            $this->assertSame($status, $app->fresh()->status);
        }
    }

    public function test_moving_a_returned_application_to_assessment_leaves_no_stale_queue_marker_and_withdrawing_updates_the_contact(): void
    {
        $app = $this->application('returned', ['submitted_for_approval_at' => now()->subDays(3)]);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $app), ['status' => 'under_assessment'])->assertSessionHas('success');
        $app->refresh();
        $this->assertSame('under_assessment', $app->status);
        $this->assertNull($app->submitted_for_approval_at, 'a stale hand-off date must not put the file in the authoriser queue by itself');
        $this->assertFalse($app->isPendingAuthorisation());

        $this->actingAs($this->agent)->post(route('corex.rental-applications.update-status', $app), ['status' => 'withdrawn', 'note' => 'Applicant found another place'])->assertSessionHas('success');
        $this->assertSame('withdrawn', $app->fresh()->status);
        $this->assertSame('withdrawn', Contact::find($app->contact_id)->rental_application_status, "the contact's cached rental status must follow a withdrawal");
    }

    // ── one-step approval ────────────────────────────────────────────────

    public function test_one_step_approval_cannot_approve_a_withdrawn_application(): void
    {
        $this->setting(['approval_mode' => 'one_step']);
        $app = $this->application('withdrawn');

        $this->actingAs($this->authoriser)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 9000])->assertStatus(422);

        $this->assertSame('withdrawn', $app->fresh()->status);
    }

    public function test_one_step_approval_runs_the_same_hand_off_checks_as_two_step(): void
    {
        $this->setting(['approval_mode' => 'one_step']);
        $app = $this->application('under_assessment');
        $this->unsortedPdf($app);

        $this->actingAs($this->authoriser)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 9000])
            ->assertSessionHas('error');
        $this->assertSame('under_assessment', $app->fresh()->status, 'an unsorted PDF refuses a one-step approval too');

        // FICA hard stop switched on and the applicant has not submitted FICA: refused as well.
        $clean = $this->application('under_assessment');
        $this->setting(['approval_mode' => 'one_step', 'require_fica_before_authorisation' => true]);
        $this->actingAs($this->authoriser)->post(route('corex.rental-applications.authorisation.approve', $clean), ['approved_rental_amount' => 9000])
            ->assertSessionHas('error');
        $this->assertSame('under_assessment', $clean->fresh()->status);

        // Hard stop off: the same application is approved.
        $this->setting(['approval_mode' => 'one_step', 'require_fica_before_authorisation' => false]);
        $this->actingAs($this->authoriser)->post(route('corex.rental-applications.authorisation.approve', $clean), ['approved_rental_amount' => 9000])
            ->assertRedirect();
        $this->assertSame('approved', $clean->fresh()->status);
    }

    // ── telling the applicant again after the decision changed ────────────

    public function test_a_flipped_decision_can_be_sent_to_the_applicant(): void
    {
        $app = $this->application('approved', ['applicant_notified_at' => now()->subDay(), 'approved_rental_amount' => 9000]);

        $this->actingAs($this->authoriser)->post(route('corex.rental-applications.authorisation.decline', $app), [
            'reason' => 'New information', 'decline_reason_template_id' => $this->declineTemplateId(),
        ])->assertRedirect();

        $app->refresh();
        $this->assertSame('declined', $app->status);
        $this->assertNull($app->applicant_notified_at, 'the new decision has not been told to the applicant');

        $this->actingAs($this->agent)->post(route('corex.rental-applications.review.send-decline', $app), ['subject' => 'Your application', 'body' => 'We are sorry.'])
            ->assertSessionHas('success');
        $this->assertNotNull($app->fresh()->applicant_notified_at);

        // ...and back again: approving the (reopened) application makes "send approval" available once more.
        $this->actingAs($this->authoriser)->post(route('corex.rental-applications.authorisation.approve', $app->fresh()), [
            'approved_rental_amount' => 9500, 'reason' => 'Further evidence received',
        ])->assertRedirect();
        $this->assertNull($app->fresh()->applicant_notified_at);
    }

    public function test_reopening_and_the_applicants_resubmission_clear_the_previous_rounds_markers(): void
    {
        $app = $this->application('declined', ['applicant_notified_at' => now()->subDay(), 'submitted_for_approval_at' => now()->subDays(2)]);

        $this->actingAs($this->authoriser)->postJson(route('corex.rental-applications.review.reopen', $app), ['note' => 'More evidence'])->assertOk();

        $app->refresh();
        $this->assertSame('reopened', $app->status);
        $this->assertNull($app->applicant_notified_at);
        $this->assertNull($app->submitted_for_approval_at);
    }

    // ── send approval with nowhere to send ────────────────────────────────

    public function test_send_approval_refuses_when_there_is_no_email_on_file_and_does_not_stamp_the_application(): void
    {
        $app = $this->application('approved', ['email' => null, 'approved_rental_amount' => 9000]);
        Contact::find($app->contact_id)->forceFill(['email' => null])->save();

        $this->actingAs($this->agent)->post(route('corex.rental-applications.review.send', $app))->assertSessionHas('error');

        $this->assertNull($app->fresh()->applicant_notified_at, 'nothing was sent, so the applicant must not be marked as told');
    }

    // ── scoping and dead links ────────────────────────────────────────────

    public function test_the_authorisers_request_more_info_respects_branch_scope(): void
    {
        $otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Durban']);
        $ro = $this->user('branch_manager', $otherBranch);
        $this->agency->update(['rental_application_ro_user_ids' => [$ro->id]]);
        $app = $this->application('under_assessment', ['submitted_for_approval_at' => now()]);

        $res = $this->actingAs($ro)->post(route('corex.rental-applications.authorisation.request-more-info', $app), ['reason' => 'need payslips']);

        $this->assertContains($res->getStatusCode(), [403, 404], 'a reviewer in another branch must not reach this file');
        $this->assertNotNull($app->fresh()->submitted_for_approval_at, 'the file stays with the authoriser');
    }

    public function test_the_agent_cannot_ask_a_declined_applicant_for_more_when_their_link_is_closed(): void
    {
        $app = $this->application('declined', ['token_expires_at' => now()]);

        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.request-more-info', $app), ['note' => 'please add payslips'])
            ->assertStatus(422);
    }
}
