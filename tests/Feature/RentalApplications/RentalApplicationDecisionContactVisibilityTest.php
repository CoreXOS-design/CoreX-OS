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
use Tests\TestCase;

/**
 * Approving or declining an application crashed (500) when the person deciding could not see the
 * applicant's CONTACT in their own contact list (another branch's contact, or an archived one): the
 * application's contact link ran through the contact visibility filter, came back empty, and the
 * confirmation message read `->full_name` off nothing.
 *
 * Rule: the decision works for anyone permitted to decide the application; anyone else gets a clean
 * refusal. Whether they may SEE the contact elsewhere in CoreX is a separate question the decision
 * must never depend on.
 */
final class RentalApplicationDecisionContactVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branchA = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => ($branch ?? $this->branchA)->id, 'role' => $role]);
    }

    /** An application in the decider's own branch whose applicant contact lives in ANOTHER branch (hidden from a branch-scoped user's contact list). */
    private function applicationWithHiddenContact(User $creator, bool $archived = false): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchB->id,
            'first_name' => 'Thandi', 'last_name' => 'Zulu', 'email' => 'thandi-' . uniqid() . '@example.co.za',
            'created_by_user_id' => $creator->id,
        ]);
        if ($archived) {
            $contact->delete();
        }

        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchA->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $creator->id, 'status' => 'under_assessment', 'submitted_for_approval_at' => now(),
            'full_name' => 'Thandi Zulu', 'email' => 'applicant-' . uniqid() . '@example.co.za',
        ]);
    }

    private function declineTemplateId(): int
    {
        return RentalApplicationDeclineReasonTemplate::create([
            'agency_id' => $this->agency->id, 'reason' => 'Income requirements not met',
            'guidance' => 'Thank you for applying.', 'sort_order' => 0,
        ])->id;
    }

    public function test_a_reviewer_who_cannot_see_the_applicants_contact_can_still_approve(): void
    {
        $reviewer = $this->user('branch_manager');
        $this->agency->update(['rental_application_ro_user_ids' => [$reviewer->id]]);
        $app = $this->applicationWithHiddenContact($this->user('agent'));

        // Precondition that makes this test meaningful: the contact really is hidden from this user's own contact list.
        $this->actingAs($reviewer);
        $this->assertNull(Contact::query()->find($app->contact_id), 'Fixture must hide the contact from the decider.');

        $response = $this->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 15000]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertStringContainsString('Thandi Zulu', (string) session('success'));
        $this->assertSame('approved', $app->fresh()->status);
    }

    public function test_a_reviewer_who_cannot_see_the_applicants_contact_can_still_decline(): void
    {
        $reviewer = $this->user('branch_manager');
        $this->agency->update(['rental_application_ro_user_ids' => [$reviewer->id]]);
        $app = $this->applicationWithHiddenContact($this->user('agent'));

        $response = $this->actingAs($reviewer)->post(route('corex.rental-applications.authorisation.decline', $app), [
            'reason' => 'not qualifying', 'decline_reason_template_id' => $this->declineTemplateId(),
        ]);

        $response->assertRedirect();
        $this->assertSame('declined', $app->fresh()->status);
        $this->assertStringContainsString('Thandi', (string) $app->fresh()->decline_email_body);
    }

    public function test_an_archived_applicant_contact_does_not_block_a_decision(): void
    {
        $reviewer = $this->user('branch_manager');
        $this->agency->update(['rental_application_ro_user_ids' => [$reviewer->id]]);
        $app = $this->applicationWithHiddenContact($this->user('agent'), archived: true);

        $this->actingAs($reviewer)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 15000])
            ->assertRedirect();

        $this->assertSame('approved', $app->fresh()->status);
    }

    public function test_someone_not_permitted_to_decide_gets_a_clean_403_not_a_500(): void
    {
        $plainAgent = $this->user('agent');
        $app = $this->applicationWithHiddenContact($this->user('agent'));

        $this->actingAs($plainAgent)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 15000])
            ->assertForbidden();
        $this->actingAs($plainAgent)->post(route('corex.rental-applications.authorisation.decline', $app), [
            'reason' => 'no', 'decline_reason_template_id' => $this->declineTemplateId(),
        ])->assertForbidden();

        $this->assertSame('under_assessment', $app->fresh()->status);
    }
}
