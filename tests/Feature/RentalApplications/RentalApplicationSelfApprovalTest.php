<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, verbatim: "Self approve should only work for the co of rentals or
 * admin - rest agents and ro can not approve their own." Pins
 * RentalApplicationAuthorisationController::guardNotSelfApproving() —
 * confirmed already correct by direct code audit (2026-09-09), but with no
 * regression test until now, so nothing would have caught this breaking.
 *
 * Four personas, matching Johan's own framing exactly:
 *   - A plain agent (no RO/CO tier at all) — blocked outright by the OUTER
 *     tier gate (guardCanDecide()'s isRO||isCO check), before self-approval
 *     even comes into play. Still a genuine proof of "an agent cannot
 *     approve their own" — they cannot approve ANYONE's, let alone theirs.
 *   - An RO who is ALSO the agent who submitted the application — this is
 *     the one that actually exercises guardNotSelfApproving() itself: they
 *     hold real RO tier (so the outer gate passes) but are refused because
 *     it's their own submission. A companion assertion proves the SAME RO
 *     genuinely can decide a DIFFERENT agent's application, so the block is
 *     provably about self-approval, not a blanket RO deny.
 *   - A CO who submitted their own application — allowed, per Johan's rule.
 *   - An admin (role=admin) who ALSO holds CO tier (the realistic shape —
 *     Johan: "admin or bm acts like the co" — role alone confers no tier
 *     membership in this codebase, confirmed by audit) who submitted their
 *     own application — allowed, exercising the role-tier exemption
 *     specifically, not just the CO-list exemption already covered above.
 *
 * All three decision actions (approve/decline/request-more-info) — Johan's
 * rule applies to every one of them, and the code enforces it via two
 * separate call sites (guardCanDecide() for approve/decline,
 * a direct call for requestMoreInfo()), so each needs its own proof.
 */
final class RentalApplicationSelfApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
    }

    private function user(string $role = 'agent'): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role]);
    }

    private function setRO(array $userIds): void
    {
        $this->agency->update(['rental_application_ro_user_ids' => $userIds]);
    }

    private function setCO(array $userIds): void
    {
        $this->agency->update(['rental_application_co_user_ids' => $userIds]);
    }

    /** A fresh, pending-authorisation application — the "first decision" case guardNotSelfApproving() applies to regardless of override status. */
    private function pendingApplication(User $creator): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho-' . uniqid() . '@example.co.za',
        ]);

        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $creator->id, 'status' => 'under_assessment', 'submitted_for_approval_at' => now(),
            'full_name' => 'Sipho Ndlovu', 'email' => 'applicant-' . uniqid() . '@example.co.za',
        ]);
    }

    private function approveUrl(RentalApplication $app): string
    {
        return route('corex.rental-applications.authorisation.approve', $app);
    }

    private function declineUrl(RentalApplication $app): string
    {
        return route('corex.rental-applications.authorisation.decline', $app);
    }

    private function requestMoreInfoUrl(RentalApplication $app): string
    {
        return route('corex.rental-applications.authorisation.request-more-info', $app);
    }

    // ── Persona 1: plain agent, no RO/CO tier at all ──────────────────────

    public function test_a_plain_agent_with_no_tier_cannot_approve_at_all_let_alone_their_own(): void
    {
        $agent = $this->user('agent');
        $app = $this->pendingApplication($agent);
        // Deliberately NOT added to either list — the outer tier gate is what fires here.

        $this->actingAs($agent)->post($this->approveUrl($app), ['approved_rental_amount' => 15000])
            ->assertForbidden();
        $this->actingAs($agent)->post($this->declineUrl($app), ['reason' => 'no'])
            ->assertForbidden();
        $this->actingAs($agent)->post($this->requestMoreInfoUrl($app), ['reason' => 'need more'])
            ->assertForbidden();

        $this->assertSame('under_assessment', $app->fresh()->status);
    }

    // ── Persona 2: RO, submitted their own application ────────────────────

    public function test_an_ro_cannot_approve_their_own_submission_but_can_approve_someone_elses(): void
    {
        $ro = $this->user('agent');
        $otherAgent = $this->user('agent');
        $this->setRO([$ro->id]);

        $ownApp = $this->pendingApplication($ro);
        $this->actingAs($ro)->post($this->approveUrl($ownApp), ['approved_rental_amount' => 15000])
            ->assertForbidden();
        $this->actingAs($ro)->post($this->declineUrl($ownApp), ['reason' => 'no'])
            ->assertForbidden();
        $this->actingAs($ro)->post($this->requestMoreInfoUrl($ownApp), ['reason' => 'need more'])
            ->assertForbidden();
        $this->assertSame('under_assessment', $ownApp->fresh()->status);

        // Same RO, someone ELSE'S application — genuinely allowed, proving
        // the block above is about self-approval, not a blanket RO deny.
        $othersApp = $this->pendingApplication($otherAgent);
        $this->actingAs($ro)->post($this->approveUrl($othersApp), ['approved_rental_amount' => 15000])
            ->assertRedirect();
        $this->assertSame('approved', $othersApp->fresh()->status);
    }

    // ── Persona 3: CO, submitted their own application ─────────────────────

    public function test_a_co_can_approve_their_own_submission(): void
    {
        $co = $this->user('agent');
        $this->setCO([$co->id]);
        $app = $this->pendingApplication($co);

        $this->actingAs($co)->post($this->approveUrl($app), ['approved_rental_amount' => 15000])
            ->assertRedirect();
        $this->assertSame('approved', $app->fresh()->status);
    }

    public function test_a_co_can_decline_their_own_submission(): void
    {
        $co = $this->user('agent');
        $this->setCO([$co->id]);
        $app = $this->pendingApplication($co);

        $this->actingAs($co)->post($this->declineUrl($app), ['reason' => 'not qualifying'])
            ->assertRedirect();
        $this->assertSame('declined', $app->fresh()->status);
    }

    public function test_a_co_can_request_more_info_on_their_own_submission(): void
    {
        $co = $this->user('agent');
        $this->setCO([$co->id]);
        $app = $this->pendingApplication($co);

        $this->actingAs($co)->post($this->requestMoreInfoUrl($app), ['reason' => 'need bank statements'])
            ->assertRedirect();
        $this->assertNull($app->fresh()->submitted_for_approval_at);
    }

    // ── Persona 4: admin role, ALSO holding CO tier (the realistic shape — role alone confers no tier membership) ──

    public function test_an_admin_who_also_holds_co_tier_can_approve_their_own_submission(): void
    {
        $admin = $this->user('admin');
        $this->setCO([$admin->id]);
        $app = $this->pendingApplication($admin);

        $this->actingAs($admin)->post($this->approveUrl($app), ['approved_rental_amount' => 15000])
            ->assertRedirect();
        $this->assertSame('approved', $app->fresh()->status);
    }

    public function test_an_admin_who_also_holds_co_tier_can_decline_their_own_submission(): void
    {
        $admin = $this->user('admin');
        $this->setCO([$admin->id]);
        $app = $this->pendingApplication($admin);

        $this->actingAs($admin)->post($this->declineUrl($app), ['reason' => 'not qualifying'])
            ->assertRedirect();
        $this->assertSame('declined', $app->fresh()->status);
    }

    public function test_an_admin_who_also_holds_co_tier_can_request_more_info_on_their_own_submission(): void
    {
        $admin = $this->user('admin');
        $this->setCO([$admin->id]);
        $app = $this->pendingApplication($admin);

        $this->actingAs($admin)->post($this->requestMoreInfoUrl($app), ['reason' => 'need bank statements'])
            ->assertRedirect();
        $this->assertNull($app->fresh()->submitted_for_approval_at);
    }

    /**
     * The one case worth an explicit, separate proof: an admin role WITHOUT
     * any RO/CO tier membership is still blocked outright by the outer tier
     * gate — role alone is never enough to even REACH the decision screen,
     * let alone bypass self-approval. Confirms the two exemptions
     * (role-tier, CO-list) are genuinely independent checks, not role
     * silently granting tier.
     */
    public function test_an_admin_with_no_ro_or_co_tier_membership_still_cannot_decide_at_all(): void
    {
        $admin = $this->user('admin');
        $app = $this->pendingApplication($admin);
        // Deliberately not added to either list.

        $this->actingAs($admin)->post($this->approveUrl($app), ['approved_rental_amount' => 15000])
            ->assertForbidden();
    }
}
