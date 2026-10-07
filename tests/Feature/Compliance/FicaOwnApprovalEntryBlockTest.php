<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Http\Controllers\Compliance\FicaController;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Contact;
use App\Models\FicaStatusHistory;
use App\Models\FicaSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
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
    // A referred pack is decided only at the referral station, so it must not promise "another RO may review it".
    private const REFERRED_REASON = 'this pack was referred, so only the Compliance Officer it was referred to';

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

    private function assertBlockedAtEntry($response, FicaSubmission $sub, string $statusBefore, ?string $reason = null): void
    {
        $response->assertRedirect(route('compliance.fica.show', $sub));
        $response->assertSessionHasErrors('own_fica');
        $this->assertStringContainsString($reason ?? self::REASON, session('errors')->first('own_fica'));
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

        $this->assertBlockedAtEntry($resp, $sub, $status, $status === 'referred_to_co' ? self::REFERRED_REASON : null);
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

    // ══ Audit-fix round (7 Oct 2026): resubmit, status-aware screens, N+1, wording, ledger de-dup ══

    /** A realistic stage-1 fixture: nobody has done the stage-1 check yet, so agent_verified_by is NULL. */
    private function freshSubmission(int $requestedBy, string $status): FicaSubmission
    {
        $sub = $this->submission($requestedBy, $status);
        DB::table('fica_submissions')->where('id', $sub->id)->update(['agent_verified_by' => null, 'agent_verified_at' => null]);

        return $sub->fresh();
    }

    /** Real role rows + default grants (not the suite's allow-all shortcut), so authorizeAgency's scope path is exercised. */
    private function useRealRoles(): void
    {
        $now = now();
        foreach ([['super_admin', 'System Owner', 1, 1], ['admin', 'Administrator', 0, 2], ['branch_manager', 'Branch Manager', 0, 3], ['agent', 'Agent', 0, 4]] as [$name, $label, $owner, $sort]) {
            DB::table('roles')->updateOrInsert(['name' => $name, 'agency_id' => null],
                ['label' => $label, 'is_owner' => $owner, 'can_be_deleted' => 0, 'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now]);
        }
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => $role]);
    }

    // ── F2: resubmit-corrections is no back door around the blocked stage-1 step ──

    public function test_requesting_officer_cannot_resubmit_their_own_fica_and_the_block_is_audited(): void
    {
        $sub = $this->freshSubmission($this->ro->id, 'corrections_requested');

        $resp = $this->actingAs($this->ro)->post(route('compliance.fica.resubmit-corrections', $sub));

        $this->assertBlockedAtEntry($resp, $sub, 'corrections_requested');
        $row = FicaStatusHistory::where('fica_submission_id', $sub->id)->where('action', 'self_approval_blocked')->sole();
        $this->assertSame('resubmit_corrections', $row->meta['attempt'] ?? null);
        $this->assertSame($this->ro->id, $row->actor_user_id);
    }

    public function test_resubmit_still_works_for_a_non_officer_requester_and_for_a_different_officer(): void
    {
        $byAgent = $this->freshSubmission($this->agent->id, 'corrections_requested');
        $resp = $this->actingAs($this->agent)->post(route('compliance.fica.resubmit-corrections', $byAgent));
        $resp->assertRedirect(route('compliance.fica.show', $byAgent));
        $resp->assertSessionDoesntHaveErrors('own_fica');
        $this->assertSame('agent_approved', $byAgent->fresh()->status);

        $roOwn = $this->freshSubmission($this->ro->id, 'corrections_requested');
        $resp = $this->actingAs($this->otherRo)->post(route('compliance.fica.resubmit-corrections', $roOwn));
        $resp->assertSessionDoesntHaveErrors('own_fica');
        $this->assertSame('agent_approved', $roOwn->fresh()->status, 'a different officer may resubmit it');

        $this->assertDatabaseMissing('fica_status_history', ['action' => 'self_approval_blocked']);
    }

    public function test_resubmit_with_real_roles_blocks_the_requesting_branch_manager_officer_but_not_others(): void
    {
        $this->useRealRoles();
        $bmOfficer = $this->userWithRole('branch_manager');
        $this->appoint($bmOfficer, FicaOfficerAppointment::ROLE_MLRO);
        $bmPlain   = $this->userWithRole('branch_manager');   // not an officer
        $adminRo   = $this->userWithRole('admin');
        $this->appoint($adminRo, FicaOfficerAppointment::ROLE_MLRO);

        $own = $this->freshSubmission($bmOfficer->id, 'corrections_requested');
        $resp = $this->actingAs($bmOfficer)->post(route('compliance.fica.resubmit-corrections', $own));
        $this->assertBlockedAtEntry($resp, $own, 'corrections_requested');
        $this->assertDatabaseHas('fica_status_history', ['fica_submission_id' => $own->id, 'action' => 'self_approval_blocked', 'actor_user_id' => $bmOfficer->id]);

        $plainOwn = $this->freshSubmission($bmPlain->id, 'corrections_requested');
        $this->actingAs($bmPlain)->post(route('compliance.fica.resubmit-corrections', $plainOwn))->assertSessionDoesntHaveErrors('own_fica');
        $this->assertSame('agent_approved', $plainOwn->fresh()->status);

        // A different officer (an admin RO, company scope) resubmits the branch manager's FICA.
        $again = $this->freshSubmission($bmOfficer->id, 'corrections_requested');
        $this->actingAs($adminRo)->post(route('compliance.fica.resubmit-corrections', $again))->assertSessionDoesntHaveErrors('own_fica');
        $this->assertSame('agent_approved', $again->fresh()->status);
    }

    public function test_record_page_with_real_roles_shows_the_notice_only_to_the_blocked_officer(): void
    {
        $this->useRealRoles();
        $bmOfficer = $this->userWithRole('branch_manager');
        $this->appoint($bmOfficer, FicaOfficerAppointment::ROLE_MLRO);
        $adminRo = $this->userWithRole('admin');
        $this->appoint($adminRo, FicaOfficerAppointment::ROLE_MLRO);

        $sub = $this->freshSubmission($bmOfficer->id, 'corrections_requested');
        DB::table('fica_submissions')->where('id', $sub->id)->update(['co_notes' => 'Please attach a clearer proof of address.']); // the banner (and its Resubmit button) shows with CO notes

        $own = $this->actingAs($bmOfficer)->get(route('compliance.fica.show', $sub))->assertOk()->getContent();
        $this->assertStringContainsString('data-own-fica-blocked', $own);
        $this->assertStringNotContainsString(route('compliance.fica.resubmit-corrections', $sub), $own, 'no resubmit button the server would refuse');

        $other = $this->actingAs($adminRo)->get(route('compliance.fica.show', $sub))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-own-fica-blocked', $other);
        $this->assertStringContainsString(route('compliance.fica.resubmit-corrections', $sub), $other);
    }

    // ── F4: the notice and the Reopen button exist only where the block can apply ──

    /** @return array<string, array{0: string}> */
    public static function finishedStatuses(): array
    {
        return ['approved' => ['approved'], 'cancelled' => ['cancelled'], 'draft' => ['draft']];
    }

    /**
     * @dataProvider finishedStatuses
     */
    public function test_no_own_fica_notice_on_a_finished_or_unsent_record(string $status): void
    {
        $sub = $this->freshSubmission($this->ro->id, $status);

        $html = $this->actingAs($this->ro)->get(route('compliance.fica.show', $sub))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-own-fica-blocked', $html);
        $this->assertStringNotContainsString('This is your own FICA', $html);
        $this->assertStringNotContainsString('data-own-fica-review-disabled', $html);
    }

    public function test_rejected_own_fica_has_no_banner_and_no_dead_reopen_button_but_others_keep_the_button(): void
    {
        $sub = $this->freshSubmission($this->ro->id, 'rejected');

        $html = $this->actingAs($this->ro)->get(route('compliance.fica.show', $sub))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-own-fica-blocked', $html, 'no banner');
        $this->assertStringNotContainsString(route('compliance.fica.reopen', $sub), $html, 'no Reopen form the server would refuse');
        $this->assertStringNotContainsString('Reopen for Corrections', $html);
        $this->assertStringContainsString('data-own-fica-reopen-blocked', $html, 'a plain reason takes the button\'s place');

        $other = $this->actingAs($this->otherRo)->get(route('compliance.fica.show', $sub))->assertOk()->getContent();
        $this->assertStringContainsString(route('compliance.fica.reopen', $sub), $other);
        $this->assertStringNotContainsString('data-own-fica-reopen-blocked', $other);
    }

    public function test_notice_is_present_in_every_review_state(): void
    {
        foreach (['submitted', 'under_review', 'corrections_requested', 'agent_approved', 'referred_to_co'] as $status) {
            $sub = $this->freshSubmission($this->ro->id, $status);
            $html = $this->actingAs($this->ro)->get(route('compliance.fica.show', $sub))->assertOk()->getContent();
            $this->assertStringContainsString('data-own-fica-blocked', $html, $status);
        }
    }

    public function test_blocked_attempts_on_a_record_with_nothing_to_review_are_refused_but_not_audited(): void
    {
        foreach (['approved', 'cancelled', 'draft'] as $status) {
            $sub = $this->freshSubmission($this->ro->id, $status);
            $resp = $this->actingAs($this->ro)->post(route('compliance.fica.agent-approve', $sub), []);
            $resp->assertSessionHasErrors('own_fica');
            $this->assertSame($status, $sub->fresh()->status);
        }
        $this->assertDatabaseMissing('fica_status_history', ['action' => 'self_approval_blocked']);

        // A genuine blocked attempt (reopen on a rejected own FICA) is still audited.
        $rejected = $this->freshSubmission($this->ro->id, 'rejected');
        $this->actingAs($this->ro)->post(route('compliance.fica.reopen', $rejected), [])->assertSessionHasErrors('own_fica');
        $this->assertDatabaseHas('fica_status_history', ['fica_submission_id' => $rejected->id, 'action' => 'self_approval_blocked']);
    }

    // ── F5: the list asks the officer questions once per agency, not once per row ──

    private function appointmentQueriesFor(callable $render): int
    {
        $count = 0;
        DB::listen(function ($q) use (&$count) {
            if (str_contains($q->sql, 'fica_officer_appointments')) {
                $count++;
            }
        });
        $render();

        return $count;
    }

    public function test_officer_lookups_on_the_list_do_not_grow_with_the_number_of_rows(): void
    {
        $this->actingAs($this->ro);
        $url = route('compliance.fica.index', ['tab' => 'all', 'agent_id' => 'all']);

        // Baseline already holds one row of each review state, so the per-agency lookups are all paid once.
        $this->submission($this->ro->id, 'agent_approved');
        $this->submission($this->ro->id, 'referred_to_co');
        $this->get($url)->assertOk(); // warm: first request pays one-off boot queries
        $small = $this->appointmentQueriesFor(fn () => $this->get($url)->assertOk());

        foreach (range(1, 18) as $i) { $this->submission($this->ro->id, $i % 2 ? 'agent_approved' : 'referred_to_co'); }
        $big = $this->appointmentQueriesFor(fn () => $this->get($url)->assertOk());

        $this->assertSame($small, $big, "appointment queries: 2 rows => {$small}, 20 rows => {$big}");
        $this->assertLessThanOrEqual(12, $big);
    }

    public function test_officer_lookups_on_the_contact_tab_do_not_grow_with_the_number_of_submissions(): void
    {
        $this->actingAs($this->ro);
        $mk = function (int $n): array {
            $contact = Contact::withoutEvents(fn () => Contact::create([
                'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
                'first_name' => 'Multi', 'last_name' => 'Sub' . $n, 'created_by_user_id' => $this->ro->id,
            ]));
            foreach (range(1, $n) as $_) {
                FicaSubmission::withoutEvents(fn () => FicaSubmission::create([
                    'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'contact_id' => $contact->id,
                    'requested_by' => $this->ro->id, 'agent_verified_by' => $this->ro->id, 'status' => 'submitted',
                ]));
            }

            return [$contact];
        };
        [$few] = $mk(1);
        [$many] = $mk(15);
        $render = fn ($c) => fn () => view('corex.contacts._fica-tab-body', ['contact' => $c, 'ficaStatus' => 'pending'])->render();

        $one = $this->appointmentQueriesFor($render($few));
        $fifteen = $this->appointmentQueriesFor($render($many));

        $this->assertSame($one, $fifteen, "appointment queries: 1 submission => {$one}, 15 => {$fifteen}");
    }

    // ── F6/F7: the wording tells the truth ──

    public function test_stage_one_never_claims_nobody_else_can_do_the_check(): void
    {
        // No other officer at all - but stage 1 is open to any user with compliance access.
        FicaOfficerAppointment::where('agency_id', $this->agencyId)
            ->whereIn('user_id', [$this->primaryCo->id, $this->otherRo->id])
            ->update(['ended_on' => now()->subDay()->toDateString()]);

        $sub = $this->freshSubmission($this->ro->id, 'submitted');
        $reason = $sub->ownReviewBlockFor($this->ro);

        $this->assertNotNull($reason);
        $this->assertStringNotContainsString('no other', $reason);
        $this->assertStringNotContainsString('appoint one', $reason);
        $this->assertStringContainsString('any other user with compliance access', $reason);
    }

    public function test_referred_pack_wording_names_the_referral_station_not_another_ro(): void
    {
        // Primary CO exists: the station is open to them.
        $sub = $this->freshSubmission($this->ro->id, 'referred_to_co');
        $reason = $sub->ownReviewBlockFor($this->ro);
        $this->assertStringContainsString(self::REFERRED_REASON, $reason);
        $this->assertStringNotContainsString('another Responsible Officer', $reason);

        // No primary CO and the other RO is NOT the referral recipient: nobody can decide it.
        FicaOfficerAppointment::where('agency_id', $this->agencyId)->where('user_id', $this->primaryCo->id)
            ->update(['ended_on' => now()->subDay()->toDateString()]);
        $none = $sub->fresh()->ownReviewBlockFor($this->ro);
        $this->assertStringContainsString('no other Compliance Officer able to decide this referred pack', $none);
        $this->assertFalse($sub->fresh()->hasOtherEligibleReviewer($this->ro));

        // ...unless the agency has configured that other RO as the referral recipient.
        DB::table('agencies')->where('id', $this->agencyId)->update(['fica_referral_recipient_user_id' => $this->otherRo->id]);
        $this->assertTrue($sub->fresh()->hasOtherEligibleReviewer($this->ro));
        $this->assertStringContainsString(self::REFERRED_REASON, $sub->fresh()->ownReviewBlockFor($this->ro));
    }

    // ── F8: a refresh-happy officer does not flood the ledger ──

    public function test_identical_blocked_posts_collapse_into_one_ledger_row(): void
    {
        $sub = $this->freshSubmission($this->ro->id, 'submitted');

        foreach (range(1, 4) as $_) {
            $this->actingAs($this->ro)->post(route('compliance.fica.agent-approve', $sub), [])->assertSessionHasErrors('own_fica');
        }
        $this->assertSame(1, FicaStatusHistory::where('fica_submission_id', $sub->id)->where('action', 'self_approval_blocked')->count());

        // A different action is a different event: it gets its own row.
        $this->actingAs($this->ro)->post(route('compliance.fica.reject', $sub), []);
        $this->assertSame(2, FicaStatusHistory::where('fica_submission_id', $sub->id)->where('action', 'self_approval_blocked')->count());

        // Outside the window the same action is recorded again.
        Carbon::setTestNow(now()->addSeconds(61));
        try {
            $this->actingAs($this->ro)->post(route('compliance.fica.agent-approve', $sub), []);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame(3, FicaStatusHistory::where('fica_submission_id', $sub->id)->where('action', 'self_approval_blocked')->count());
    }

    public function test_the_ledger_dedup_is_per_submission_and_per_user(): void
    {
        $a = $this->freshSubmission($this->ro->id, 'submitted');
        $b = $this->freshSubmission($this->ro->id, 'submitted');

        $this->actingAs($this->ro)->post(route('compliance.fica.agent-approve', $a), []);
        $this->actingAs($this->ro)->post(route('compliance.fica.agent-approve', $b), []);

        $this->assertSame(1, FicaStatusHistory::where('fica_submission_id', $a->id)->where('action', 'self_approval_blocked')->count());
        $this->assertSame(1, FicaStatusHistory::where('fica_submission_id', $b->id)->where('action', 'self_approval_blocked')->count());
    }
}
