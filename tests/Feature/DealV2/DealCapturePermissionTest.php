<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 WS-V3 (Ruling b) — capture defaults to BM/admin; an agency can grant
 * agents `deals_v2.capture_own`, which lets them capture only the deals they are
 * on (clampScope). A plain agent (no capture grant) cannot capture at all.
 */
final class DealCapturePermissionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private DealPipelineTemplate $template;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $creator = $this->user('admin');
        $this->template = $this->makeTemplate($creator->id);
        $this->property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title' => '9 Marine Drive, Margate', 'address' => '9 Marine Drive, Margate',
            'agent_id' => $creator->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));
    }

    public function test_plain_agent_without_capture_grant_cannot_capture(): void
    {
        $this->seedRole('agent', ['access_deal_register_v2', 'deals_v2.view', 'deals_v2.edit']);
        $agent = $this->user('agent');

        $resp = $this->actingAs($agent)->post(route('deals-v2.store'), $this->payload($agent->id));
        $resp->assertForbidden(); // 403 — capture is BM/admin by default
        $this->assertSame(0, DealV2::withoutGlobalScopes()->count());
    }

    public function test_agent_with_capture_own_can_capture_a_deal_they_are_on(): void
    {
        $this->seedRole('agent', ['access_deal_register_v2', 'deals_v2.view', 'deals_v2.edit', 'deals_v2.capture_own']);
        $agent = $this->user('agent');

        $resp = $this->actingAs($agent)->post(route('deals-v2.store'), $this->payload($agent->id));
        $resp->assertRedirect();
        $this->assertSame(1, DealV2::withoutGlobalScopes()->count());
        $this->assertSame($agent->id, DealV2::withoutGlobalScopes()->first()->listing_agent_id);
    }

    public function test_agent_with_capture_own_cannot_capture_a_deal_they_are_not_on(): void
    {
        $this->seedRole('agent', ['access_deal_register_v2', 'deals_v2.view', 'deals_v2.edit', 'deals_v2.capture_own']);
        $agent = $this->user('agent');
        $other = $this->user('agent');

        // A deal built with ONLY the other agent — the capturer isn't on it.
        $resp = $this->actingAs($agent)->post(route('deals-v2.store'), $this->payload($other->id));
        $resp->assertSessionHasErrors();
        $this->assertSame(0, DealV2::withoutGlobalScopes()->count(), 'not captured — agent not on the deal');
    }

    public function test_full_capture_holder_is_unrestricted(): void
    {
        $this->seedRole('branch_manager', ['access_deal_register_v2', 'deals_v2.view', 'deals_v2.edit', 'deals_v2.create']);
        $bm = $this->user('branch_manager');
        $other = $this->user('agent');

        // BM captures a deal for another agent (not themselves) — allowed.
        $resp = $this->actingAs($bm)->post(route('deals-v2.store'), $this->payload($other->id));
        $resp->assertRedirect();
        $this->assertSame(1, DealV2::withoutGlobalScopes()->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function seedRole(string $role, array $permKeys): void
    {
        Role::create(['name' => $role, 'label' => ucfirst($role), 'agency_id' => $this->agencyId]);
        foreach ($permKeys as $key) {
            RolePermission::create(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        Role::clearCache();
        \App\Services\PermissionService::clearCache();
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'role' => $role, 'is_active' => true, 'is_admin' => false,
        ]);
    }

    private function payload(int $listingAgentId): array
    {
        return [
            'property_id' => $this->property->id,
            'deal_type' => 'bond',
            'pipeline_template_id' => $this->template->id,
            'purchase_price' => 1_950_000,
            'commission_amount' => 97_500,
            'commission_vat' => 14_625,
            'offer_date' => '2026-03-01',
            'listing_split_percent' => 50,
            'selling_split_percent' => 50,
            'agents' => [['user_id' => $listingAgentId, 'side' => 'listing']],
        ];
    }

    private function makeTemplate(int $creatorId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Cap Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);
        DealPipelineStep::create([
            'pipeline_template_id' => $template->id, 'agency_id' => $this->agencyId,
            'position' => 1, 'name' => 'OTP Signed', 'is_locked' => false, 'is_milestone' => true,
            'completion_type' => 'date_input', 'trigger_type' => 'on_creation', 'days_offset' => 0,
            'rag_amber_days' => 7, 'rag_red_days' => 3,
            'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
        ]);

        return $template;
    }
}
