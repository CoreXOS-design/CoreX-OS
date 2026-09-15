<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-392 — Johan, live on QA1: "on testing rental applications the uploaded
 * doc do not pull back with the rental application." Traced end to end on
 * real QA1 data: the file landed correctly, the documents row existed and
 * was correctly linked, and show() rendered it correctly — the actual gap
 * was that RentalApplicationSigningController::uploadDocuments() only ever
 * advances status sent -> in_progress (never all the way to 'returned',
 * which requires the full sign-both-declarations submit), while
 * RentalApplicationController::returned()'s status filter excluded
 * 'in_progress' entirely. An applicant who uploaded a real document
 * without finishing the signature flow was invisible on the ONE screen
 * named for reviewing incoming applicant activity — not because the
 * document was broken, but because the application never surfaced there.
 *
 * This is why cc2's QA sweep passed (it almost certainly exercised the
 * linear happy path: full submit, both signatures, THEN a document — which
 * lands in 'returned' and always showed correctly) while Johan's real,
 * non-linear usage (upload before finishing signatures) hit the gap.
 *
 * Drives the REAL public upload route (a real multipart file, through
 * RentalApplicationSigningController::uploadDocuments()) rather than
 * synthesising the DB row — this is squarely cc4's lane's endpoint, used
 * here strictly as a black-box integration point, never edited.
 */
final class RentalApplicationDocumentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private RentalApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
        $this->application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
    }

    public function test_a_document_uploaded_without_finishing_signatures_is_visible_to_the_agent(): void
    {
        $file = UploadedFile::fake()->create('id-document.pdf', 200, 'application/pdf');

        $uploadResponse = $this->post(
            route('rental-applications.public.documents', $this->application->token),
            ['supporting_files' => [$file]]
        );
        $uploadResponse->assertRedirect();

        $this->application->refresh();
        $this->assertSame('in_progress', $this->application->status, 'A document-only upload must advance status to in_progress, never all the way to returned.');
        $this->assertSame(1, $this->application->documents()->count());

        // The regression this test guards: an in_progress application with a
        // real uploaded document must be discoverable on Returned
        // Applications, not just on the plain index.
        $returnedResponse = $this->actingAs($this->agent)->get(route('corex.rental-applications.returned'));
        $returnedResponse->assertOk();
        $returnedResponse->assertSee($this->application->contact->full_name);

        // And it must still appear on the main index too — nothing an agent
        // currently relies on seeing there should disappear as a side effect.
        $this->actingAs($this->agent)->get(route('corex.rental-applications.index'))
            ->assertOk()->assertSee($this->application->contact->full_name);

        // The document itself renders and downloads correctly on show().
        $showResponse = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $this->application));
        $showResponse->assertOk();
        $showResponse->assertSee('id-document.pdf');

        $document = $this->application->documents()->first();
        $this->actingAs($this->agent)
            ->get(route('corex.rental-applications.documents.download', [$this->application, $document]))
            ->assertOk();
    }

    public function test_scoping_still_holds_on_an_in_progress_application_with_a_document(): void
    {
        $file = UploadedFile::fake()->create('id-document.pdf', 200, 'application/pdf');
        $this->post(route('rental-applications.public.documents', $this->application->token), ['supporting_files' => [$file]]);
        $document = $this->application->refresh()->documents()->first();

        $otherAgentSameBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $otherAgentSameBranch2 = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $agentOtherBranch = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $otherBranch->id, 'role' => 'agent']);
        $otherAgency = Agency::create(['name' => 'A Different Agency', 'slug' => 'other-' . uniqid()]);
        $otherAgencyBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'HQ']);
        $otherAgencyAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherAgencyBranch->id, 'role' => 'admin']);

        // An agent who did not create this application (own scope, unrelated
        // agent) is refused on the document download.
        $this->actingAs($otherAgentSameBranch2)
            ->get(route('corex.rental-applications.documents.download', [$this->application, $document]))
            ->assertStatus(403);

        // A different branch, same agency: refused.
        $this->actingAs($agentOtherBranch)
            ->get(route('corex.rental-applications.documents.download', [$this->application, $document]))
            ->assertStatus(403);
        $this->actingAs($agentOtherBranch)
            ->get(route('corex.rental-applications.pdf', $this->application))
            ->assertStatus(403);

        // A different agency entirely: 404, not 403 (route-model-binding never resolves it).
        $this->actingAs($otherAgencyAdmin)
            ->get(route('corex.rental-applications.documents.download', [$this->application, $document]))
            ->assertStatus(404);
        $this->actingAs($otherAgencyAdmin)
            ->get(route('corex.rental-applications.pdf', $this->application))
            ->assertStatus(404);

        // The owning agent (admin, all scope in this fixture) still gets it.
        $this->actingAs($this->agent)
            ->get(route('corex.rental-applications.documents.download', [$this->application, $document]))
            ->assertOk();
    }
}
