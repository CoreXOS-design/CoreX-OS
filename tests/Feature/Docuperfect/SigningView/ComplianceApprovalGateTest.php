<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\OfficerAppointment;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\EsignApproval;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Notifications\SignatureActivityNotification;
use App\Services\Compliance\OfficerRegistry;
use App\Services\Docuperfect\EsignApprovalService;
use App\Services\Docuperfect\SignatureService;
use Database\Seeders\NotificationEventTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Compliance approval gate — spec .ai/specs/esign-compliance-approval-gate.md §6 / §13.
 *
 * Drives the REAL dispatch point (SignatureService::handlePartyCompletion after the agent signs)
 * and the real sendForSigning() pre-signed branch. Paths proven: route 1 passthrough; route 2 hold
 * (status, ledger, audit, no party invited, officers notified in scope only, sender excluded);
 * approve releases exactly once (idempotent double-approve); decline (reason required, sender
 * told); self-approval blocked + audited, CO exempt; scope 403; CO override; resubmit; candidate
 * document never double-held.
 */
final class ComplianceApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Branch $otherBranch;
    private User $sender;
    private User $ro;
    private User $co;
    private User $otherBranchRo;
    private OfficerRegistry $registry;
    private EsignApprovalService $gate;
    private SignatureService $signatures;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->seed(NotificationEventTypeSeeder::class);

        $this->agency      = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch      = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->otherBranch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Scottburgh']);

        $this->sender        = $this->user('Retha Botha',   'agent',          'Property Practitioner', $this->branch);
        $this->ro            = $this->user('Elize Nel',     'branch_manager', 'Property Practitioner', $this->branch);
        $this->co            = $this->user('Johan Reichel', 'admin',          'Principal Property Practitioner', $this->branch);
        $this->otherBranchRo = $this->user('Dalene du Toit','branch_manager', 'Property Practitioner', $this->otherBranch);

        $this->registry   = app(OfficerRegistry::class);
        $this->gate       = app(EsignApprovalService::class);
        $this->signatures = app(SignatureService::class);

        $this->registry->appointCo($this->agency->id, OfficerAppointment::MODULE_ESIGN, $this->co->id, $this->co->id);
        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_ESIGN, [$this->ro->id, $this->otherBranchRo->id], $this->co->id);
    }

    private function user(string $name, string $role, string $designation, Branch $branch): User
    {
        return User::factory()->create([
            'name' => $name, 'role' => $role, 'designation' => $designation,
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'is_active' => true,
        ]);
    }

    private function routeTwo(): void
    {
        $this->registry->setEsignRoute($this->agency->id, OfficerRegistry::ESIGN_ROUTE_RO_CO);
    }

    /** A signed-by-agent ceremony with one seller still waiting — the moment before release. */
    private function ceremony(?User $creator = null, bool $candidate = false): array
    {
        $creator = $creator ?? $this->sender;
        $doc = Document::create(['name' => 'Sole Mandate — 12 Marine Drive, Margate', 'owner_id' => $creator->id, 'agency_id' => $this->agency->id, 'branch_id' => $creator->branch_id]);
        $tpl = SignatureTemplate::create([
            'document_id'       => $doc->id,
            'document_hash'     => Str::random(64),
            'status'            => SignatureTemplate::STATUS_SIGNING,
            'created_by'        => $creator->id,
            'is_candidate_flow' => $candidate,
            'parties_json'      => [['role' => 'agent'], ['role' => 'seller']],
            'signing_order_json'=> $candidate ? ['agent', 'supervisor', 'seller'] : ['agent', 'seller'],
        ]);
        $agent = SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1, 'signing_order' => 1,
            'signer_name' => $creator->name, 'signer_email' => $creator->email, 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14), 'status' => SignatureRequest::STATUS_COMPLETED, 'completed_at' => now(),
        ]);
        $order = 2;
        if ($candidate) {
            SignatureRequest::create([
                'signature_template_id' => $tpl->id, 'party_role' => 'supervisor', 'role_index' => 1, 'signing_order' => $order++,
                'signer_name' => 'Authorised Practitioner', 'signer_email' => '', 'token' => Str::random(48),
                'token_expires_at' => now()->addDays(14), 'status' => SignatureRequest::STATUS_WAITING,
            ]);
        }
        $seller = SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1, 'signing_order' => $order,
            'signer_name' => 'Nomsa Dlamini', 'signer_email' => 'nomsa.dlamini@example.co.za', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14), 'status' => SignatureRequest::STATUS_WAITING,
        ]);

        return [$tpl->fresh(), $agent, $seller];
    }

    // ── Route 1 — byte-for-byte today ──

    public function test_route_one_releases_straight_to_the_first_party_and_never_holds(): void
    {
        [$tpl, $agent, $seller] = $this->ceremony();

        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);

        $this->assertNotSame(SignatureTemplate::STATUS_APPROVAL_PENDING, $tpl->fresh()->status);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller->fresh()->status, 'seller is invited on route 1');
        $this->assertSame(0, EsignApproval::withoutGlobalScopes()->count(), 'no ledger row on route 1');
    }

    // ── Route 2 — the hold ──

    public function test_route_two_holds_the_document_before_it_leaves_the_agency(): void
    {
        $this->routeTwo();
        [$tpl, $agent, $seller] = $this->ceremony();

        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);

        $tpl->refresh();
        $this->assertSame(SignatureTemplate::STATUS_APPROVAL_PENDING, $tpl->status);
        $this->assertSame(SignatureRequest::STATUS_WAITING, $seller->fresh()->status, 'the seller is NOT invited while held');
        $this->assertNull($seller->fresh()->sent_at);

        $row = EsignApproval::withoutGlobalScopes()->where('signature_template_id', $tpl->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(EsignApproval::STATUS_PENDING, $row->status);
        $this->assertSame($this->sender->id, $row->requested_by_user_id);
        $this->assertSame($this->branch->id, $row->branch_id);
        $this->assertSame($this->agency->id, $row->agency_id);

        $this->assertDatabaseHas('signature_audit_log', ['signature_template_id' => $tpl->id, 'action' => 'compliance_approval_requested']);

        // Officers in scope are told; the other-branch RO and the sender are not.
        Notification::assertSentTo($this->ro, SignatureActivityNotification::class);
        Notification::assertSentTo($this->co, SignatureActivityNotification::class);
        Notification::assertNotSentTo($this->otherBranchRo, SignatureActivityNotification::class);
        Notification::assertNotSentTo($this->sender, SignatureActivityNotification::class);
    }

    public function test_route_two_also_holds_the_direct_send_path_when_the_agent_pre_signed(): void
    {
        $this->routeTwo();
        [$tpl, $agent, $seller] = $this->ceremony();
        $tpl->update(['status' => SignatureTemplate::STATUS_READY]);

        $this->signatures->sendForSigning($tpl, $this->sender);

        $this->assertSame(SignatureTemplate::STATUS_APPROVAL_PENDING, $tpl->fresh()->status);
        $this->assertSame(SignatureRequest::STATUS_WAITING, $seller->fresh()->status);
    }

    public function test_candidate_document_is_gated_by_the_supervisor_step_not_held_twice(): void
    {
        $this->routeTwo();
        $candidate = $this->user('Cand Idate', 'agent', 'Candidate Property Practitioner', $this->branch);
        [$tpl, $agent, $seller] = $this->ceremony($candidate, candidate: true);

        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);

        $this->assertSame(SignatureTemplate::STATUS_AWAITING_SUPERVISOR, $tpl->fresh()->status, 'the co-signature IS the approval');
        $this->assertSame(0, EsignApproval::withoutGlobalScopes()->count(), 'no second hold for a candidate document');
    }

    // ── Decisions ──

    public function test_ro_approval_releases_to_the_first_party_exactly_once(): void
    {
        $this->routeTwo();
        [$tpl, $agent, $seller] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);
        Notification::fake(); // reset — we only care about the decision alert now

        $approval = $this->gate->approve($tpl->fresh(), $this->ro, 'Looks right.');

        $this->assertSame(EsignApproval::STATUS_APPROVED, $approval->status);
        $this->assertSame($this->ro->id, $approval->decided_by_user_id);
        $this->assertNotSame(SignatureTemplate::STATUS_APPROVAL_PENDING, $tpl->fresh()->status);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller->fresh()->status, 'seller invited on approval');
        $this->assertDatabaseHas('signature_audit_log', ['signature_template_id' => $tpl->id, 'action' => 'compliance_approved']);
        Notification::assertSentTo($this->sender, SignatureActivityNotification::class);

        // Idempotent: a second click on a stale form returns the same decision and invites nobody twice.
        $sentAt = $seller->fresh()->sent_at;
        $again  = $this->gate->approve($tpl->fresh(), $this->ro);
        $this->assertSame($approval->id, $again->id);
        $this->assertEquals($sentAt, $seller->fresh()->sent_at);
        $this->assertSame(1, EsignApproval::withoutGlobalScopes()->where('signature_template_id', $tpl->id)->count());
    }

    public function test_decline_needs_a_reason_and_tells_the_sender(): void
    {
        $this->routeTwo();
        [$tpl, $agent, $seller] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);
        Notification::fake();

        try {
            $this->gate->decline($tpl->fresh(), $this->ro, '  ');
            $this->fail('a blank reason must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }

        $approval = $this->gate->decline($tpl->fresh(), $this->ro, 'Seller ID number missing on page 2.');

        $this->assertSame(EsignApproval::STATUS_DECLINED, $approval->status);
        $this->assertSame(SignatureTemplate::STATUS_APPROVAL_DECLINED, $tpl->fresh()->status);
        $this->assertSame(SignatureRequest::STATUS_WAITING, $seller->fresh()->status, 'still nothing left the agency');
        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $agent->fresh()->status, 'signed stays signed');
        $this->assertDatabaseHas('signature_audit_log', ['signature_template_id' => $tpl->id, 'action' => 'compliance_declined']);
        Notification::assertSentTo($this->sender, SignatureActivityNotification::class);
    }

    public function test_sender_cannot_approve_their_own_document_unless_they_are_the_co(): void
    {
        $this->routeTwo();
        // The sender is ALSO an RO — the common small-agency case.
        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_ESIGN, [$this->ro->id, $this->sender->id], $this->co->id);
        [$tpl, $agent] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);

        try {
            $this->gate->approve($tpl->fresh(), $this->sender);
            $this->fail('self-approval must be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseHas('signature_audit_log', ['signature_template_id' => $tpl->id, 'action' => 'compliance_self_approval_blocked']);
        $this->assertSame(SignatureTemplate::STATUS_APPROVAL_PENDING, $tpl->fresh()->status);

        // The CO sending their own document may approve it (FICA's primary-officer rule).
        [$tpl2, $agent2, $seller2] = $this->ceremony($this->co);
        $this->signatures->handlePartyCompletion($tpl2, 'agent', $agent2);
        $this->gate->approve($tpl2->fresh(), $this->co);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller2->fresh()->status);
    }

    public function test_officer_outside_the_documents_scope_is_refused(): void
    {
        $this->routeTwo();
        [$tpl, $agent] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);

        $this->expectException(HttpException::class);
        $this->gate->approve($tpl->fresh(), $this->otherBranchRo);
    }

    public function test_a_non_officer_is_refused(): void
    {
        $this->routeTwo();
        [$tpl, $agent] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);
        $plainAdmin = $this->user('Plain Admin', 'admin', 'Office Admin', $this->branch);

        $this->expectException(HttpException::class);
        $this->gate->approve($tpl->fresh(), $plainAdmin);
    }

    public function test_co_can_override_a_decline_with_a_reason_and_an_ro_cannot(): void
    {
        $this->routeTwo();
        [$tpl, $agent, $seller] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);
        $this->gate->decline($tpl->fresh(), $this->ro, 'Not happy with clause 4.');

        try {
            $this->gate->override($tpl->fresh(), $this->ro, 'I changed my mind.');
            $this->fail('an RO may not override');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $override = $this->gate->override($tpl->fresh(), $this->co, 'Clause 4 is standard wording; releasing.');

        $this->assertTrue($override->is_override);
        $this->assertSame(EsignApproval::STATUS_APPROVED, $override->status);
        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller->fresh()->status, 'released by the override');
        $this->assertDatabaseHas('signature_audit_log', ['signature_template_id' => $tpl->id, 'action' => 'compliance_override_approved']);
    }

    public function test_sender_can_ask_again_after_a_decline(): void
    {
        $this->routeTwo();
        [$tpl, $agent] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);
        $this->gate->decline($tpl->fresh(), $this->ro, 'Fix the date.');

        // Only the sender may.
        try {
            $this->gate->resubmit($tpl->fresh(), $this->ro);
            $this->fail('only the sender may resubmit');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $again = $this->gate->resubmit($tpl->fresh(), $this->sender);

        $this->assertSame(EsignApproval::STATUS_PENDING, $again->status);
        $this->assertSame(SignatureTemplate::STATUS_APPROVAL_PENDING, $tpl->fresh()->status);
        $this->assertSame(2, EsignApproval::withoutGlobalScopes()->where('signature_template_id', $tpl->id)->count(), 'a new ledger row, the decline kept');
    }

    public function test_declined_queue_drops_a_decline_once_the_sender_has_asked_again(): void
    {
        $this->routeTwo();
        [$tpl, $agent] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);
        $this->gate->decline($tpl->fresh(), $this->ro, 'Fix the date.');

        $declined = fn () => $this->gate->queueQuery($this->co, [EsignApproval::STATUS_DECLINED])->pluck('signature_template_id')->all();
        $pending  = fn () => $this->gate->queueQuery($this->co)->pluck('signature_template_id')->all();

        $this->assertSame([$tpl->id], $declined(), 'a live decline is in the Declined queue');
        $this->assertSame([], $pending());

        $this->gate->resubmit($tpl->fresh(), $this->sender);

        // The decline row is kept for the record, but the document is pending again — it must not
        // sit in the Declined tab with an "Override & send" button that can only be refused.
        $this->assertSame([], $declined(), 'the superseded decline leaves the Declined queue');
        $this->assertSame([$tpl->id], $pending(), 'the document is back in the Waiting queue exactly once');
        $this->assertSame(1, $this->gate->pendingCountFor($this->co));

        // Declined a second time: the first decline is history (superseded), one declined document, listed once.
        $this->gate->decline($tpl->fresh(), $this->ro, 'Still wrong.');
        $this->assertSame(1, EsignApproval::withoutGlobalScopes()->where('signature_template_id', $tpl->id)->where('status', EsignApproval::STATUS_DECLINED)->count());
        $this->assertSame(1, EsignApproval::withoutGlobalScopes()->where('signature_template_id', $tpl->id)->where('status', EsignApproval::STATUS_SUPERSEDED)->count());
        $this->assertSame([$tpl->id], $declined(), 'the document appears once in the Declined queue, not once per decline');
        $this->assertSame([], $pending());

        // The CO's override closes the decline too.
        $this->gate->override($tpl->fresh(), $this->co, 'Releasing.');
        $this->assertSame(0, EsignApproval::withoutGlobalScopes()->where('signature_template_id', $tpl->id)->whereIn('status', EsignApproval::OPEN_STATUSES)->count(), 'nothing open after an override');
    }

    // ── Cancelled while held: nothing left to decide ──

    public function test_cancelling_a_held_document_closes_the_ledger_and_refuses_stale_decisions(): void
    {
        $this->routeTwo();
        [$tpl, $agent, $seller] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);
        $this->assertSame(1, $this->gate->pendingCountFor($this->ro));

        // The sender cancels (the controller's cancel path calls exactly this inside its transaction).
        $withdrawn = $this->gate->withdraw($tpl->fresh(), $this->sender, 'Client walked away.');
        $tpl->update(['status' => SignatureTemplate::STATUS_CANCELLED]);

        $this->assertSame(1, $withdrawn);
        $this->assertSame(EsignApproval::STATUS_WITHDRAWN, EsignApproval::withoutGlobalScopes()->where('signature_template_id', $tpl->id)->value('status'));
        $this->assertDatabaseHas('signature_audit_log', ['signature_template_id' => $tpl->id, 'action' => 'compliance_approval_withdrawn']);
        $this->assertSame(0, $this->gate->pendingCountFor($this->ro), 'gone from the queue and the badge');

        // A stale Approve / Decline from a form opened before the cancel is refused in plain words.
        foreach (['approve', 'decline'] as $stale) {
            try {
                $stale === 'approve'
                    ? $this->gate->approve($tpl->fresh(), $this->ro)
                    : $this->gate->decline($tpl->fresh(), $this->ro, 'Too late.');
                $this->fail("a stale {$stale} must not act on a cancelled document");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('not waiting for approval', $e->errors()['approval'][0]);
            }
        }
        $this->assertSame(SignatureTemplate::STATUS_CANCELLED, $tpl->fresh()->status, 'the cancelled document was not resurrected');
        $this->assertSame(SignatureRequest::STATUS_WAITING, $seller->fresh()->status, 'and nobody was invited');
    }

    public function test_a_decision_needs_the_document_itself_to_still_be_held(): void
    {
        $this->routeTwo();
        [$tpl, $agent, $seller] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);

        // The ledger row is still pending but the document moved on without going through the gate
        // (any path that changes status behind the officers' backs) — the officer is told, nothing fires.
        $tpl->update(['status' => SignatureTemplate::STATUS_CANCELLED]);

        try {
            $this->gate->approve($tpl->fresh(), $this->co);
            $this->fail('approve must check the document, not only the ledger row');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cancelled', $e->errors()['approval'][0]);
        }
        $this->assertSame(EsignApproval::STATUS_PENDING, EsignApproval::withoutGlobalScopes()->where('signature_template_id', $tpl->id)->value('status'), 'row untouched');
        $this->assertSame(SignatureRequest::STATUS_WAITING, $seller->fresh()->status);
        $this->assertDatabaseMissing('signature_audit_log', ['signature_template_id' => $tpl->id, 'action' => 'compliance_approved']);
    }

    // ── No path round the gate ──

    public function test_resend_cannot_deliver_the_signing_link_while_held(): void
    {
        $this->routeTwo();
        [$tpl, $agent, $seller] = $this->ceremony();
        $this->signatures->handlePartyCompletion($tpl, 'agent', $agent);

        try {
            $this->signatures->resendInvitationEmail($seller->fresh());
            $this->fail('resend is a send; it must respect the hold');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('waiting for compliance approval', $e->getMessage());
        }
        $this->assertSame(SignatureRequest::STATUS_WAITING, $seller->fresh()->status);
        $this->assertNull($seller->fresh()->sent_at);
        Mail::assertNothingSent();
    }

    // ── Queue scope (ruling 5) ──

    public function test_queue_and_badge_follow_own_branch_all(): void
    {
        $this->routeTwo();
        [$tplA, $agentA] = $this->ceremony();                                                          // Margate
        $otherSender = $this->user('Sipho Zulu', 'agent', 'Property Practitioner', $this->otherBranch);
        [$tplB, $agentB] = $this->ceremony($otherSender);                                              // Scottburgh
        $this->signatures->handlePartyCompletion($tplA, 'agent', $agentA);
        $this->signatures->handlePartyCompletion($tplB, 'agent', $agentB);

        $this->assertSame(1, $this->gate->pendingCountFor($this->ro), 'branch-manager RO sees the branch');
        $this->assertSame(1, $this->gate->pendingCountFor($this->otherBranchRo));
        $this->assertSame(2, $this->gate->pendingCountFor($this->co), 'admin CO sees the agency');

        $agentRo = $this->user('Agent RO', 'agent', 'Property Practitioner', $this->branch);
        $this->registry->saveRos($this->agency->id, OfficerAppointment::MODULE_ESIGN, [$this->ro->id, $this->otherBranchRo->id, $agentRo->id], $this->co->id);
        $this->assertSame(0, $this->gate->pendingCountFor($agentRo), 'an agent-scoped RO sees only their own documents');
        $this->assertSame(0, $this->gate->pendingCountFor($this->sender), 'a non-officer sees nothing');
    }
}
