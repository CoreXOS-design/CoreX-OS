<?php

namespace Tests\Feature\Syndication;

use App\Jobs\PrivateProperty\SyncPpListingStatusJob;
use App\Jobs\SubmitListingToProperty24;
use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\PropertySyndicationApproval;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use App\Services\Syndication\Property24\Property24SyndicationService;
use App\Services\Syndication\SyndicationApprovalRequiredException;
use App\Services\Syndication\SyndicationApprovalService;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Layer 3 (syndication approval) BACKSTOPS — the gate is enforced at the
 * service / job / observer chokepoints, not only at the controllers, so the
 * queue, the console commands and the observer auto-sync cannot bypass it.
 * Also locks the request/cancel/reject hardening and the approver-roster
 * agency check. Spec: .ai/specs/syndication-approval-gate.md
 *
 * Every assertion that "nothing reached the portal" uses Http::assertNothingSent().
 */
class SyndicationApprovalBackstopTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $approver;

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

        Http::fake();
        $this->actingAs($this->agent);
    }

    private function property(array $overrides = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4),
            'address' => '12 Beach Road', 'suburb' => 'Uvongo', 'city' => 'Margate', 'province' => 'KZN',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
            'published_at' => now(), 'compliance_snapshot_at' => now(),
        ], $overrides));
    }

    private function switchGateOn(?array $approverIds = null): void
    {
        PerformanceSetting::set(
            SyndicationApprovalService::SETTING_APPROVERS,
            json_encode($approverIds ?? [$this->approver->id]),
            $this->agency->id
        );
        PerformanceSetting::set(SyndicationApprovalService::SETTING_REQUIRED, 1, $this->agency->id);
    }

    private function websiteKey(): AgencyApiKey
    {
        $minted = AgencyApiKey::mintSecret();

        return AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Main Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
    }

    // ── refusalFor: the one shared check ────────────────────────────────

    public function test_refusal_is_null_when_the_feature_is_off_or_the_listing_is_approved(): void
    {
        $svc = app(SyndicationApprovalService::class);
        $p   = $this->property();

        $this->assertNull($svc->refusalFor($p, 'Property24'));

        $this->switchGateOn();
        $refusal = $svc->refusalFor($p, 'Property24');
        $this->assertFalse($refusal['success']);
        $this->assertTrue($refusal['approval_required']);

        $svc->approve($p, $this->approver);
        $this->assertNull($svc->refusalFor($p->fresh(), 'Property24'));
    }

    // ── Service chokepoints ─────────────────────────────────────────────

    public function test_p24_new_publish_and_return_to_market_refuse_an_unapproved_listing_without_any_portal_call(): void
    {
        $this->switchGateOn();
        // NEW listing: never sent to P24 (no ref). Off-portal listing: P24 was told to take it off.
        $new = $this->property();
        $off = $this->property(['p24_ref' => '12345', 'p24_syndication_status' => Property::PORTAL_OFF_STATUS]);

        $service = app(Property24SyndicationService::class);

        $this->assertFalse($service->submitListing($new)['success']);
        $this->assertFalse($service->submitListing($off)['success']);
        $this->assertFalse($service->reactivateListing($off)['success']);

        Http::assertNothingSent();
    }

    public function test_pp_new_publish_and_return_to_market_refuse_an_unapproved_listing_without_any_portal_call(): void
    {
        $this->switchGateOn();
        $new = $this->property();
        $off = $this->property(['pp_ref' => 'PP1', 'pp_syndication_status' => Property::PORTAL_OFF_STATUS]);

        $service = app(PrivatePropertySyndicationService::class);

        $this->assertTrue($service->submitListing($new)['approval_required'] ?? false);
        $this->assertTrue($service->submitListing($off)['approval_required'] ?? false);
        $this->assertTrue($service->reactivateListing($off)['approval_required'] ?? false);

        Http::assertNothingSent();
    }

    // ── Revoke / not-yet-grandfathered leaves LIVE listings alone ───────

    public function test_update_refusal_lets_an_already_live_listing_through_but_never_a_new_or_off_portal_one(): void
    {
        $this->switchGateOn();
        $svc = app(SyndicationApprovalService::class);

        // Live on P24 / PP (portal ref, not taken off) but carrying no approval stamp
        // (revoked, or the grandfather job has not run yet).
        $liveP24 = $this->property(['p24_ref' => '111', 'p24_syndication_status' => 'active']);
        $livePp  = $this->property(['pp_ref' => 'PP9', 'pp_syndication_status' => 'active']);

        $this->assertNull($svc->refusalForUpdate($liveP24, 'Property24'));
        $this->assertNull($svc->refusalForUpdate($livePp, 'Private Property'));

        // Live on P24 says nothing about PP, and vice versa.
        $this->assertNotNull($svc->refusalForUpdate($liveP24, 'Private Property'));
        $this->assertNotNull($svc->refusalForUpdate($livePp, 'Property24'));

        // NEW listing (no portal ref) and a listing taken off the portal stay gated.
        $this->assertNotNull($svc->refusalForUpdate($this->property(), 'Property24'));
        $this->assertNotNull($svc->refusalForUpdate($this->property(['p24_ref' => '5', 'p24_syndication_status' => Property::PORTAL_OFF_STATUS]), 'Property24'));
        $this->assertNotNull($svc->refusalForUpdate($this->property(['pp_ref' => 'X', 'pp_syndication_status' => Property::PORTAL_OFF_STATUS]), 'Private Property'));

        // And the strict check used for new publishing is unchanged.
        $this->assertNotNull($svc->refusalFor($liveP24, 'Property24'));
    }

    public function test_the_pp_status_sync_job_still_refuses_to_return_an_off_portal_listing_to_market_when_unapproved(): void
    {
        $this->switchGateOn();
        $off = $this->property(['pp_syndication_enabled' => true, 'pp_ref' => 'PP2', 'pp_syndication_status' => Property::PORTAL_OFF_STATUS, 'status' => 'active']);

        (new SyncPpListingStatusJob($off->id))->handle(app(PrivatePropertySyndicationService::class));

        Http::assertNothingSent();
    }

    public function test_website_enable_is_refused_but_disable_always_works(): void
    {
        $this->switchGateOn();
        $p   = $this->property();
        $key = $this->websiteKey();
        $svc = app(WebsiteSyndicationService::class);

        try {
            $svc->setEnabled($p, $key, true);
            $this->fail('Enabling an unapproved listing on a website must throw.');
        } catch (SyndicationApprovalRequiredException $e) {
            $this->assertTrue(true);
        }

        $this->assertFalse($svc->setEnabled($p, $key, false)->enabled);
    }

    // ── Queued job re-check ─────────────────────────────────────────────

    public function test_a_queued_p24_submit_is_skipped_when_approval_was_revoked_meanwhile(): void
    {
        $this->switchGateOn();
        $p = $this->property(['p24_syndication_status' => 'submitting']);

        (new SubmitListingToProperty24($p))->handle(app(Property24SyndicationService::class));

        Http::assertNothingSent();
        $this->assertSame('error', $p->fresh()->p24_syndication_status);
    }

    public function test_the_pp_status_sync_job_does_not_republish_an_unapproved_listing(): void
    {
        $this->switchGateOn();
        $p = $this->property(['pp_syndication_enabled' => true, 'pp_ref' => 'PP1', 'pp_syndication_status' => Property::PORTAL_OFF_STATUS, 'status' => 'active']);

        (new SyncPpListingStatusJob($p->id))->handle(app(PrivatePropertySyndicationService::class));

        Http::assertNothingSent();
    }

    // ── Request / cancel / reject hardening ─────────────────────────────

    public function test_requesting_twice_leaves_exactly_one_pending_row(): void
    {
        $this->switchGateOn();
        $p   = $this->property();
        $svc = app(SyndicationApprovalService::class);

        $first  = $svc->request($p, $this->agent);
        $second = $svc->request($p, $this->agent);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PropertySyndicationApproval::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $p->id)->where('status', PropertySyndicationApproval::STATUS_PENDING)->count());
    }

    public function test_only_the_requester_or_an_approver_can_cancel_a_pending_request(): void
    {
        $this->switchGateOn();
        $p = $this->property();
        app(SyndicationApprovalService::class)->request($p, $this->agent);

        $other = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);

        $this->actingAs($other)
            ->postJson(route('corex.properties.syndication-approval.cancel', $p))
            ->assertForbidden();

        $this->actingAs($this->agent)
            ->postJson(route('corex.properties.syndication-approval.cancel', $p))
            ->assertOk();
    }

    public function test_cancel_and_reject_report_when_nothing_was_pending(): void
    {
        $this->switchGateOn();
        $p = $this->property();

        $this->actingAs($this->agent)
            ->postJson(route('corex.properties.syndication-approval.cancel', $p))
            ->assertStatus(422);

        $this->actingAs($this->approver)
            ->postJson(route('corex.properties.syndication-approval.reject', $p), ['reason' => 'No.'])
            ->assertStatus(422);
    }

    // ── Approver roster stays inside the agency ─────────────────────────

    public function test_a_foreign_agency_user_cannot_be_put_on_the_approver_roster(): void
    {
        $admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        // Explicit grants (seeding any grant leaves the unseeded "allow all" test
        // fallback, so the permissions this route needs must be stated).
        foreach (['access_settings', 'properties.syndication.manage_approvers'] as $key) {
            \App\Models\RolePermission::create(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }

        $other       = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        // setUp() has an authenticated agent, and BelongsToAgency would force any row created
        // now onto that agent's agency — stamping must be suppressed for the foreign rows.
        $otherBranch = Branch::withoutAgencyStamping(fn () => Branch::create(['agency_id' => $other->id, 'name' => 'Main']));
        $foreign     = User::withoutAgencyStamping(fn () => User::factory()->create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent',
        ]));
        $this->assertSame($other->id, (int) $foreign->fresh()->agency_id);

        $this->actingAs($admin)
            ->post(route('corex.settings.syndication-portals'), [
                'syndication_approval_required' => 1,
                'syndication_approver_user_ids' => [$foreign->id, $this->approver->id],
            ]);

        $this->assertSame([$this->approver->id], SyndicationApprovalService::approverIdsFor($this->agency->id));

        // A roster of only foreign ids is the same as an empty one: refused.
        $this->actingAs($admin)
            ->post(route('corex.settings.syndication-portals'), [
                'syndication_approval_required' => 1,
                'syndication_approver_user_ids' => [$foreign->id],
            ])
            ->assertSessionHasErrors('syndication_approver_user_ids');
    }
}
