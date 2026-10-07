<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Http\Controllers\Compliance\FicaController;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Contact;
use App\Models\FicaStatusHistory;
use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Own-FICA separation at the START of the review (Johan, 7 Oct 2026): an officer (RO/MLRO)
 * who may not approve their own FICA is stopped when they OPEN the review / try to mark it
 * up — not after they have done all the work and hit the end-of-flow refusal (AT-236).
 * The primary CO stays the one exception (AT-236's composed rule). The end-of-flow guard in
 * complianceApprove remains as a backstop.
 */
final class FicaOwnApprovalEntryBlockTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'You cannot approve your own FICA - another Responsible Officer or the Compliance Officer must review it';

    private int $agencyId;
    private User $primaryCo;
    private User $ro;       // the officer whose own FICA it is (MLRO = "RO")
    private User $otherRo;  // another officer
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Own FICA Co ' . Str::random(5), 'slug' => 'ownfica-' . Str::random(8),
            'fica_referral_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mk = fn (string $role) => User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => $role]);
        $this->primaryCo = $mk('super_admin');
        $this->ro        = $mk('super_admin');
        $this->otherRo   = $mk('super_admin');
        $this->agent     = $mk('agent');

        $this->appoint($this->primaryCo, FicaOfficerAppointment::ROLE_PRIMARY);
        $this->appoint($this->ro, FicaOfficerAppointment::ROLE_MLRO);
        $this->appoint($this->otherRo, FicaOfficerAppointment::ROLE_MLRO);
    }

    private function appoint(User $u, string $role): void
    {
        FicaOfficerAppointment::create([
            'agency_id' => $this->agencyId, 'branch_id' => null, 'user_id' => $u->id,
            'role' => $role, 'full_name' => $u->name, 'appointed_on' => now()->toDateString(),
            'appointed_by' => $u->id,
        ]);
    }

    private function submission(int $requestedBy, string $status = 'agent_approved', ?int $agentVerifiedBy = null, array $contactExtra = []): FicaSubmission
    {
        $contact = Contact::withoutEvents(fn () => Contact::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'first_name' => 'Thandi', 'last_name' => 'Mkhize', 'created_by_user_id' => $requestedBy,
        ], $contactExtra)));

        return FicaSubmission::withoutEvents(fn () => FicaSubmission::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_id' => $contact->id, 'requested_by' => $requestedBy,
            'agent_verified_by' => $agentVerifiedBy ?? $requestedBy,
            'agent_verified_at' => now(), 'status' => $status,
        ]));
    }

    private function assertBlockedAtEntry($response, FicaSubmission $sub, string $statusBefore): void
    {
        $response->assertRedirect(route('compliance.fica.show', $sub));
        $response->assertSessionHasErrors('own_fica');
        $this->assertStringContainsString(self::REASON, session('errors')->first('own_fica'));
        $this->assertSame($statusBefore, $sub->fresh()->status, 'nothing was saved');
    }

    // ── The review screen refuses at the START ────────────────────────────

    public function test_ro_opening_the_review_screen_of_their_own_fica_is_turned_back(): void
    {
        $sub = $this->submission($this->ro->id);

        $resp = $this->actingAs($this->ro)->get(route('compliance.fica.compliance-review', $sub));

        $this->assertBlockedAtEntry($resp, $sub, 'agent_approved');
        // A plain page view must not write to the audit ledger (a refresh would flood it).
        $this->assertDatabaseMissing('fica_status_history', ['fica_submission_id' => $sub->id, 'action' => 'self_approval_blocked']);
    }

    public function test_another_ro_can_open_the_review_screen_of_that_fica(): void
    {
        $sub = $this->submission($this->ro->id);

        $this->actingAs($this->otherRo)
            ->get(route('compliance.fica.compliance-review', $sub))
            ->assertOk();
    }

    public function test_primary_co_keeps_the_self_approval_exception_and_can_open_their_own(): void
    {
        $sub = $this->submission($this->primaryCo->id);

        $this->actingAs($this->primaryCo)
            ->get(route('compliance.fica.compliance-review', $sub))
            ->assertOk();
    }

    // ── Every save / mark-up endpoint refuses at the START (nothing saved, audited) ──

    /** @return array<string, array{0: string, 1: string, 2: string}> [route, attempt, status the pack is in] */
    public static function markupEndpoints(): array
    {
        return [
            'agent approve'       => ['compliance.fica.agent-approve', 'agent_approve', 'submitted'],
            'request corrections' => ['compliance.fica.request-corrections', 'request_corrections', 'submitted'],
            'reject'              => ['compliance.fica.reject', 'agent_reject', 'submitted'],
            'compliance approve'  => ['compliance.fica.compliance-approve', 'compliance_approve', 'agent_approved'],
            'compliance reject'   => ['compliance.fica.compliance-reject', 'compliance_reject', 'agent_approved'],
            'tfs decision'        => ['compliance.fica.tfs-decision', 'tfs_decision', 'agent_approved'],
            'return to referrer'  => ['compliance.fica.return-to-referrer', 'return_to_referrer', 'referred_to_co'],
            'reopen rejected'     => ['compliance.fica.reopen', 'reopen_rejected', 'rejected'],
        ];
    }

    /**
     * @dataProvider markupEndpoints
     */
    public function test_every_markup_endpoint_refuses_the_ros_own_fica_before_saving_anything(string $route, string $attempt, string $status): void
    {
        Mail::fake();
        $sub = $this->submission($this->ro->id, $status);

        // Empty payload: the refusal must come BEFORE validation, i.e. at the very start.
        $resp = $this->actingAs($this->ro)->post(route($route, $sub), []);

        $this->assertBlockedAtEntry($resp, $sub, $status);
        $this->assertDatabaseHas('fica_status_history', [
            'fica_submission_id' => $sub->id,
            'action'             => 'self_approval_blocked',
            'actor_user_id'      => $this->ro->id,
            'actor_tier'         => FicaOfficerAppointment::ROLE_MLRO,
        ]);
        $row = FicaStatusHistory::where('fica_submission_id', $sub->id)->where('action', 'self_approval_blocked')->first();
        $this->assertSame($attempt, $row->meta['attempt'] ?? null);
        Mail::assertNothingSent();
    }

    public function test_another_ro_is_not_stopped_by_the_entry_check_on_the_same_fica(): void
    {
        $sub = $this->submission($this->ro->id, 'submitted');

        // Validation (not the own-FICA refusal) is what answers them: they got past the entry check.
        $resp = $this->actingAs($this->otherRo)->post(route('compliance.fica.agent-approve', $sub), []);

        $resp->assertSessionDoesntHaveErrors('own_fica');
        $resp->assertSessionHasErrors('risk_rating');
    }

    // ── The old end-of-flow guard stays as a backstop ─────────────────────

    public function test_end_of_flow_guard_still_refuses_if_the_entry_check_is_ever_bypassed(): void
    {
        $sub = $this->submission($this->ro->id);

        // Simulate the entry check letting the officer through (it must not, but the backstop must still hold).
        $partial = \Mockery::mock($sub)->makePartial();
        $partial->shouldReceive('ownReviewBlockFor')->andReturn(null);

        $this->actingAs($this->ro);
        $resp = app(FicaController::class)->complianceApprove(
            Request::create('/x', 'POST'),
            $partial,
            app(\App\Services\Compliance\FicaReferralService::class)
        );

        $this->assertSame(route('compliance.fica.compliance-review', $sub), $resp->getTargetUrl());
        $this->assertSame('agent_approved', $sub->fresh()->status);
        $this->assertDatabaseHas('fica_status_history', [
            'fica_submission_id' => $sub->id,
            'action'             => 'self_approval_blocked',
            'actor_user_id'      => $this->ro->id,
        ]);
    }

    // ── The screens: disabled with the reason ─────────────────────────────

    public function test_record_page_disables_review_with_the_reason_for_the_ro_and_offers_no_markup(): void
    {
        $own = $this->submission($this->ro->id);              // agent_approved
        $ownStage1 = $this->submission($this->ro->id, 'submitted');

        $html = $this->actingAs($this->ro)->get(route('compliance.fica.show', $own))->assertOk()->getContent();
        $this->assertStringContainsString('data-own-fica-blocked', $html);
        $this->assertStringContainsString(self::REASON, $html);
        $this->assertStringContainsString('data-own-fica-review-disabled', $html);
        $this->assertStringNotContainsString(route('compliance.fica.compliance-review', $own), $html, 'no live link into the review screen');

        $html2 = $this->actingAs($this->ro)->get(route('compliance.fica.show', $ownStage1))->assertOk()->getContent();
        $this->assertStringContainsString(self::REASON, $html2);
        $this->assertStringNotContainsString(route('compliance.fica.agent-approve', $ownStage1), $html2, 'no approve form');
        $this->assertStringNotContainsString(route('compliance.fica.reject', $ownStage1), $html2, 'no reject form');
        $this->assertStringNotContainsString(route('compliance.fica.request-corrections', $ownStage1), $html2, 'no corrections form');
        $this->assertStringContainsString(route('compliance.fica.refer-to-co', $ownStage1), $html2, 'escalating to the CO stays available');
    }

    public function test_record_page_is_normal_for_another_ro_and_for_the_primary_co(): void
    {
        $sub = $this->submission($this->ro->id);

        $other = $this->actingAs($this->otherRo)->get(route('compliance.fica.show', $sub))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-own-fica-blocked', $other);
        $this->assertStringContainsString(route('compliance.fica.compliance-review', $sub), $other);

        $own = $this->submission($this->primaryCo->id);
        $co = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $own))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-own-fica-blocked', $co);
        $this->assertStringContainsString(route('compliance.fica.compliance-review', $own), $co);
    }

    public function test_compliance_list_disables_review_on_the_ros_own_row_only(): void
    {
        $own   = $this->submission($this->ro->id);
        $other = $this->submission($this->agent->id);

        $html = $this->actingAs($this->ro)->get(route('compliance.fica.index', ['tab' => 'all', 'agent_id' => 'all']))->assertOk()->getContent();

        $this->assertStringContainsString('data-own-fica-review-disabled', $html);
        $this->assertStringContainsString(self::REASON, $html);
        $this->assertStringNotContainsString(route('compliance.fica.compliance-review', $own), $html);
        $this->assertStringContainsString(route('compliance.fica.compliance-review', $other), $html, "someone else's FICA stays reviewable");
    }

    public function test_contact_fica_tab_shows_the_reason_before_the_click_through(): void
    {
        $sub = $this->submission($this->ro->id, 'submitted');
        $contact = Contact::withoutGlobalScopes()->find($sub->contact_id);

        $this->actingAs($this->ro);
        $html = view('corex.contacts._fica-tab-body', ['contact' => $contact, 'ficaStatus' => 'pending'])->render();
        $this->assertStringContainsString('data-own-fica-review-disabled', $html);
        $this->assertStringContainsString(self::REASON, $html);

        $this->actingAs($this->otherRo);
        $html = view('corex.contacts._fica-tab-body', ['contact' => $contact, 'ficaStatus' => 'pending'])->render();
        $this->assertStringNotContainsString('data-own-fica-review-disabled', $html);
    }

    // ── Who is blocked, and the no-other-reviewer case ────────────────────

    public function test_only_officers_who_are_not_the_primary_co_are_blocked(): void
    {
        $own = $this->submission($this->ro->id);

        $this->assertNotNull($own->ownReviewBlockFor($this->ro));
        $this->assertNull($own->ownReviewBlockFor($this->otherRo), "not their work");
        $this->assertNull($own->ownReviewBlockFor($this->agent), 'a non-officer on a FICA they sent: stage-1 is the normal flow');

        $primaryOwn = $this->submission($this->primaryCo->id);
        $this->assertNull($primaryOwn->ownReviewBlockFor($this->primaryCo), 'AT-236: the primary CO may self-approve');
    }

    public function test_officer_who_did_the_stage_one_approval_is_also_blocked(): void
    {
        $stage1 = $this->submission($this->agent->id, 'agent_approved', $this->ro->id);

        $this->assertNotNull($stage1->ownReviewBlockFor($this->ro));
        $this->assertNull($stage1->ownReviewBlockFor($this->otherRo));
    }

    public function test_with_no_other_eligible_reviewer_the_message_says_so_plainly(): void
    {
        // The only other officers are gone: end the primary CO's and the other RO's appointments.
        FicaOfficerAppointment::where('agency_id', $this->agencyId)
            ->whereIn('user_id', [$this->primaryCo->id, $this->otherRo->id])
            ->update(['ended_on' => now()->subDay()->toDateString()]);

        $sub = $this->submission($this->ro->id);
        $reason = $sub->ownReviewBlockFor($this->ro);

        $this->assertStringContainsString('no other Responsible Officer or Compliance Officer', $reason);
        $this->assertStringContainsString('appoint one', $reason);

        $html = $this->actingAs($this->ro)->get(route('compliance.fica.show', $sub))->assertOk()->getContent();
        $this->assertStringContainsString('no other Responsible Officer or Compliance Officer', $html);
    }

    public function test_another_officer_who_is_also_the_requester_does_not_count_as_a_reviewer(): void
    {
        // otherRo did the stage-1 approval on ro's own FICA, primary CO is gone: nobody eligible is left.
        FicaOfficerAppointment::where('agency_id', $this->agencyId)
            ->where('user_id', $this->primaryCo->id)
            ->update(['ended_on' => now()->subDay()->toDateString()]);

        $sub = $this->submission($this->ro->id, 'agent_approved', $this->otherRo->id);

        $this->assertFalse($sub->hasOtherEligibleReviewer($this->ro));
        $this->assertStringContainsString('no other Responsible Officer', $sub->ownReviewBlockFor($this->ro));
    }
}
