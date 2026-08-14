<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\Candidate;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Candidate-flow rework — SINGLE authoriser co-sign. With supervisor_final removed, once all external
 * parties have signed the CANDIDATE's own final approval completes the document (approveAndAdvance
 * falls through to completeDocument when no supervisor_final request exists).
 *
 * Backward-compat: an in-flight document that STILL carries a supervisor_final request finishes its
 * old flow (routes to AWAITING_SUPERVISOR_FINAL, does not skip straight to completed).
 */
final class CandidateFlowCompletionTest extends TestCase
{
    use RefreshDatabase;

    private SignatureService $svc;
    private User $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->svc = app(SignatureService::class);

        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $this->candidate = User::factory()->create([
            'name' => 'Cand', 'role' => 'agent', 'designation' => 'Candidate Property Practitioner',
            'branch_id' => $branch->id, 'agency_id' => $agency->id, 'is_active' => true,
        ]);
        // A branch authoriser so getEligibleAuthorisers() (used by the backward-compat route) resolves.
        User::factory()->create([
            'name' => 'Full', 'role' => 'agent', 'designation' => 'Property Practitioner',
            'branch_id' => $branch->id, 'agency_id' => $agency->id, 'is_active' => true,
        ]);
    }

    private function candidateTemplate(): SignatureTemplate
    {
        $document = Document::create([
            'name' => 'Candidate Doc', 'document_type' => 'agreement',
            'owner_id' => $this->candidate->id,
            'web_template_data' => ['merged_html' => ''], // no marks → parity guard has nothing to flag
        ]);
        return SignatureTemplate::create([
            'document_id'   => $document->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
            'created_by'    => $this->candidate->id,
            'is_candidate_flow' => true,
        ]);
    }

    private function addRequest(SignatureTemplate $t, string $role, string $status): SignatureRequest
    {
        $r = $this->svc->createSigningRequest(
            template: $t, partyRole: $role,
            signerName: ucfirst($role), signerEmail: $role . '@x.test', roleIndex: 1,
        );
        $r->update(['status' => $status, 'completed_at' => $status === SignatureRequest::STATUS_COMPLETED ? now() : null]);
        return $r->fresh();
    }

    public function test_candidate_final_approval_completes_when_no_supervisor_final(): void
    {
        $t = $this->candidateTemplate();
        // candidate signed (agent), authoriser co-signed (supervisor), external party signed (seller) — all done.
        $this->addRequest($t, 'agent', SignatureRequest::STATUS_COMPLETED);
        $this->addRequest($t, 'supervisor', SignatureRequest::STATUS_COMPLETED);
        $this->addRequest($t, 'seller', SignatureRequest::STATUS_COMPLETED);

        $result = $this->svc->approveAndAdvance($t);
        $t->refresh();

        $this->assertSame('completed', $result['action'] ?? null, 'no supervisor_final → candidate approval completes');
        $this->assertSame(SignatureTemplate::STATUS_COMPLETED, $t->status);
    }

    public function test_backward_compat_inflight_supervisor_final_still_routes(): void
    {
        $t = $this->candidateTemplate();
        $this->addRequest($t, 'agent', SignatureRequest::STATUS_COMPLETED);
        $this->addRequest($t, 'supervisor', SignatureRequest::STATUS_COMPLETED);
        $this->addRequest($t, 'seller', SignatureRequest::STATUS_COMPLETED);
        // An in-flight doc created under the OLD flow still carries a supervisor_final (pending).
        $this->addRequest($t, 'supervisor_final', SignatureRequest::STATUS_PENDING);

        $result = $this->svc->approveAndAdvance($t);
        $t->refresh();

        $this->assertNotSame(SignatureTemplate::STATUS_COMPLETED, $t->status, 'must not skip its supervisor_final');
        $this->assertSame(SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL, $t->status, 'old flow still routes to supervisor_final');
    }
}
