<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan's QA1 rentals test, 7 Oct 2026: FICA blocked the agent from continuing with the tenant where it should have
 * warned. His ruling (8 Oct, relayed by the conductor): the FICA gate lifts on SUBMITTED, the same as sales.
 *
 * The application half of the rentals path — send to the authoriser, approve — proven in every FICA state.
 * The one stop that exists (the agency's own opt-in "FICA before authoriser" setting, OFF by default) follows
 * the shared FicaGate: it only ever stops an applicant who has NOT submitted. Everywhere else FICA warns.
 */
final class RentalApplicationFicaGateStepsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agent;
    private User $authoriser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cr-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Ayanda', 'last_name' => 'Mtolo', 'email' => 'ayanda@example.co.za',
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->authoriser = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agency->update(['rental_application_ro_user_ids' => [$this->authoriser->id]]);
        $this->agency->refresh();
    }

    private function fica(?string $status): void
    {
        if ($status === null) {
            return;
        }
        FicaSubmission::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'requested_by' => $this->agent->id, 'status' => $status,
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
            'verified_at' => $status === 'approved' ? now() : null,
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'under_assessment', 'submitted_at' => now()->subDay(),
            'current_generation' => 1, 'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    private function requireFicaBeforeAuthoriser(bool $on): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['require_fica_before_authorisation' => $on],
        );
    }

    /** @return array<string, array{0:?string,1:bool}> [fica status, gate open] */
    public static function ficaStates(): array
    {
        return [
            'no FICA at all' => [null, false],
            'requested, not submitted (draft)' => ['draft', false],
            'sent back for corrections' => ['corrections_requested', false],
            'submitted' => ['submitted', true],
            'under review' => ['under_review', true],
            'agent approved' => ['agent_approved', true],
            'referred to the compliance officer' => ['referred_to_co', true],
            'approved' => ['approved', true],
        ];
    }

    /** @dataProvider ficaStates */
    public function test_send_to_authoriser_goes_through_in_every_fica_state_when_the_hard_stop_is_off(?string $status, bool $open): void
    {
        $this->requireFicaBeforeAuthoriser(false);
        $this->fica($status);
        $application = $this->application();

        $response = $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.review.submit-for-approval', $application),
            ['expected_generation' => 1],
        );

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertNotNull($application->fresh()->submitted_for_approval_at);

        if ($open) {
            $response->assertJsonPath('fica_warning', null);
        } else {
            $this->assertNotEmpty($response->json('fica_warning'), 'not-yet-submitted FICA warns on the way through');
            $this->assertNotEmpty($response->json('fica_url'), 'and carries the link to request/complete FICA');
        }
    }

    /** @dataProvider ficaStates */
    public function test_the_agencys_own_hard_stop_only_ever_stops_an_applicant_who_has_not_submitted(?string $status, bool $open): void
    {
        $this->requireFicaBeforeAuthoriser(true);
        $this->fica($status);
        $application = $this->application();

        $response = $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.review.submit-for-approval', $application),
            ['expected_generation' => 1],
        );

        if ($open) {
            $response->assertOk();
            $this->assertNotNull($application->fresh()->submitted_for_approval_at);
        } else {
            $response->assertStatus(422)->assertJsonPath('reason', 'fica_outstanding');
            $this->assertNotEmpty($response->json('fica_url'));
            $this->assertNull($application->fresh()->submitted_for_approval_at);
        }
    }

    /** @dataProvider ficaStates */
    public function test_approving_is_never_stopped_by_fica_it_only_records_the_condition_until_compliance_verifies(?string $status, bool $open): void
    {
        $this->fica($status);
        $application = $this->application(['submitted_for_approval_at' => now()]);

        $response = $this->actingAs($this->authoriser)->post(
            route('corex.rental-applications.authorisation.approve', $application),
            ['approved_rental_amount' => '10000'],
        );

        $response->assertSessionDoesntHaveErrors();
        $application->refresh();
        $this->assertSame('approved', $application->status);
        // "Approved, subject to FICA verification" is a label on the approval, not a stop: it is there until
        // compliance has VERIFIED the FICA (approved), whatever the gate says.
        $verified = $status === 'approved';
        $this->assertSame(! $verified, $application->approved_subject_to_fica_at !== null);
    }

    /** @dataProvider ficaStates */
    public function test_the_review_screen_warns_with_a_link_when_fica_is_not_submitted_and_says_submitted_when_it_is(?string $status, bool $open): void
    {
        $this->fica($status);
        $application = $this->application();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $application))
            ->assertOk()->getContent();

        if ($status === 'approved') {
            $this->assertStringContainsString('FICA Complete', $html);
        } elseif ($open) {
            $this->assertStringContainsString('FICA Submitted', $html, 'a submitted FICA is not "Outstanding"');
            $this->assertStringNotContainsString('FICA Outstanding', $html);
        } else {
            $this->assertStringContainsString('FICA Outstanding', $html);
            $this->assertStringContainsString('data-qa="fica-gate-link"', $html, 'the warning carries the link to request/complete FICA');
        }
    }
}
