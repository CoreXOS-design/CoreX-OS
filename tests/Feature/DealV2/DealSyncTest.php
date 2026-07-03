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
 * WS1 (AT-158 / DR2, D1) — the single-writer DR1↔DR2 mirror + parity harness.
 *
 * Proves: shared core fields mirror BOTH ways between a linked deals ↔ deals_v2
 * pair (status via the round-trip map, price, commission total, registration
 * date); the mirror is re-entrancy-safe (no loop); DR2-only pipeline data never
 * leaks into DR1; and deals:parity-check reports 0 mismatches on a synced pair.
 */
final class DealSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_dr2_status_change_mirrors_to_dr1(): void
    {
        [$v1, $v2] = $this->linkedPair();

        $v2->update(['status' => 'granted']);

        $v1->refresh();
        $this->assertSame('G', $v1->accepted_status, 'DR2 granted → DR1 accepted_status G');
        $this->assertNotNull($v1->granted_at, 'granted_at stamped on DR1');

        $v2->update(['status' => 'completed', 'actual_registration' => '2026-05-01']);
        $v1->refresh();
        $this->assertSame('R', $v1->accepted_status, 'DR2 completed → DR1 R');
        $this->assertSame('2026-05-01', $v1->registration_date->format('Y-m-d'), 'registration date mirrored');
    }

    public function test_dr1_status_change_mirrors_to_dr2(): void
    {
        [$v1, $v2] = $this->linkedPair();

        $v1->update(['accepted_status' => 'G', 'granted_at' => now()]);
        $this->assertSame('granted', $v2->fresh()->status, 'DR1 granted → DR2 granted');

        $v1->update(['accepted_status' => 'R', 'registration_date' => '2026-06-01']);
        $v2->refresh();
        $this->assertSame('completed', $v2->status, 'DR1 registered → DR2 completed');
        $this->assertSame('2026-06-01', $v2->actual_registration->format('Y-m-d'));
    }

    public function test_commission_and_price_mirror_both_ways(): void
    {
        [$v1, $v2] = $this->linkedPair();

        // DR1 → DR2
        $v1->update(['total_commission' => 115000, 'sale_price' => 2_000_000]);
        $v2->refresh();
        $this->assertEqualsWithDelta(115000, (float) $v2->commission_amount + (float) $v2->commission_vat, 0.01, 'commission total mirrors DR1→DR2');
        $this->assertSame(2_000_000, (int) $v2->purchase_price);

        // DR2 → DR1
        $v2->update(['commission_amount' => 90000, 'commission_vat' => 13500, 'purchase_price' => 2_500_000]);
        $v1->refresh();
        $this->assertEqualsWithDelta(103500, (float) $v1->total_commission, 0.01, 'commission total mirrors DR2→DR1');
        $this->assertSame(2_500_000, (int) $v1->sale_price);
    }

    public function test_mirror_is_re_entrancy_safe_and_dr2_pipeline_does_not_leak(): void
    {
        [$v1, $v2] = $this->linkedPair();

        // A DR2-only field change (overall_rag) must not corrupt DR1 shared fields,
        // and must not loop. DR1 has no pipeline columns → nothing leaks.
        $before = $v1->only(['accepted_status', 'sale_price', 'total_commission']);
        $v2->update(['overall_rag' => 'red']);
        $v1->refresh();
        $this->assertSame($before, $v1->only(['accepted_status', 'sale_price', 'total_commission']),
            'DR2-only field change leaves DR1 shared fields untouched');
        // No pipeline column exists on DR1 at all — the schema itself guarantees no leak.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('deals', 'current_rag'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('deals', 'pipeline_template_id'));
    }

    public function test_parity_check_reports_zero_mismatch_on_synced_pair(): void
    {
        [$v1, $v2] = $this->linkedPair();
        $v1->update(['accepted_status' => 'G', 'granted_at' => now(), 'total_commission' => 100000, 'sale_price' => 1_800_000]);

        $code = Artisan::call('deals:parity-check');
        $this->assertSame(0, $code, 'synced pair is in parity: ' . Artisan::output());
    }

    public function test_parity_check_detects_and_fix_converges_a_drifted_pair(): void
    {
        [$v1, $v2] = $this->linkedPair();
        // Force drift on the DR2 side WITHOUT firing the mirror (quiet write).
        $v2->forceFill(['status' => 'cancelled'])->saveQuietly();

        $this->assertSame(1, Artisan::call('deals:parity-check'), 'drift detected');
        // --fix converges but its exit code still reflects the drift it FOUND (=1).
        Artisan::call('deals:parity-check', ['--fix' => true]);
        $this->assertSame(0, Artisan::call('deals:parity-check'), 'a clean run after --fix reports parity');
        $this->assertSame('active', $v2->fresh()->status, 'DR2 re-mirrored to DR1 state (active)');
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /** @return array{0:Deal,1:DealV2} a fully-linked DR1↔DR2 pair */
    private function linkedPair(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '9 Reef Rd', 'address' => '9 Reef Rd',
            'agent_id' => $agent->id, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
        ]));
        $template = DealPipelineTemplate::create([
            'name' => 'T', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $agent->id,
        ]);

        // DR2 twin first (no legacy link yet → its observer sync is a no-op).
        $v2 = DealV2::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'reference' => DealV2::generateReference(), 'deal_type' => 'bond',
            'status' => 'active', 'property_id' => $property->id, 'listing_agent_id' => $agent->id,
            'pipeline_template_id' => $template->id, 'purchase_price' => 1_500_000,
            'commission_amount' => 75_000, 'commission_vat' => 11_250, 'commission_status' => 'Not Paid',
            'offer_date' => '2026-03-01', 'overall_rag' => 'grey', 'branch_id' => $agencyId, 'created_by_id' => $agent->id,
        ]);

        // DR1 deal, linked to the twin → creation fires the DR1→DR2 mirror.
        $v1 = Deal::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'deal_v2_id' => $v2->id,
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_500_000,
            'total_commission' => 86_250, 'sale_price' => 1_500_000,
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'seller_name' => 'S Seller', 'buyer_name' => 'B Buyer',
        ]);

        // Back-link the twin (quiet — no re-sync).
        $v2->forceFill(['legacy_deal_id' => $v1->id])->saveQuietly();

        return [$v1->fresh(), $v2->fresh()];
    }
}
