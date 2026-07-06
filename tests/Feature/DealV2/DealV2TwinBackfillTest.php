<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DR1 → DR2 twin backfill (Johan-ruled 2026-07-06; .ai/specs/dr2-twin-backfill.md).
 *
 * Backfilled twins make the DR2 register show the complete DR1 book, but carry
 * NO pipeline (Johan's amendment). Locks: idempotency, DR1 untouchability, no
 * pipeline objects on twins, honest status mapping, and the pre-pipeline flag.
 */
final class DealV2TwinBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
    }

    /** A DR1 deal with a listing-side agent and NO twin yet. */
    private function seedDr1Deal(array $overrides = []): Deal
    {
        $deal = Deal::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_500_000,
            'total_commission' => 86_250, 'sale_price' => 1_500_000,
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'seller_name' => 'S Seller', 'buyer_name' => 'B Buyer', 'property_address' => '9 Reef Rd',
        ], $overrides));

        DB::table('deal_user')->insert([
            'deal_id' => $deal->id, 'user_id' => $this->agent->id, 'side' => 'listing',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $deal->fresh();
    }

    public function test_backfill_creates_one_pre_pipeline_twin_per_dr1_deal(): void
    {
        $d1 = $this->seedDr1Deal();
        $d2 = $this->seedDr1Deal();

        Artisan::call('deals:backfill-v2-twins', ['--agency' => $this->agencyId]);

        $this->assertSame(2, DealV2::withoutGlobalScopes()->count());

        foreach ([$d1, $d2] as $deal) {
            $twin = DealV2::withoutGlobalScopes()->where('legacy_deal_id', $deal->id)->first();
            $this->assertNotNull($twin, 'a twin exists for the DR1 deal');
            $this->assertNull($twin->pipeline_template_id, 'twin carries NO pipeline template');
            $this->assertNull($twin->property_id, 'DR1 has no linked property');
            $this->assertNotNull($twin->backfilled_at, 'twin marked backfilled_at');
            $this->assertTrue($twin->isPrePipeline(), 'twin is pre-pipeline');
            $this->assertSame($this->agent->id, (int) $twin->listing_agent_id, 'listing agent resolved from deal_user');
            $this->assertSame((int) $twin->id, (int) $deal->fresh()->deal_v2_id, 'DR1 pointer set to twin');
        }
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->seedDr1Deal();
        Artisan::call('deals:backfill-v2-twins', ['--agency' => $this->agencyId]);
        Artisan::call('deals:backfill-v2-twins', ['--agency' => $this->agencyId]);

        $this->assertSame(1, DealV2::withoutGlobalScopes()->count(), 'no duplicate twin on re-run');
    }

    public function test_dr1_is_untouched_except_the_pointer(): void
    {
        $deal = $this->seedDr1Deal(['accepted_status' => 'G', 'granted_at' => now(), 'total_commission' => 100_000, 'sale_price' => 2_000_000]);
        $before = $deal->only(['accepted_status', 'sale_price', 'total_commission', 'commission_status', 'seller_name']);
        $countBefore = Deal::withoutGlobalScopes()->count();

        Artisan::call('deals:backfill-v2-twins', ['--agency' => $this->agencyId]);

        $deal->refresh();
        $this->assertSame($countBefore, Deal::withoutGlobalScopes()->count(), 'DR1 row count unchanged');
        $this->assertSame($before, $deal->only(['accepted_status', 'sale_price', 'total_commission', 'commission_status', 'seller_name']), 'DR1 fields unchanged');
        $this->assertNotNull($deal->deal_v2_id, 'only the additive pointer was written');
    }

    public function test_no_pipeline_objects_are_created_for_twins(): void
    {
        $this->seedDr1Deal();
        $this->seedDr1Deal();

        Artisan::call('deals:backfill-v2-twins', ['--agency' => $this->agencyId]);

        $this->assertSame(0, DB::table('deal_step_instances')->count(), 'no step instances for backfilled twins');
    }

    public function test_twin_status_maps_from_dr1_state(): void
    {
        $registered = $this->seedDr1Deal(['accepted_status' => 'R', 'registration_date' => '2026-05-01']);

        Artisan::call('deals:backfill-v2-twins', ['--agency' => $this->agencyId]);

        $twin = DealV2::withoutGlobalScopes()->where('legacy_deal_id', $registered->id)->first();
        $this->assertSame('completed', $twin->status, 'DR1 registered → DR2 completed');
        $this->assertSame('2026-05-01', $twin->actual_registration->format('Y-m-d'));
    }

    public function test_native_dr2_deal_is_not_pre_pipeline(): void
    {
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '1 Native Rd', 'address' => '1 Native Rd',
            'agent_id' => $this->agent->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));
        $template = DealPipelineTemplate::create([
            'name' => 'T', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->agent->id,
        ]);
        $native = DealV2::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'reference' => DealV2::generateReference(), 'deal_type' => 'bond',
            'status' => 'active', 'property_id' => $property->id, 'listing_agent_id' => $this->agent->id,
            'pipeline_template_id' => $template->id, 'purchase_price' => 1_500_000,
            'commission_amount' => 75_000, 'commission_vat' => 11_250, 'commission_status' => 'Not Paid',
            'offer_date' => '2026-03-01', 'overall_rag' => 'grey', 'branch_id' => $this->agencyId, 'created_by_id' => $this->agent->id,
        ]);

        $this->assertFalse($native->isPrePipeline(), 'a natively-captured deal is not pre-pipeline');
        $this->assertNotNull($native->pipeline_template_id, 'native deal keeps its pipeline template');
    }
}
