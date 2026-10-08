<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealSettlement;
use App\Models\User;
use App\Services\DealMoneyLineRebuilder;
use App\Services\DealV2\DealTwinIntegrityService;
use App\Services\Finance\DealMoney;
use App\Services\Finance\SettlementScreenRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, 8 Oct 2026: "the SAVED figures are the figures of record" — the settlement screen must
 * show the saved figures (no stored figure changes). On QA1 the screen worked unrounded and 65 of
 * 167 deals differed by 1-2 cents; 3 differed by thousands because a departed agent was dropped
 * from the screen. These tests pin screen = saved, to the cent, for every shape; the same check
 * runs over every real deal via `php artisan deals:parity-check` (screen-vs-saved).
 */
final class SettlementScreenEqualsSavedTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Screen Co ' . Str::random(5), 'slug' => 'screen-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** name => [incVat, listingSplit, listingExt, sellingSplit, sellingExt, [[side, split, cut, payeMethod, payeValue]...]] */
    public static function shapes(): array
    {
        return [
            'the 1-cent tie from QA1 (R10,000 x 100% x 70% cut)' => ['10000.00', '100', false, '0', false, [['listing', '100', '70', 'percentage', '18']]],
            'two agents on a side, odd thirds' => ['58650.00', '50', false, '50', false, [['listing', '33.33', '60', 'percentage', '18'], ['listing', '66.67', '55', 'percentage', '18'], ['selling', '100', '70', 'percentage', '27.5']]],
            'external listing, one selling agent' => ['58650.00', '50', true, '50', false, [['selling', '100', '60', 'percentage', '18']]],
            'cents that do not divide' => ['12345.67', '50', false, '50', false, [['listing', '100', '65', 'percentage', '18'], ['selling', '100', '65', 'percentage', '18']]],
            'fixed PAYE not yet paid' => ['115000.00', '50', false, '50', false, [['listing', '100', '60', 'fixed', '500'], ['selling', '100', '60', 'percentage', '18']]],
            'deductions' => ['115000.00', '60', false, '40', false, [['listing', '100', '50', 'percentage', '18', '350.50'], ['selling', '100', '50', 'percentage', '18']]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shapes')]
    public function test_the_settlement_screen_shows_exactly_what_is_saved(string $inc, string $lSplit, bool $lExt, string $sSplit, bool $sExt, array $agents): void
    {
        $deal = $this->deal($inc, $lSplit, $lExt, $sSplit, $sExt, $agents);

        $this->assertSame([], $this->failures($deal), 'screen = saved, to the cent');
    }

    public function test_a_departed_agent_is_still_on_the_screen_and_the_split_matches_what_is_saved(): void
    {
        $deal = $this->deal('391000.00', '50', true, '50', false, [['selling', '50', '70', 'percentage', '18'], ['selling', '50', '50', 'percentage', '18']]);
        $departed = (int) DB::table('deal_user')->where('deal_id', $deal->id)->orderBy('id')->value('user_id');
        User::withoutGlobalScopes()->where('id', $departed)->update(['is_active' => false, 'deleted_at' => now()]);

        $rows = SettlementScreenRows::build($deal->fresh(), 'selling', DealMoneyLineRebuilder::computeDealPools($deal->fresh())['sellingPool'], collect());

        $agents = array_values(array_filter($rows, fn ($r) => $r['user_id'] !== 0));
        $this->assertCount(2, $agents, 'the departed agent is not dropped');
        $this->assertSame(85000.0, $agents[0]['allocated'], '50% of R170,000 — not 100% for the remaining agent');
        $this->assertSame([], $this->failures($deal->fresh()));
    }

    public function test_a_saved_line_stays_exactly_as_saved_when_it_matches_the_deals_inputs(): void
    {
        $deal = $this->deal('10000.00', '100', false, '0', false, [['listing', '100', '70', 'percentage', '18']]);
        // The line was saved a cent away from what today's rounding gives (the QA1 case).
        DB::table('deal_money_lines')->where('deal_id', $deal->id)->update(['agent_gross_ex_vat' => '6086.94', 'agent_net_ex_vat' => '4991.29', 'company_gross_ex_vat' => '2608.71']);

        $rows = SettlementScreenRows::build($deal->fresh(), 'listing', DealMoneyLineRebuilder::computeDealPools($deal->fresh())['listingPool'], collect());

        $this->assertSame(6086.94, $rows[0]['gross']);
        $this->assertSame(4991.29, $rows[0]['net']);
        $this->assertSame([], $this->failures($deal->fresh()));
    }

    public function test_a_stale_saved_line_is_shown_fresh_and_the_guard_reports_it(): void
    {
        $deal = $this->deal('58650.00', '50', false, '50', false, [['listing', '100', '60', 'percentage', '18']]);
        // The deal changes (side now handled by the other agency) but its lines are not rebuilt.
        DB::table('deals')->where('id', $deal->id)->update(['listing_external' => 1]);

        $failures = $this->failures($deal->fresh());

        $this->assertNotEmpty($failures);
        $this->assertStringContainsString('settlement screen shows', $failures[0]);
    }

    public function test_a_stale_line_after_a_split_change_shows_the_new_figure_not_the_old_one(): void
    {
        $deal = $this->deal('58650.00', '50', false, '50', false, [['listing', '100', '60', 'percentage', '18']]);
        DB::table('deals')->where('id', $deal->id)->update(['listing_split_percent' => 40, 'selling_split_percent' => 60]);

        $pool = DealMoneyLineRebuilder::computeDealPools($deal->fresh())['listingPool'];
        $rows = SettlementScreenRows::build($deal->fresh(), 'listing', $pool, collect());

        $this->assertSame(20400.0, $rows[0]['allocated'], '40% of R51,000, not the old saved R25,500');
    }

    // ── fixtures ──

    /** @return string[] the guard's findings for this deal, as text */
    private function failures(Deal $deal): array
    {
        $lines = DB::table('deal_money_lines')->where('deal_id', $deal->id)->whereNull('deleted_at')->get();

        return array_map(fn ($f) => $f['message'], app(DealTwinIntegrityService::class)->auditScreen($deal, $lines));
    }

    private function deal(string $inc, string $lSplit, bool $lExt, string $sSplit, bool $sExt, array $agents): Deal
    {
        $deal = Deal::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'deal_no' => (string) random_int(10000, 99999),
            'period' => '2026-08', 'deal_date' => '2026-08-10', 'property_value' => 680000, 'total_commission' => $inc,
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'listing_split_percent' => $lSplit, 'listing_external' => $lExt ? 1 : 0, 'listing_our_share_percent' => $lExt ? 0 : 100,
            'selling_split_percent' => $sSplit, 'selling_external' => $sExt ? 1 : 0, 'selling_our_share_percent' => $sExt ? 0 : 100,
        ]);
        foreach ($agents as $a) {
            [$side, $split, $cut, $method, $value] = $a;
            $user = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent']);
            DB::table('deal_user')->insert([
                'deal_id' => $deal->id, 'user_id' => $user->id, 'side' => $side, 'agent_split_percent' => $split,
                'agent_cut_percent' => $cut, 'paye_method' => $method, 'paye_value' => $value, 'deductions' => $a[5] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DealMoneyLineRebuilder::rebuildDealId($deal->id);

        return Deal::withoutGlobalScopes()->find($deal->id);
    }
}
