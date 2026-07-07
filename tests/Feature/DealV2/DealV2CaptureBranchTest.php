<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealPipelineTemplateProvisioner;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-192 (d) — DR2 branch attribution mirrors DR1: NEVER Branch::first().
 *
 * The old `auth()->user()->branch_id ?? Branch::first()?->id` landed a
 * NULL-home-branch capturer's deal on an unrelated branch (Shelly Beach). Since
 * the DR2 twin inherits this branch, a wrong stamp would propagate. The fix
 * prefers the capturer's effective branch and otherwise REQUIRES an explicit
 * choice — no silent fallback.
 */
final class DealV2CaptureBranchTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $homeBranchId;   // = agencyId (mirrors sibling test convention)
    private int $otherBranchId;
    private Property $property;
    private DealPipelineTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->homeBranchId = $this->agencyId;
        DB::table('branches')->insert([
            'id' => $this->homeBranchId, 'agency_id' => $this->agencyId, 'name' => 'Shelly Beach',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->otherBranchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Southbroom',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $seedAdmin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->homeBranchId,
            'role' => 'super_admin', 'is_admin' => true, 'is_active' => true,
        ]);

        $this->property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title' => '9 Forest Walk, Southbroom', 'address' => '9 Forest Walk, Southbroom',
            'agent_id' => $seedAdmin->id, 'branch_id' => $this->homeBranchId, 'agency_id' => $this->agencyId,
        ]));

        app(DealPipelineTemplateProvisioner::class)->provisionDefaultsForAgency($this->agencyId, $seedAdmin->id);
        $this->template = DealPipelineTemplate::withoutGlobalScopes()
            ->where('agency_id', $this->agencyId)->where('deal_type', 'bond')->first();
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function nullHomeAdmin(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => null,
            'role' => 'admin', 'is_admin' => true, 'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'property_id' => $this->property->id,
            'deal_type' => 'bond',
            'pipeline_template_id' => $this->template->id,
            'purchase_price' => 1_000_000,
            'total_commission_inc_vat' => 115_000,
            'commission_percentage' => 7.5,
            'offer_date' => '2026-07-01',
            'listing_split_percent' => 100,
            'selling_split_percent' => 0,
        ], $overrides);
    }

    public function test_null_home_capturer_without_branch_is_rejected_not_defaulted(): void
    {
        $admin = $this->nullHomeAdmin();

        $before = DealV2::withoutGlobalScopes()->count();

        $this->actingAs($admin)
            ->post(route('deals-v2.store'), $this->payload(['listing_agents' => [(string) $admin->id]]))
            ->assertSessionHasErrors('branch_id');

        $this->assertSame($before, DealV2::withoutGlobalScopes()->count(), 'No deal may land on Branch::first().');
    }

    public function test_null_home_capturer_with_explicit_branch_uses_that_branch(): void
    {
        $admin = $this->nullHomeAdmin();

        $this->actingAs($admin)->post(route('deals-v2.store'), $this->payload([
            'listing_agents' => [(string) $admin->id],
            'branch_id' => $this->otherBranchId,
        ]));

        $deal = DealV2::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($deal);
        $this->assertSame($this->otherBranchId, (int) $deal->branch_id, 'Explicit branch honoured, not Branch::first().');
    }

    public function test_capturer_with_home_branch_is_auto_stamped(): void
    {
        $admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->otherBranchId,
            'role' => 'admin', 'is_admin' => true, 'is_active' => true,
        ]);

        // No branch_id in the payload — resolves from the capturer's effective branch.
        $this->actingAs($admin)->post(route('deals-v2.store'), $this->payload([
            'listing_agents' => [(string) $admin->id],
        ]));

        $deal = DealV2::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($deal);
        $this->assertSame($this->otherBranchId, (int) $deal->branch_id);
    }
}
