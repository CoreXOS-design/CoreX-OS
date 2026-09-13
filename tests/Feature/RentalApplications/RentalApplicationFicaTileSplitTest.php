<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FICA Outstanding tile split, 2026-09-15 — Johan: "yes on fica"
 * (.ai/specs/rental-applications.md, "FICA Outstanding tile split").
 *
 * Covers RentalApplicationController::applyFicaBucketFilter(): every
 * status in the real FicaSubmission vocabulary lands in exactly the
 * bucket the spec settled on, the split keys off the LATEST submission
 * (not "does any submission with this status exist"), and the two new
 * tiles' combined count matches what the single pre-split tile would
 * have shown for the same population.
 */
final class RentalApplicationFicaTileSplitTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'FICA Split Agency', 'slug' => 'fica-split-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Test', 'last_name' => 'Applicant',
            'email' => 'applicant-' . uniqid() . '@example.test',
        ]);
    }

    private function application(Contact $contact, string $status = 'returned'): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $this->agent->id,
            'status' => $status, 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);
    }

    private function ficaSubmission(Contact $contact, string $status, ?\DateTimeInterface $createdAt = null, ?\DateTimeInterface $verifiedAt = null): FicaSubmission
    {
        $submission = FicaSubmission::create([
            'contact_id' => $contact->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'requested_by' => $this->agent->id, 'status' => $status,
            'verified_at' => $verifiedAt, 'verified_by' => $verifiedAt ? $this->agent->id : null,
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
        if ($createdAt) {
            $submission->created_at = $createdAt;
            $submission->save();
        }

        return $submission;
    }

    private function tileCounts(): array
    {
        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.index'));
        $response->assertOk();

        return $response->viewData('counts');
    }

    public function test_no_fica_submission_at_all_is_waiting_on_applicant(): void
    {
        $this->application($this->contact());

        $counts = $this->tileCounts();
        $this->assertSame(1, $counts['fica_waiting_applicant']);
        $this->assertSame(0, $counts['fica_waiting_us']);
    }

    /** @dataProvider applicantStatusProvider */
    public function test_applicant_bucket_statuses(string $status): void
    {
        $contact = $this->contact();
        $this->application($contact);
        $this->ficaSubmission($contact, $status);

        $counts = $this->tileCounts();
        $this->assertSame(1, $counts['fica_waiting_applicant'], "status={$status} should be Waiting on applicant");
        $this->assertSame(0, $counts['fica_waiting_us']);
    }

    public static function applicantStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'corrections_requested' => ['corrections_requested'],
        ];
    }

    /** @dataProvider staffStatusProvider */
    public function test_staff_bucket_statuses(string $status): void
    {
        $contact = $this->contact();
        $this->application($contact);
        $this->ficaSubmission($contact, $status);

        $counts = $this->tileCounts();
        $this->assertSame(0, $counts['fica_waiting_applicant'], "status={$status} should NOT be Waiting on applicant");
        $this->assertSame(1, $counts['fica_waiting_us'], "status={$status} should be Waiting on us");
    }

    public static function staffStatusProvider(): array
    {
        return [
            'submitted' => ['submitted'],
            'under_review' => ['under_review'],
            'agent_approved' => ['agent_approved'],
            'referred_to_co' => ['referred_to_co'],
            // Deliberate divergence from RentalApplication::ficaAwaitingApplicantAction(),
            // which buckets these with the applicant for a DIFFERENT
            // audience/question (what the applicant's own confirmation
            // page tells them). Neither has a live self-service resend
            // link (FicaController::resend() only allows draft/
            // corrections_requested) — a staff decision must happen first.
            'rejected' => ['rejected'],
            'cancelled' => ['cancelled'],
        ];
    }

    public function test_approved_but_expired_is_waiting_on_us(): void
    {
        $contact = $this->contact();
        $this->application($contact);
        $this->ficaSubmission($contact, 'approved', verifiedAt: now()->subMonths(12));

        $counts = $this->tileCounts();
        $this->assertSame(0, $counts['fica_waiting_applicant']);
        $this->assertSame(1, $counts['fica_waiting_us']);
    }

    public function test_approved_and_valid_is_in_neither_fica_tile(): void
    {
        $contact = $this->contact();
        $this->application($contact);
        $this->ficaSubmission($contact, 'approved', verifiedAt: now()->subMonths(2));

        $counts = $this->tileCounts();
        $this->assertSame(0, $counts['fica_waiting_applicant']);
        $this->assertSame(0, $counts['fica_waiting_us']);
    }

    /**
     * The correctness-critical case flagged in the spec: an old rejected
     * submission must not out-count a newer, live draft. The split keys
     * off the LATEST submission by created_at, never "does any submission
     * with this status exist" — get this wrong and a contact could land
     * in both buckets, or neither.
     */
    public function test_latest_submission_wins_over_an_older_one_of_a_different_bucket(): void
    {
        $contact = $this->contact();
        $this->application($contact);
        $this->ficaSubmission($contact, 'rejected', createdAt: now()->subDays(10));
        $this->ficaSubmission($contact, 'draft', createdAt: now()->subDays(1));

        $counts = $this->tileCounts();
        $this->assertSame(1, $counts['fica_waiting_applicant'], 'the newer draft must win');
        $this->assertSame(0, $counts['fica_waiting_us']);
    }

    public function test_reverse_ordering_also_respects_the_latest_submission(): void
    {
        $contact = $this->contact();
        $this->application($contact);
        $this->ficaSubmission($contact, 'draft', createdAt: now()->subDays(10));
        $this->ficaSubmission($contact, 'rejected', createdAt: now()->subDays(1));

        $counts = $this->tileCounts();
        $this->assertSame(0, $counts['fica_waiting_applicant']);
        $this->assertSame(1, $counts['fica_waiting_us'], 'the newer rejected must win');
    }

    /**
     * The two tiles must sum to what the original single fica_outstanding
     * tile counted, for a population spanning every real status in the
     * vocabulary plus "no submission at all" and "already approved".
     */
    public function test_the_two_tiles_sum_to_the_original_single_tile_population(): void
    {
        $allStatuses = ['draft', 'submitted', 'under_review', 'agent_approved', 'referred_to_co', 'corrections_requested', 'rejected', 'cancelled'];
        foreach ($allStatuses as $status) {
            $contact = $this->contact();
            $this->application($contact);
            $this->ficaSubmission($contact, $status);
        }
        // No submission at all.
        $this->application($this->contact());
        // Already approved and valid — must be excluded from both, and
        // from the original tile's population too.
        $approvedContact = $this->contact();
        $this->application($approvedContact);
        $this->ficaSubmission($approvedContact, 'approved', verifiedAt: now()->subMonths(1));

        $counts = $this->tileCounts();
        $originalPopulation = count($allStatuses) + 1; // +1 for "no submission at all"
        $this->assertSame($originalPopulation, $counts['fica_waiting_applicant'] + $counts['fica_waiting_us']);
    }

    /** The split must respect the same own/branch/all scope every other tile already does. */
    public function test_fica_tiles_respect_the_scope_toggle(): void
    {
        $otherAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $ownContact = $this->contact();
        RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'contact_id' => $ownContact->id, 'created_by_user_id' => $otherAgent->id,
            'status' => 'returned', 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);

        $response = $this->actingAs($otherAgent)->get(route('corex.rental-applications.index', ['scope' => 'own']));
        $response->assertOk();
        $this->assertSame(1, $response->viewData('counts')['fica_waiting_applicant']);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.index', ['scope' => 'own']));
        $response->assertOk();
        $this->assertSame(0, $response->viewData('counts')['fica_waiting_applicant'], 'not the creating agent — own scope must not see it');
    }
}
