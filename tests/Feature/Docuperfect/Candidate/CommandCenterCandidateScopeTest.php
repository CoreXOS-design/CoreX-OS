<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Candidate;

use App\Http\Controllers\CommandCenter\DashboardController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item-5 dashboard port (ad83e04e) — the LIVE CommandCenter dashboard is the authoriser surface.
 * @index must scope BOTH candidate queries per viewer (agency admins agency-wide; BM/full-status
 * their branch), and it must build $candidateInProgressDocs for the read-only "View" cards.
 *
 * KEY assertion: an agency-A admin never sees agency-B candidate documents (the tenancy fix that
 * was previously only on the dead CoreX\DashboardController).
 */
final class CommandCenterCandidateScopeTest extends TestCase
{
    use RefreshDatabase;

    private function candidate(Branch $branch, Agency $agency): User
    {
        return User::factory()->create([
            'role' => 'agent', 'designation' => 'Candidate Property Practitioner',
            'branch_id' => $branch->id, 'agency_id' => $agency->id, 'is_active' => true,
        ]);
    }

    private function candidateDoc(User $creator, string $status): SignatureTemplate
    {
        $doc = Document::create([
            'name' => 'Doc', 'document_type' => 'agreement', 'owner_id' => $creator->id,
            'web_template_data' => ['merged_html' => ''],
        ]);
        return SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => $status, 'created_by' => $creator->id, 'is_candidate_flow' => true,
        ]);
    }

    /** @return array{candidateDocs:\Illuminate\Support\Collection, inProgress:\Illuminate\Support\Collection} */
    private function dashboardFor(User $viewer): array
    {
        $req = Request::create('/legacy-dashboard', 'GET');
        $req->setUserResolver(fn () => $viewer);
        $data = app(DashboardController::class)->index($req)->getData();
        return [
            'candidateDocs' => collect($data['candidateDocs'] ?? []),
            'inProgress'    => collect($data['candidateInProgressDocs'] ?? []),
        ];
    }

    public function test_agency_admin_does_not_see_other_agency_candidate_docs(): void
    {
        $aA = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $bA = Branch::create(['agency_id' => $aA->id, 'name' => 'Margate']);
        $candA = $this->candidate($bA, $aA);
        $adminA = User::factory()->create(['role' => 'admin', 'branch_id' => $bA->id, 'agency_id' => $aA->id, 'is_active' => true]);
        $needA = $this->candidateDoc($candA, SignatureTemplate::STATUS_AWAITING_SUPERVISOR);
        $progA = $this->candidateDoc($candA, SignatureTemplate::STATUS_AWAITING_SELLER);

        $aB = Agency::create(['name' => 'Rival', 'slug' => 'rival']);
        $bB = Branch::create(['agency_id' => $aB->id, 'name' => 'Foreign']);
        $candB = $this->candidate($bB, $aB);
        $needB = $this->candidateDoc($candB, SignatureTemplate::STATUS_AWAITING_SUPERVISOR);
        $progB = $this->candidateDoc($candB, SignatureTemplate::STATUS_AWAITING_SELLER);

        $d = $this->dashboardFor($adminA);

        $this->assertTrue($d['candidateDocs']->pluck('id')->contains($needA->id), 'admin sees own-agency needs-auth');
        $this->assertFalse($d['candidateDocs']->pluck('id')->contains($needB->id), 'admin must NOT see other-agency needs-auth (tenancy)');
        $this->assertTrue($d['inProgress']->pluck('id')->contains($progA->id), 'admin sees own-agency in-progress');
        $this->assertFalse($d['inProgress']->pluck('id')->contains($progB->id), 'admin must NOT see other-agency in-progress (tenancy)');
    }

    public function test_branch_full_status_sees_only_their_branch(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $b1 = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $b2 = Branch::create(['agency_id' => $agency->id, 'name' => 'Scottburgh']);
        $cand1 = $this->candidate($b1, $agency);
        $cand2 = $this->candidate($b2, $agency);
        $full1 = User::factory()->create(['role' => 'agent', 'designation' => 'Property Practitioner', 'branch_id' => $b1->id, 'agency_id' => $agency->id, 'is_active' => true]);

        $prog1 = $this->candidateDoc($cand1, SignatureTemplate::STATUS_AWAITING_SELLER);
        $prog2 = $this->candidateDoc($cand2, SignatureTemplate::STATUS_AWAITING_SELLER);

        $d = $this->dashboardFor($full1);

        $this->assertTrue($d['inProgress']->pluck('id')->contains($prog1->id), 'branch full-status sees own branch');
        $this->assertFalse($d['inProgress']->pluck('id')->contains($prog2->id), 'branch full-status must NOT see another branch');
    }

    public function test_in_progress_excludes_authorisation_queue_and_terminal_statuses(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $cand = $this->candidate($branch, $agency);
        $admin = User::factory()->create(['role' => 'admin', 'branch_id' => $branch->id, 'agency_id' => $agency->id, 'is_active' => true]);

        $inProgress = $this->candidateDoc($cand, SignatureTemplate::STATUS_AWAITING_SELLER);
        $needsAuth  = $this->candidateDoc($cand, SignatureTemplate::STATUS_AWAITING_SUPERVISOR);
        $completed  = $this->candidateDoc($cand, SignatureTemplate::STATUS_COMPLETED);

        $d = $this->dashboardFor($admin);

        $this->assertTrue($d['inProgress']->pluck('id')->contains($inProgress->id));
        $this->assertFalse($d['inProgress']->pluck('id')->contains($needsAuth->id), 'AWAITING_SUPERVISOR belongs to the needs-auth queue, not in-progress');
        $this->assertFalse($d['inProgress']->pluck('id')->contains($completed->id), 'completed is not in-progress');
        $this->assertTrue($d['candidateDocs']->pluck('id')->contains($needsAuth->id), 'AWAITING_SUPERVISOR is in the needs-auth queue');
    }
}
