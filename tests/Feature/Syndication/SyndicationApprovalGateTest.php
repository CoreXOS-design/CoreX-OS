<?php

namespace Tests\Feature\Syndication;

use App\Jobs\Property\GrandfatherSyndicatedStockJob;
use App\Mail\SyndicationApprovalRequestedMail;
use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\PropertySyndicationApproval;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\SyndicationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * THE LAYER-3 GATE. Spec: .ai/specs/syndication-approval-gate.md
 *
 * CoreX has two automatic layers between a listing and the portals (on-market,
 * then compliance). This is the third and it is not automatic: a person the
 * agency named has to say yes. The whole point is that a compliance-GREEN
 * listing still cannot reach Property24, Private Property or an agency website
 * until they do.
 *
 * So every assertion here is written against a listing whose compliance has
 * already passed. A test that blocked an incomplete listing would prove
 * nothing — layer 2 already does that.
 *
 * The three rulings this test exists to lock (Johan, 2026-09-29):
 *   D2 — approval holds FOREVER. Editing price/photos/description afterwards
 *        does NOT send it back. Asserted explicitly so nobody "helpfully"
 *        adds re-approval later.
 *   D3 — it holds back ALL syndication: P24, PP and every agency website.
 *   D8 — "only new stock": stock already published is grandfathered at
 *        switch-on and never reaches the approver.
 */
class SyndicationApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $approver;
    private AgencyApiKey $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid(), 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->approver = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
            'name' => 'Penny Principal', 'email' => 'penny@coastal.test',
        ]);

        $minted = AgencyApiKey::mintSecret();
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Main Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);

        // No portal shall be reached while the gate is shut — asserted per-URL.
        Http::fake();

        $this->actingAs($this->agent);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    /** A compliance-CLEAR, on-market listing — layers 1 and 2 already satisfied. */
    private function compliantProperty(array $overrides = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4),
            'address' => '12 Beach Road', 'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KZN',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
            'published_at' => now(),
            // Layer 2 satisfied: the compliance snapshot short-circuits
            // MarketingReadinessService::isMarketable() to true.
            'compliance_snapshot_at' => now(),
        ], $overrides));
    }

    private function switchGateOn(array $approverIds = null): void
    {
        PerformanceSetting::set(
            SyndicationApprovalService::SETTING_APPROVERS,
            json_encode($approverIds ?? [$this->approver->id]),
            $this->agency->id
        );
        PerformanceSetting::set(
            SyndicationApprovalService::SETTING_REQUIRED,
            1,
            $this->agency->id
        );
    }

    /**
     * Every path that puts a listing ONTO a target. Spec §6.3 — eight of them,
     * across three portals.
     */
    private function enablePaths(Property $p): array
    {
        return [
            'p24.toggle'      => route('corex.properties.p24-syndication.toggle', $p),
            'p24.submit'      => route('corex.properties.p24-syndication.submit', $p),
            'p24.reactivate'  => route('corex.properties.p24-syndication.reactivate', $p),
            'pp.toggle'       => route('corex.properties.syndication.toggle', $p),
            'pp.submit'       => route('corex.properties.syndication.submit', $p),
            'pp.reactivate'   => route('corex.properties.syndication.reactivate', $p),
            'web.toggle'      => route('corex.properties.website-syndication.toggle', [$p, $this->key]),
            'web.activate'    => route('corex.properties.website-syndication.activate', [$p, $this->key]),
        ];
    }

    // ── Off is inert ────────────────────────────────────────────────────

    public function test_with_the_feature_off_no_path_is_ever_blocked_by_approval(): void
    {
        $p = $this->compliantProperty();

        foreach ($this->enablePaths($p) as $label => $url) {
            $response = $this->postJson($url);
            $this->assertNotSame(
                'syndication_approval_required',
                $response->json('error'),
                "[$label] was blocked by layer 3 even though the agency never switched it on."
            );
        }
    }

    public function test_with_the_feature_off_the_state_is_silent(): void
    {
        $state = app(SyndicationApprovalService::class)->stateFor($this->compliantProperty());

        $this->assertTrue($state->isSilent());
        $this->assertTrue($state->approved, 'An agency without the gate must never be treated as unapproved.');
        $this->assertFalse($state->canRequest);
    }

    // ── On: every enable path is shut ───────────────────────────────────

    public function test_every_enable_path_is_blocked_on_a_compliance_clear_listing(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty();

        foreach ($this->enablePaths($p) as $label => $url) {
            $this->postJson($url)
                ->assertStatus(422)
                ->assertJson(['error' => 'syndication_approval_required'], "[$label] was NOT blocked.");
        }

        // Nothing was switched on behind the refusal.
        $fresh = $p->fresh();
        $this->assertFalse((bool) $fresh->p24_syndication_enabled);
        $this->assertFalse((bool) $fresh->pp_syndication_enabled);

        // And no portal was ever contacted. Per-URL counts, never assertNotSent:
        // Http::fake() MERGES stubs, so a blanket assertion can pass for the
        // wrong reason.
        Http::assertNothingSent();
    }

    public function test_deactivating_is_never_blocked(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty();

        // Removing exposure must always be possible — blocking a take-down
        // would be actively dangerous.
        foreach ([
            route('corex.properties.p24-syndication.deactivate', $p),
            route('corex.properties.syndication.deactivate', $p),
            route('corex.properties.website-syndication.deactivate', [$p, $this->key]),
        ] as $url) {
            $this->assertNotSame('syndication_approval_required', $this->postJson($url)->json('error'));
        }
    }

    // ── The agent's request + the email (D4/D5) ─────────────────────────

    public function test_send_for_approval_creates_the_request_and_emails_every_approver(): void
    {
        Mail::fake();

        $second = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => 'agent', 'email' => 'second@coastal.test',
        ]);
        $this->switchGateOn([$this->approver->id, $second->id]);

        $p = $this->compliantProperty();

        $this->postJson(route('corex.properties.syndication-approval.request', $p), [
            'note' => 'Seller wants it live by Friday.',
        ])->assertOk()->assertJson(['success' => true]);

        $row = PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $p->id)->firstOrFail();

        $this->assertSame(PropertySyndicationApproval::STATUS_PENDING, $row->status);
        $this->assertSame($this->agent->id, $row->requested_by_user_id);
        $this->assertSame('Seller wants it live by Friday.', $row->request_note);
        $this->assertSame($this->branch->id, $row->branch_id, 'branch_id must be stamped for branch-scoped visibility.');

        // Both approvers, one email each, carrying what the approver needs.
        // assertSent (not assertQueued): the job is what is queued; the
        // mailable itself is sent synchronously from inside it.
        Mail::assertSent(SyndicationApprovalRequestedMail::class, 2);
        Mail::assertSent(SyndicationApprovalRequestedMail::class, function ($mail) {
            return $mail->hasTo('penny@coastal.test')
                && str_contains($mail->addressLine, '12 Beach Road')
                && str_contains($mail->priceLine, '1,500,000')
                && $mail->note === 'Seller wants it live by Friday.';
        });

        // Still shut while it waits.
        $this->postJson(route('corex.properties.p24-syndication.submit', $p))->assertStatus(422);
    }

    public function test_the_button_does_not_exist_before_compliance_is_complete(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty(['compliance_snapshot_at' => null]);

        $svc = app(SyndicationApprovalService::class);
        $this->assertFalse($svc->canRequest($p), 'An incomplete listing must not offer Send for approval.');

        $this->postJson(route('corex.properties.syndication-approval.request', $p))
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_a_mail_failure_leaves_the_request_pending_and_unnotified(): void
    {
        // The gate must never depend on the email arriving.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->switchGateOn();
        $p = $this->compliantProperty();

        $this->postJson(route('corex.properties.syndication-approval.request', $p))->assertOk();

        $row = PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $p->id)->firstOrFail();

        $this->assertSame(PropertySyndicationApproval::STATUS_PENDING, $row->status);
        $this->assertNull($row->notified_at, 'A failed send must stay visible, not be assumed successful.');
    }

    // ── Approval, and what it unlocks ───────────────────────────────────

    public function test_approval_stamps_the_property_and_unlocks_every_path(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty();

        $this->postJson(route('corex.properties.syndication-approval.request', $p))->assertOk();

        $this->actingAs($this->approver)
            ->postJson(route('corex.properties.syndication-approval.approve', $p), ['note' => 'Looks good.'])
            ->assertOk()->assertJson(['success' => true]);

        $p->refresh();
        $this->assertNotNull($p->syndication_approved_at);
        $this->assertSame($this->approver->id, (int) $p->syndication_approved_by_user_id);

        // All eight now pass layer 3. They may still fail for an unrelated
        // reason (no portal credentials in a test env) — what must NEVER come
        // back is the approval refusal.
        $this->actingAs($this->agent);
        foreach ($this->enablePaths($p) as $label => $url) {
            $this->assertNotSame(
                'syndication_approval_required',
                $this->postJson($url)->json('error'),
                "[$label] was still blocked after approval."
            );
        }
    }

    public function test_approval_holds_after_the_listing_is_edited(): void
    {
        // D2, asserted explicitly: price, photos and description changes do NOT
        // send an approved listing back for re-approval.
        $this->switchGateOn();
        $p = $this->compliantProperty();

        app(SyndicationApprovalService::class)->approve($p, $this->approver);
        $p->refresh();

        $p->forceFill([
            'price'       => 9950000,
            'description' => 'Completely rewritten marketing copy.',
            'gallery_images_json' => ['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg'],
        ])->save();

        $this->assertTrue(app(SyndicationApprovalService::class)->isApproved($p->fresh()));
    }

    public function test_rejection_records_the_reason_and_keeps_the_portals_shut(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty();
        $this->postJson(route('corex.properties.syndication-approval.request', $p))->assertOk();

        $this->actingAs($this->approver)
            ->postJson(route('corex.properties.syndication-approval.reject', $p), ['reason' => 'Front photo is the neighbour\'s house.'])
            ->assertOk();

        $row = PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $p->id)->orderByDesc('id')->firstOrFail();

        $this->assertSame(PropertySyndicationApproval::STATUS_REJECTED, $row->status);
        $this->assertSame('Front photo is the neighbour\'s house.', $row->decision_note);
        $this->assertNull($p->fresh()->syndication_approved_at);

        $this->actingAs($this->agent)
            ->postJson(route('corex.properties.p24-syndication.submit', $p))
            ->assertStatus(422)->assertJson(['error' => 'syndication_approval_required']);
    }

    public function test_reject_requires_a_reason(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty();
        $this->postJson(route('corex.properties.syndication-approval.request', $p))->assertOk();

        $this->actingAs($this->approver)
            ->postJson(route('corex.properties.syndication-approval.reject', $p), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    // ── Revoke ──────────────────────────────────────────────────────────

    public function test_revoke_reblocks_new_syndication_but_never_touches_a_live_listing(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty();

        app(SyndicationApprovalService::class)->approve($p, $this->approver);

        // Pretend it went live on P24 while it was approved.
        $p->forceFill(['p24_syndication_enabled' => true, 'p24_ref' => 'REF123', 'p24_syndication_status' => 'active'])->save();

        $this->actingAs($this->approver)
            ->postJson(route('corex.properties.syndication-approval.revoke', $p), ['reason' => 'Price was changed without telling me.'])
            ->assertOk();

        $p->refresh();
        $this->assertNull($p->syndication_approved_at);

        // The live listing is UNTOUCHED — revoke stops it going somewhere new,
        // it is not a delist. Pulling it down stays the explicit Deactivate.
        $this->assertTrue((bool) $p->p24_syndication_enabled);
        $this->assertSame('REF123', $p->p24_ref);

        // But a NEW enable is shut again.
        $this->actingAs($this->agent)
            ->postJson(route('corex.properties.syndication.toggle', $p))
            ->assertStatus(422)->assertJson(['error' => 'syndication_approval_required']);

        $row = PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $p->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(PropertySyndicationApproval::STATUS_WITHDRAWN, $row->status);
        $this->assertSame('Price was changed without telling me.', $row->decision_note);
    }

    // ── Authority ───────────────────────────────────────────────────────

    public function test_an_ordinary_agent_cannot_approve(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty();
        $this->postJson(route('corex.properties.syndication-approval.request', $p))->assertOk();

        // The requesting agent is not on the roster — asking is not deciding.
        $this->postJson(route('corex.properties.syndication-approval.approve', $p))->assertStatus(403);
        $this->assertNull($p->fresh()->syndication_approved_at);
    }

    public function test_an_approver_from_another_agency_cannot_reach_the_property(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty();

        // BelongsToAgency force-stamps a new record onto the ACTING user's
        // agency, overriding any agency_id passed in — so building the
        // "outsider" naively inside an actingAs() context silently creates
        // them inside THIS agency and the test proves nothing. Vouch for the
        // agency explicitly.
        $otherAgency = Agency::create(['name' => 'Rival', 'slug' => 'rival-' . uniqid()]);
        $otherBranch = Branch::withoutAgencyStamping(fn () => Branch::create([
            'agency_id' => $otherAgency->id, 'name' => 'Main',
        ]));
        $outsider = User::withoutAgencyStamping(fn () => User::factory()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin',
        ]));

        $this->assertSame($otherAgency->id, (int) $outsider->agency_id, 'Fixture guard: the outsider must really be outside.');

        // AgencyScope makes the route binding itself 404 across agencies —
        // direct-URL access by id is blocked, not merely unlinked.
        $this->actingAs($outsider)
            ->postJson(route('corex.properties.syndication-approval.approve', $p))
            ->assertStatus(404);
    }

    public function test_the_owner_admin_fallback_can_always_approve(): void
    {
        // An agency whose chosen approver has left must never be stuck.
        $this->switchGateOn();
        $p = $this->compliantProperty();

        $admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->postJson(route('corex.properties.syndication-approval.approve', $p))
            ->assertOk();

        $this->assertNotNull($p->fresh()->syndication_approved_at);
    }

    // ── The property's own status is never touched (spec §2) ────────────

    public function test_no_transition_ever_writes_the_property_status_column(): void
    {
        $this->switchGateOn();
        $p = $this->compliantProperty(['status' => 'active']);
        $svc = app(SyndicationApprovalService::class);

        $svc->request($p, $this->agent);
        $this->assertSame('active', $p->fresh()->status);

        $svc->approve($p, $this->approver);
        $this->assertSame('active', $p->fresh()->status);

        $svc->revoke($p, $this->approver, 'nope');
        $this->assertSame('active', $p->fresh()->status);
    }

    // ── D8 — "only new stock" ───────────────────────────────────────────

    public function test_switch_on_grandfathers_published_stock_but_not_unpublished_or_revoked(): void
    {
        $published   = $this->compliantProperty(['p24_syndication_enabled' => true]);
        $onPp        = $this->compliantProperty(['pp_syndication_enabled' => true]);
        $neverOut    = $this->compliantProperty();
        $offMarket   = $this->compliantProperty(['status' => 'sold', 'p24_syndication_enabled' => true]);

        // A property that WAS approved and then deliberately revoked: an off→on
        // cycle must never resurrect it.
        $revoked = $this->compliantProperty(['p24_syndication_enabled' => true]);
        $this->switchGateOn();
        app(SyndicationApprovalService::class)->approve($revoked, $this->approver);
        app(SyndicationApprovalService::class)->revoke($revoked, $this->approver, 'pulled');

        (new GrandfatherSyndicatedStockJob($this->agency->id, $this->approver->id))->handle();

        $this->assertNotNull($published->fresh()->syndication_approved_at, 'Live P24 stock must be grandfathered.');
        $this->assertNotNull($onPp->fresh()->syndication_approved_at, 'Live PP stock must be grandfathered.');
        $this->assertNull($neverOut->fresh()->syndication_approved_at, 'Never-published stock still needs one nod.');
        $this->assertNull($offMarket->fresh()->syndication_approved_at, 'Off-market stock has nothing to approve.');
        $this->assertNull($revoked->fresh()->syndication_approved_at, 'A human revoke must never be undone by the switch.');

        // The trail says WHY a property nobody approved is approved.
        $row = PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $published->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(PropertySyndicationApproval::STATUS_APPROVED, $row->status);
        $this->assertStringContainsString('already published', $row->decision_note);
    }

    public function test_grandfathering_is_idempotent(): void
    {
        $published = $this->compliantProperty(['p24_syndication_enabled' => true]);
        $this->switchGateOn();

        (new GrandfatherSyndicatedStockJob($this->agency->id, $this->approver->id))->handle();
        $firstStamp = $published->fresh()->syndication_approved_at;

        (new GrandfatherSyndicatedStockJob($this->agency->id, $this->approver->id))->handle();

        $this->assertEquals($firstStamp, $published->fresh()->syndication_approved_at);
        $this->assertSame(1, PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $published->id)->count(), 'A second run must not double-row.');
    }

    // ── The switch itself ───────────────────────────────────────────────

    public function test_the_switch_cannot_be_turned_on_with_nobody_behind_it(): void
    {
        $admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);

        // A VALIDATION error, not a flash: the Agency Setup Wizard reuses this
        // saver and ignores return values, so the refusal has to be something
        // that bubbles. See SyndicationApprovalWizardTest.
        $this->actingAs($admin)
            ->post(route('corex.settings.syndication-portals'), [
                'syndication_approval_required' => 1,
                'syndication_approver_user_ids' => [],
            ])
            ->assertSessionHasErrors('syndication_approver_user_ids');

        $this->assertFalse(SyndicationApprovalService::isRequiredForAgency($this->agency->id));
    }
}
