<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Contact;
use App\Models\FicaStatusHistory;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Services\CommandCenter\CommandCentreService;
use App\Services\Compliance\FicaReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-236 — FICA multi-officer approval workflow.
 *
 * Proves Johan's composed rules: (1) SELF-APPROVAL SEPARATION — a secondary officer
 * cannot approve their own FICA (only the primary CO may), enforced in the gate and
 * audited; (2) REFER-TO-CO — a reviewer refers with a mandatory reason → referred_to_co
 * + durable audit; the CO returns it to the referrer; (3) the two quick-link queues are
 * scoped correctly (CO queue = agent_approved + referred, CO-only; reviewer queue = own).
 */
final class FicaMultiOfficerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $primaryCo;
    private User $mlro;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'FICA Co ' . Str::random(5), 'slug' => 'fica-' . Str::random(8),
            'fica_referral_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // super_admin role → holds access_compliance; appointments make them officers.
        $this->primaryCo = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin']);
        $this->mlro      = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin']);
        $this->agent     = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent']);

        $this->appoint($this->primaryCo, FicaOfficerAppointment::ROLE_PRIMARY);
        $this->appoint($this->mlro, FicaOfficerAppointment::ROLE_MLRO);
    }

    private function appoint(User $u, string $role): void
    {
        FicaOfficerAppointment::create([
            'agency_id' => $this->agencyId, 'branch_id' => null, 'user_id' => $u->id,
            'role' => $role, 'full_name' => $u->name, 'appointed_on' => now()->toDateString(),
            'appointed_by' => $u->id,
        ]);
    }

    private function submission(int $requestedBy, string $status = 'agent_approved', ?int $agentVerifiedBy = null): FicaSubmission
    {
        $contact = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'first_name' => 'Thandi', 'last_name' => 'Mkhize', 'created_by_user_id' => $requestedBy,
        ]));

        return FicaSubmission::withoutEvents(fn () => FicaSubmission::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_id' => $contact->id, 'requested_by' => $requestedBy,
            'agent_verified_by' => $agentVerifiedBy ?? $requestedBy,
            'agent_verified_at' => now(), 'status' => $status,
        ]));
    }

    // ── 1. Self-approval separation ───────────────────────────────────────

    public function test_secondary_officer_cannot_approve_their_own_fica_and_the_block_is_audited(): void
    {
        // The MLRO both requested and agent-approved this pack — it is their own work.
        $sub = $this->submission($this->mlro->id);

        $resp = $this->actingAs($this->mlro)
            ->post(route('compliance.fica.compliance-approve', $sub), []); // guard fires before validation

        $resp->assertRedirect(route('compliance.fica.compliance-review', $sub));
        $resp->assertSessionHasErrors('approve');

        $this->assertSame('agent_approved', $sub->fresh()->status, 'status unchanged — the approval was blocked');
        $this->assertDatabaseHas('fica_status_history', [
            'fica_submission_id' => $sub->id,
            'action'             => 'self_approval_blocked',
            'actor_user_id'      => $this->mlro->id,
            'actor_tier'         => FicaOfficerAppointment::ROLE_MLRO,
        ]);
    }

    public function test_primary_co_may_self_approve_secondary_may_approve_anothers_work(): void
    {
        // The composed rule at the decision boundary (isSelfApproval ∧ ¬primary).
        $ownPack   = $this->submission($this->mlro->id);       // mlro's own
        $otherPack = $this->submission($this->agent->id);      // someone else's

        $isSelf = new ReflectionMethod(\App\Http\Controllers\Compliance\FicaController::class, 'isSelfApproval');
        $isSelf->setAccessible(true);
        $ctrl = app(\App\Http\Controllers\Compliance\FicaController::class);

        // MLRO on their OWN pack → self ∧ not-primary → BLOCKED.
        $this->assertTrue($isSelf->invoke($ctrl, $ownPack, $this->mlro));
        $this->assertFalse($this->mlro->isPrimaryComplianceOfficer($this->agencyId));

        // Primary CO on their OWN pack → self, BUT primary → ALLOWED.
        $primaryOwn = $this->submission($this->primaryCo->id);
        $this->assertTrue($isSelf->invoke($ctrl, $primaryOwn, $this->primaryCo));
        $this->assertTrue($this->primaryCo->isPrimaryComplianceOfficer($this->agencyId));

        // MLRO on ANOTHER agent's pack → not self → ALLOWED.
        $this->assertFalse($isSelf->invoke($ctrl, $otherPack, $this->mlro));
    }

    // ── 2. Refer-to-CO round trip ─────────────────────────────────────────

    public function test_refer_requires_a_reason(): void
    {
        $sub = $this->submission($this->agent->id, 'submitted');

        $this->actingAs($this->agent)
            ->post(route('compliance.fica.refer-to-co', $sub), ['referral_note' => ''])
            ->assertSessionHasErrors('referral_note');

        $this->assertSame('submitted', $sub->fresh()->status);
    }

    public function test_refer_transitions_to_referred_to_co_and_audits_the_hop(): void
    {
        $sub = $this->submission($this->agent->id, 'submitted');

        $this->actingAs($this->agent)
            ->post(route('compliance.fica.refer-to-co', $sub), ['referral_note' => 'Unusual entity structure — needs CO sign-off.'])
            ->assertRedirect(route('compliance.fica.show', $sub));

        $fresh = $sub->fresh();
        $this->assertSame('referred_to_co', $fresh->status);
        $this->assertSame($this->agent->id, (int) $fresh->referred_by);
        $this->assertNotNull($fresh->referred_at);
        $this->assertStringContainsString('Unusual entity', (string) $fresh->referral_note);

        $this->assertDatabaseHas('fica_status_history', [
            'fica_submission_id' => $sub->id, 'action' => 'referred_to_co',
            'from_status' => 'submitted', 'to_status' => 'referred_to_co',
            'actor_user_id' => $this->agent->id,
        ]);
    }

    public function test_co_returns_a_referred_pack_to_the_referrer_with_comments(): void
    {
        $sub = $this->submission($this->agent->id, 'referred_to_co');
        $sub->update(['referred_by' => $this->agent->id, 'referred_at' => now(), 'referral_note' => 'please check']);

        $this->actingAs($this->primaryCo)
            ->post(route('compliance.fica.return-to-referrer', $sub), ['reviewer_notes' => 'Verified — proceed, no CO decision needed.'])
            ->assertRedirect(route('compliance.fica.index', ['tab' => 'referred_to_co']));

        $this->assertSame('corrections_requested', $sub->fresh()->status);
        $this->assertDatabaseHas('fica_status_history', [
            'fica_submission_id' => $sub->id, 'action' => 'co_returned_to_referrer',
            'actor_user_id' => $this->primaryCo->id,
        ]);
    }

    public function test_referral_recipient_resolves_to_primary_co(): void
    {
        $recipient = app(FicaReferralService::class)->resolveRecipient($this->agencyId);
        $this->assertNotNull($recipient);
        $this->assertSame($this->primaryCo->id, $recipient->id);
    }

    // ── 3. Quick-link queue scoping ───────────────────────────────────────

    public function test_ro_approvals_card_counts_agent_approved_for_any_authorized_reviewer(): void
    {
        // The shared review pool (agent_approved), NOT scoped to who created it.
        $this->submission($this->agent->id, 'agent_approved');
        $this->submission($this->primaryCo->id, 'agent_approved');
        $this->submission($this->agent->id, 'referred_to_co'); // escalated → NOT in RO Approvals
        $this->submission($this->agent->id, 'submitted');      // agent stage → NOT in RO Approvals

        $svc = app(CommandCentreService::class);
        $m = new ReflectionMethod($svc, 'ficaRoApprovals');
        $m->setAccessible(true);

        // Both an RO (Falan/mlro) and the primary CO (Elize) see the pool: 2.
        $this->assertSame(2, $m->invoke($svc, $this->mlro, $this->agencyId)['count']);
        $this->assertSame(2, $m->invoke($svc, $this->primaryCo, $this->agencyId)['count']);

        // A plain agent (no FICA appointment) is not a reviewer → count 0, card skipped.
        $this->assertSame(0, $m->invoke($svc, $this->agent, $this->agencyId)['count']);
    }

    public function test_co_approvals_needed_card_counts_referred_and_is_primary_co_only(): void
    {
        $this->submission($this->agent->id, 'referred_to_co');
        $this->submission($this->agent->id, 'referred_to_co');
        $this->submission($this->agent->id, 'agent_approved'); // not escalated → not here

        $svc = app(CommandCentreService::class);
        $m = new ReflectionMethod($svc, 'ficaCoApprovalsNeeded');
        $m->setAccessible(true);

        // The primary CO (Elize) sees the escalations: 2.
        $this->assertSame(2, $m->invoke($svc, $this->primaryCo, $this->agencyId)['count']);
        // An RO (Falan/mlro) is NOT the primary CO → the CO station is not theirs: 0.
        $this->assertSame(0, $m->invoke($svc, $this->mlro, $this->agencyId)['count']);
    }
}
