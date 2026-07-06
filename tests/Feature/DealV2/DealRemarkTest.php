<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealRemark;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealPipelineService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 WS-V6 — deal remark thread (DR1 addRemark analogue). Agents remark on
 * deals in their scope (own); BM/admin on any in scope and may moderate. Soft
 * delete only, audited. Unseeded permissions → role-default scopes (agent=own,
 * branch_manager=branch) with the hasPermission bypass, which is exactly the
 * behaviour under test.
 */
final class DealRemarkTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private DealPipelineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        $this->svc = app(DealPipelineService::class);

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_agent_can_remark_on_their_own_deal(): void
    {
        $agent = $this->user('agent');
        $deal = $this->deal($agent);

        $resp = $this->actingAs($agent)->post(route('deals-v2.remarks.store', $deal), ['body' => 'Buyer confirmed bond appointment for Tuesday.']);
        $resp->assertRedirect(route('deals-v2.show', $deal->id));
        $this->assertSame(1, $deal->remarks()->count());
        $this->assertSame($agent->id, $deal->remarks()->first()->user_id);
    }

    public function test_agent_cannot_remark_on_a_deal_outside_their_scope(): void
    {
        $owner = $this->user('agent');
        $deal = $this->deal($owner);
        $stranger = $this->user('agent'); // own-scope, not on this deal

        $resp = $this->actingAs($stranger)->post(route('deals-v2.remarks.store', $deal), ['body' => 'Should not land.']);
        $resp->assertForbidden();
        $this->assertSame(0, $deal->remarks()->count());
    }

    public function test_author_can_soft_delete_own_remark_and_it_is_audited(): void
    {
        $agent = $this->user('agent');
        $deal = $this->deal($agent);
        $remark = DealRemark::create(['agency_id' => $this->agencyId, 'deal_id' => $deal->id, 'user_id' => $agent->id, 'body' => 'Oops, wrong deal.']);

        $resp = $this->actingAs($agent)->delete(route('deals-v2.remarks.destroy', $remark));
        $resp->assertRedirect();
        $this->assertSoftDeleted('deal_v2_remarks', ['id' => $remark->id]);
        $this->assertTrue(DealActivityLog::where('deal_id', $deal->id)->where('action', 'remark_removed')->exists());
    }

    public function test_agent_cannot_delete_another_users_remark(): void
    {
        $agent = $this->user('agent');
        $deal = $this->deal($agent);
        $bm = $this->user('branch_manager');
        // A remark authored by the BM on the agent's deal.
        $remark = DealRemark::create(['agency_id' => $this->agencyId, 'deal_id' => $deal->id, 'user_id' => $bm->id, 'body' => 'Chase the FICA docs.']);

        $resp = $this->actingAs($agent)->delete(route('deals-v2.remarks.destroy', $remark));
        $resp->assertForbidden(); // own-scope agent is neither author nor moderator
        $this->assertDatabaseHas('deal_v2_remarks', ['id' => $remark->id, 'deleted_at' => null]);
    }

    public function test_branch_manager_can_moderate_a_remark_in_scope(): void
    {
        $agent = $this->user('agent');
        $deal = $this->deal($agent);
        $bm = $this->user('branch_manager');
        $remark = DealRemark::create(['agency_id' => $this->agencyId, 'deal_id' => $deal->id, 'user_id' => $agent->id, 'body' => 'Sensitive note.']);

        $resp = $this->actingAs($bm)->delete(route('deals-v2.remarks.destroy', $remark));
        $resp->assertRedirect();
        $this->assertSoftDeleted('deal_v2_remarks', ['id' => $remark->id]);
    }

    public function test_timeline_interleaves_remarks_with_activity_log(): void
    {
        $this->withoutVite();
        $agent = $this->user('agent');
        $deal = $this->deal($agent); // createDeal writes a 'deal_created' activity entry
        DealRemark::create(['agency_id' => $this->agencyId, 'deal_id' => $deal->id, 'user_id' => $agent->id, 'body' => 'First contact made with the seller.']);

        $resp = $this->actingAs($agent)->get(route('deals-v2.show', $deal->id));
        $resp->assertOk();
        $resp->assertSee('Deal Timeline');
        $resp->assertSee('First contact made with the seller.');
        $resp->assertSee('created'); // the deal_created log line also present in the one stream
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'role' => $role, 'is_active' => true, 'is_admin' => false,
        ]);
    }

    private function deal(User $listingAgent): DealV2
    {
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '3 Marine Drive, Margate',
            'address' => '3 Marine Drive, Margate', 'agent_id' => $listingAgent->id,
            'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));

        $template = DealPipelineTemplate::create([
            'name' => 'Rem Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $listingAgent->id,
        ]);
        DealPipelineStep::create([
            'pipeline_template_id' => $template->id, 'agency_id' => $this->agencyId,
            'position' => 1, 'name' => 'OTP Signed', 'is_locked' => false, 'is_milestone' => true,
            'completion_type' => 'date_input', 'trigger_type' => 'on_creation', 'days_offset' => 0,
            'rag_amber_days' => 7, 'rag_red_days' => 3,
            'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
        ]);

        return $this->svc->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id,
            'listing_agent_id' => $listingAgent->id, 'pipeline_template_id' => $template->id,
            'purchase_price' => 1_800_000, 'commission_amount' => 90_000, 'commission_vat' => 13_500,
            'offer_date' => '2026-03-01', 'branch_id' => $this->agencyId, 'created_by_id' => $listingAgent->id,
            'agents' => [['side' => 'listing', 'user_id' => $listingAgent->id]],
        ]);
    }
}
