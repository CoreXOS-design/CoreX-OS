<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * deals:align-v2-twins — corrects the stale saved copy on a linked v2 row to the deal of record
 * (Johan, 8 Oct 2026). Dry-run by default of nothing: it refuses without --dry-run or --confirm,
 * writes nothing in a dry run, applies in a transaction with an audit entry per value, never
 * touches the real deal, and is idempotent so the same command can be run on Staging and live.
 */
final class AlignV2TwinsCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Align Co ' . Str::random(5), 'slug' => 'align-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent']);
    }

    public function test_it_refuses_without_dry_run_or_confirm_and_without_run_by(): void
    {
        [$deal, $v2] = $this->stalePair();

        $this->assertSame(2, Artisan::call('deals:align-v2-twins'));
        $this->assertStringContainsString('Refusing to run', Artisan::output());
        $this->assertSame(2, Artisan::call('deals:align-v2-twins', ['--confirm' => true]));
        $this->assertStringContainsString('--run-by', Artisan::output());

        $this->assertSame(0, (int) DB::table('deals_v2')->where('id', $v2)->value('listing_external'), 'nothing was written');
    }

    public function test_dry_run_prints_before_and_after_and_writes_nothing(): void
    {
        [$deal, $v2] = $this->stalePair();

        $this->assertSame(0, Artisan::call('deals:align-v2-twins', ['--dry-run' => true]));
        $out = Artisan::output();

        $this->assertStringContainsString("v2 row {$v2}", $out);
        $this->assertStringContainsString('listing_external', $out);
        $this->assertStringContainsString('0  →  1', $out);
        $this->assertStringContainsString('purchase_price', $out);
        $this->assertSame(0, (int) DB::table('deals_v2')->where('id', $v2)->value('listing_external'));
        $this->assertSame(0, DB::table('deal_logs')->where('event_type', 'dr2_single_source_alignment')->count());
    }

    public function test_confirm_aligns_the_row_logs_each_value_and_leaves_the_deal_alone(): void
    {
        [$deal, $v2] = $this->stalePair();
        $dealBefore = DB::table('deals')->where('id', $deal->id)->first();

        $this->assertSame(0, Artisan::call('deals:align-v2-twins', ['--confirm' => true, '--run-by' => 'cc2 for Johan']));

        $row = DB::table('deals_v2')->where('id', $v2)->first();
        $this->assertSame(1, (int) $row->listing_external);
        $this->assertSame('0.00', number_format((float) $row->listing_our_share_percent, 2, '.', ''));
        $this->assertSame('60.00', number_format((float) $row->listing_split_percent, 2, '.', ''));
        $this->assertSame('40.00', number_format((float) $row->selling_split_percent, 2, '.', ''));
        $this->assertSame(1_525_000, (int) $row->purchase_price);

        $this->assertEquals($dealBefore, DB::table('deals')->where('id', $deal->id)->first(), 'the real deal is never written');

        $logs = DB::table('deal_logs')->where('deal_id', $deal->id)->where('event_type', 'dr2_single_source_alignment')->get();
        $this->assertCount(5, $logs, 'one audit entry per changed value (external, our_share, 2 splits, price)');
        $ext = $logs->firstWhere('from_value', '0');
        $this->assertSame('1', $ext->to_value);
        $this->assertStringContainsString('aligned to the deal of record - DR2 single source, approved by Johan 8 Oct 2026', $ext->message);
        $this->assertStringContainsString('cc2 for Johan', $ext->message);
        $this->assertSame(5, DB::table('deal_activity_log')->where('deal_id', $v2)->where('action', 'dr2_single_source_alignment')->count());
    }

    public function test_it_is_idempotent_and_a_second_run_finds_nothing(): void
    {
        $this->stalePair();
        Artisan::call('deals:align-v2-twins', ['--confirm' => true, '--run-by' => 'x']);
        $logs = DB::table('deal_logs')->count();

        $this->assertSame(0, Artisan::call('deals:align-v2-twins', ['--confirm' => true, '--run-by' => 'x']));
        $this->assertStringContainsString('Nothing to align', Artisan::output());
        $this->assertSame($logs, DB::table('deal_logs')->count(), 'no new audit entries');
    }

    public function test_a_row_with_both_sides_external_is_aligned_without_tripping_the_save_guard(): void
    {
        [$deal, $v2] = $this->stalePair(['listing_external' => 1, 'selling_external' => 1, 'listing_split_percent' => 50, 'selling_split_percent' => 50]);

        $this->assertSame(0, Artisan::call('deals:align-v2-twins', ['--confirm' => true, '--run-by' => 'x']));
        $row = DB::table('deals_v2')->where('id', $v2)->first();
        $this->assertSame([1, 1], [(int) $row->listing_external, (int) $row->selling_external]);
    }

    public function test_an_archived_deal_and_a_link_across_agencies(): void
    {
        [$deal, $v2] = $this->stalePair();
        DB::table('deals')->where('id', $deal->id)->update(['deleted_at' => now()]);
        Artisan::call('deals:align-v2-twins', ['--dry-run' => true]);
        $this->assertStringContainsString("v2 row {$v2}", Artisan::output(), 'an archived deal\'s live v2 row is still aligned');

        $other = (int) DB::table('agencies')->insertGetId(['name' => 'Other', 'slug' => 'o-' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('deals_v2')->where('id', $v2)->update(['agency_id' => $other]);
        Artisan::call('deals:align-v2-twins', ['--confirm' => true, '--run-by' => 'x']);
        $this->assertStringContainsString('crosses agencies', Artisan::output());
        $this->assertSame(0, (int) DB::table('deals_v2')->where('id', $v2)->value('listing_external'), 'never touched');
    }

    /** @return array{0:Deal,1:int} a deal that is listing-external at 60/40 and a v2 row with neutral defaults and an old price */
    private function stalePair(array $over = []): array
    {
        $deal = Deal::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'deal_no' => (string) random_int(10000, 99999),
            'period' => '2026-08', 'deal_date' => '2026-08-10', 'property_value' => 1_525_000, 'total_commission' => 58650,
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'listing_split_percent' => 60, 'listing_external' => 1, 'listing_our_share_percent' => 0,
            'selling_split_percent' => 40, 'selling_external' => 0, 'selling_our_share_percent' => 100,
        ], $over));
        $v2 = (int) DB::table('deals_v2')->insertGetId([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'legacy_deal_id' => $deal->id, 'reference' => 'DR1-' . $deal->id,
            'deal_type' => 'cash', 'status' => 'active', 'listing_agent_id' => $this->agent->id, 'purchase_price' => 1_510_000,
            'commission_amount' => '51000.00', 'commission_vat' => '7650.00', 'commission_status' => 'Not Paid', 'offer_date' => '2026-08-10',
            'overall_rag' => 'grey', 'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
            'listing_split_percent' => 50, 'selling_split_percent' => 50, 'listing_external' => 0, 'selling_external' => 0,
            'listing_our_share_percent' => 100, 'selling_our_share_percent' => 100,
        ]);
        DB::table('deals')->where('id', $deal->id)->update(['deal_v2_id' => $v2]);

        return [$deal->fresh(), $v2];
    }
}
