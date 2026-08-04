<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Notifications\SignatureActivityNotification;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Candidate ⇄ authoriser return loop (Johan 2026-08-04, LOCKED).
 *
 * Junior signs → senior reviews → SEND BACK (with note) → doc UNLOCKS for the junior
 * (editable/draft, agent request re-signable) → junior re-signs → RESUBMIT re-enters the
 * authorisation queue. Notes accumulate as a running thread; every hop writes audit.
 * Pre-external only. The senior never edits — only authorise or bounce.
 */
final class CandidateReturnLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_back_unlocks_junior_threads_the_note_and_notifies(): void
    {
        Notification::fake();
        [$template, $junior, $senior] = $this->seedCandidateAtSupervisorReview();

        app(SignatureService::class)->returnToCandidate($template, 'Fix the purchase price on page 2.', $senior);

        $template->refresh();
        $this->assertSame(SignatureTemplate::STATUS_RETURNED_TO_CANDIDATE, $template->status);

        // Junior UNLOCKED — agent request re-signable (not completed).
        $agentReq = $template->requests()->where('party_role', 'agent')->first();
        $this->assertNotSame(SignatureRequest::STATUS_COMPLETED, $agentReq->status, 'junior must be unlocked to re-sign');
        $this->assertNull($agentReq->completed_at);
        $this->assertSame('Fix the purchase price on page 2.', $agentReq->returned_notes);

        // Authoriser request reset to WAITING so the resubmit re-routes to the queue (not to recipients).
        $supReq = $template->requests()->where('party_role', 'supervisor')->first();
        $this->assertSame(SignatureRequest::STATUS_WAITING, $supReq->status);

        // Running thread — one send-back, round 1, note preserved.
        $thread = $template->document->fresh()->web_template_data['return_thread'] ?? [];
        $this->assertCount(1, $thread);
        $this->assertSame('sent_back', $thread[0]['direction']);
        $this->assertSame(1, $thread[0]['round']);
        $this->assertSame('Fix the purchase price on page 2.', $thread[0]['note']);

        // Audit + in-app notification to the junior.
        $this->assertDatabaseHas('signature_audit_logs', [
            'signature_template_id' => $template->id,
            'action' => 'supervisor_returned_to_candidate',
        ]);
        Notification::assertSentTo($junior, SignatureActivityNotification::class);
    }

    public function test_resubmit_returns_to_supervisor_appends_thread_and_audits(): void
    {
        Notification::fake();
        [$template, $junior, $senior] = $this->seedCandidateAtSupervisorReview();

        // Round 1: send back, then the junior re-signs (handlePartyCompletion drives the resubmit).
        $svc = app(SignatureService::class);
        $svc->returnToCandidate($template, 'Wrong occupation date.', $senior);
        $template->refresh();

        $agentReq = $template->requests()->where('party_role', 'agent')->first();
        $svc->handlePartyCompletion($template, 'agent', $agentReq);

        $template->refresh();
        $this->assertSame(SignatureTemplate::STATUS_AWAITING_SUPERVISOR, $template->status, 'resubmit must re-enter the authoriser queue, never skip to recipients');

        $thread = $template->document->fresh()->web_template_data['return_thread'] ?? [];
        $directions = array_column($thread, 'direction');
        $this->assertSame(['sent_back', 'resubmitted'], $directions);

        $this->assertDatabaseHas('signature_audit_logs', [
            'signature_template_id' => $template->id,
            'action' => 'candidate_resubmitted_to_authoriser',
        ]);
    }

    // ── Helpers ──

    /**
     * @return array{0: SignatureTemplate, 1: User, 2: User}
     */
    private function seedCandidateAtSupervisorReview(): array
    {
        $juniorId = (int) DB::table('users')->insertGetId([
            'name' => 'Junior Candidate', 'email' => 'jnr-' . Str::random(6) . '@hfc.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $seniorId = (int) DB::table('users')->insertGetId([
            'name' => 'Senior Principal', 'email' => 'snr-' . Str::random(6) . '@hfc.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Candidate mandate', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'rentals', 'signing_parties' => ['agent', 'owner_party'],
            'field_mappings' => [], 'owner_id' => $juniorId,
        ]);
        $doc = Document::create([
            'name' => 'Candidate Doc', 'document_type' => 'mandate', 'owner_id' => $juniorId,
            'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => '<div>body</div>', 'canonical_version' => 0],
        ]);
        $template = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
            'created_by' => $juniorId, 'is_candidate_flow' => true,
        ]);

        // Junior signed first (order 1, completed); authoriser waiting to review (order 2, pending).
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Junior Candidate', 'signer_email' => 'jnr@x.test',
            'token' => Str::random(48), 'status' => SignatureRequest::STATUS_COMPLETED,
            'completed_at' => now(), 'signing_order' => 1,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'supervisor', 'role_index' => 1,
            'signer_name' => 'Senior Principal', 'signer_email' => 'snr@x.test',
            'token' => Str::random(48), 'status' => SignatureRequest::STATUS_PENDING,
            'signing_order' => 2,
        ]);

        return [$template, User::findOrFail($juniorId), User::findOrFail($seniorId)];
    }
}
