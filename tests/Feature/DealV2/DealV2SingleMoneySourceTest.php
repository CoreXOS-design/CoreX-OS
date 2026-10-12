<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\DealV2\DealV2Settlement;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\DealMoneyLineRebuilder;
use App\Services\DealV2\DealSyncService;
use App\Services\DealV2\DealTwinIntegrityService;
use App\Services\Finance\DealMoney;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ONE SOURCE for a deal's money (2026-10-08, Johan: "ensure this cannot happen again ... follow
 * the money and not hit wrong figures because of duplicate dr2 deals").
 *
 * The incident: the v2 ("twin") copy of 10 linked deals — deal 1818 among them — had lost the
 * "other agency handled this side" marker, so the v2 settlement showed HFC's share DOUBLED
 * (R51,000 instead of R25,500). The fix makes the twin read the real deal's money, so there is no
 * second copy to diverge. These tests pin that, and the guard (deals:parity-check) that compares
 * both sides of every linked deal in cents.
 */
final class DealV2SingleMoneySourceTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $admin;
    private User $agentA;
    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Cape Rentals ' . Str::random(6), 'slug' => 'cape-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = $this->agencyId;
        DB::table('branches')->insert([
            'id' => $this->branchId, 'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = $this->user('admin', true);
        $this->agentA = $this->user('agent');
        $this->agentB = $this->user('agent');
    }

    // ── shapes: every combination a deal can have, with the figures worked out by hand ──

    /**
     * name => [incVat, listingSplit, listingExternal, sellingSplit, sellingExternal,
     *          expected [listingPool, sellingPool, ourTotal, listingExtPayable, sellingExtPayable] in CENTS]
     */
    public static function shapes(): array
    {
        return [
            // The real deal 1818: R58,650 incl VAT = R51,000 ex; listing handled by the other agency.
            'deal 1818 — listing external 50/50' => ['58650.00', '50', true, '50', false, [0, 2550000, 2550000, 2932500, 0]],
            'both sides ours, 50/50'            => ['58650.00', '50', false, '50', false, [2550000, 2550000, 5100000, 0, 0]],
            'selling external 60/40'            => ['115000.00', '60', false, '40', true, [6000000, 0, 6000000, 0, 4600000]],
            'listing external 80/20'            => ['115000.00', '80', true, '20', false, [0, 2000000, 2000000, 9200000, 0]],
            'both external (legacy allows it)'  => ['115000.00', '50', true, '50', true, [0, 0, 0, 5750000, 5750000]],
            'odd cents, thirds'                 => ['10000.01', '33.33', false, '66.67', false, [289826, 579740, 869566, 0, 0]],
            'odd cents, external third'         => ['10000.01', '33.33', true, '66.67', false, [0, 579740, 579740, 333300, 0]],
            'zero commission'                   => ['0.00', '50', false, '50', false, [0, 0, 0, 0, 0]],
        ];
    }

    #[DataProvider('shapes')]
    public function test_a_linked_v2_row_reads_the_real_deals_money_whatever_its_own_columns_say(
        string $inc, string $lSplit, bool $lExt, string $sSplit, bool $sExt, array $expect
    ): void {
        [$deal, $twin] = $this->linkedPair($inc, $lSplit, $lExt, $sSplit, $sExt);

        // The twin was built the way the backfill builds it: neutral defaults, nothing of the
        // deal's splits/external markers (the exact fault). Its own columns say "both ours, 50/50".
        $this->assertFalse((bool) $twin->listing_external);
        $this->assertSame('50.00', number_format((float) $twin->listing_split_percent, 2, '.', ''));

        [$lPool, $sPool, $our, $lPay, $sPay] = $expect;
        $this->assertSame($lPool, $twin->money()->sidePoolCents('listing'));
        $this->assertSame($sPool, $twin->money()->sidePoolCents('selling'));
        $this->assertSame($our, $twin->money()->ourTotalCents());
        $this->assertSame($lPay, $twin->money()->externalPayableCents('listing'));
        $this->assertSame($sPay, $twin->money()->externalPayableCents('selling'));

        // …and the float-returning accessors the v2 screens use hand back exactly those figures.
        $this->assertSame(DealMoney::toFloat($our), $twin->totalOurCommission());
        $this->assertSame(DealMoney::toFloat($lPool), $twin->listingPool());
        $this->assertSame(DealMoney::toFloat($sPool), $twin->sellingPool());

        // Same answer as the real deal, to the cent.
        $this->assertSame(DealMoney::fromDeal($deal)->snapshot(), $twin->money()->snapshot());
    }

    public function test_deal_1818_would_have_doubled_on_the_twins_own_copy_but_reads_right_through_the_deal(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', true, '50', false);

        // What the v2 settlement used to show: the twin's own copy treats both sides as ours.
        $this->assertSame(5100000, $twin->ownColumnsMoney()->ourTotalCents(), 'the stale copy would show R51,000');
        // What it shows now, and what the deal itself says.
        $this->assertSame(2550000, $twin->money()->ourTotalCents(), 'one source: R25,500');
        $this->assertSame(2550000, DealMoney::fromDeal($deal)->ourTotalCents());
    }

    public function test_the_twin_follows_a_change_to_the_real_deal_with_no_sync_in_between(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', false, '50', false);
        $this->assertSame(5100000, $twin->money()->ourTotalCents());

        // A raw write — no model event, no observer, nothing that could mirror it. The twin still agrees.
        DB::table('deals')->where('id', $deal->id)->update(['listing_external' => 1, 'total_commission' => '115000.00']);

        $this->assertSame(5000000, $twin->fresh()->money()->ourTotalCents());
    }

    public function test_a_linked_row_whose_deal_is_gone_fails_loudly_instead_of_using_its_own_copy(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', true, '50', false);
        DB::table('deals')->where('id', $deal->id)->delete();

        $this->expectException(\RuntimeException::class);
        $twin->fresh()->money();
    }

    public function test_a_native_v2_deal_with_no_real_deal_is_its_own_source(): void
    {
        $native = $this->twinRow(null, [
            'commission_amount' => '51000.00', 'commission_vat' => '7650.00',
            'listing_external' => 1, 'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);

        $this->assertFalse($native->hasLegacyMoneySource());
        $this->assertSame(2550000, $native->money()->ourTotalCents());
    }

    // ── no second copy can be written ──

    public function test_a_v2_save_never_writes_commission_back_over_the_real_deal(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', true, '50', false);
        $before = (string) Deal::withoutGlobalScopes()->find($deal->id)->getRawOriginal('total_commission');

        // An old/wrong commission on the twin, then an ordinary v2 save (a pipeline step does this).
        $twin->forceFill(['commission_amount' => 1, 'commission_vat' => 1])->saveQuietly();
        $twin->update(['overall_rag' => 'red']);

        $this->assertSame($before, (string) Deal::withoutGlobalScopes()->find($deal->id)->getRawOriginal('total_commission'));
    }

    public function test_the_real_deals_commission_still_flows_one_way_into_the_twin(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', true, '50', false);

        $deal->update(['total_commission' => 115000]);

        $twin->refresh();
        $this->assertSame(11500000, DealMoney::scaled($twin->commission_amount, 2) + DealMoney::scaled($twin->commission_vat, 2));
    }

    public function test_a_v2_edit_cannot_change_the_money_of_a_linked_deal(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', true, '50', false);

        $resp = $this->actingAs($this->admin)->put(route('deals-v2.update', $twin), [
            'notes' => 'checked',
            'total_commission_inc_vat' => '999999',
            'listing_split_percent' => 10, 'selling_split_percent' => 90,
            'purchase_price' => 5, 'offer_date' => '2026-01-01',
        ]);
        $resp->assertRedirect();

        $this->assertSame('58650.00', (string) Deal::withoutGlobalScopes()->find($deal->id)->getRawOriginal('total_commission'));
        $this->assertSame(2550000, $twin->fresh()->money()->ourTotalCents());
        $fresh = $twin->fresh();
        $this->assertSame('50.00', number_format((float) $fresh->listing_split_percent, 2, '.', ''), 'the twin\'s own splits are not written either');
        $this->assertSame('checked', $fresh->notes, 'non-money fields still save');
    }

    public function test_v2_settlement_screens_send_a_linked_deal_to_the_real_settlement_and_save_nothing(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', true, '50', false);
        $this->actingAs($this->admin);

        $this->get(route('deals-v2.settlement.index', $twin))->assertRedirect(route('deals-dr2.settle', $deal));
        $this->get(route('deals-v2.settlement.print', $twin))->assertRedirect(route('deals-dr2.settle.print', $deal));
        $this->get(route('deals-v2.settlement.payslip', [$twin, $this->agentA]))
            ->assertRedirect(route('deals-dr2.settle.print.agent', [$deal, $this->agentA]));

        $this->post(route('deals-v2.settlement.save', $twin), ['mark_paid' => 1, 'selling_share' => [$this->agentA->id => 100]])
            ->assertRedirect(route('deals-dr2.settle', $deal));

        $this->assertSame(0, DealV2Settlement::where('deal_id', $twin->id)->count(), 'no second settlement is written');
        $this->assertSame('Not Paid', (string) Deal::withoutGlobalScopes()->find($deal->id)->commission_status, 'v2 cannot flip the real deal to Paid');
    }

    public function test_the_database_refuses_a_second_v2_row_for_the_same_deal(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', true, '50', false);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->twinRow($deal->id, ['reference' => 'DUP-' . Str::random(6)]);
    }

    public function test_two_requests_creating_the_same_twin_end_up_with_one_row(): void
    {
        $deal = $this->deal('58650.00', '50', true, '50', false);
        DB::table('deal_user')->insert($this->dealUserRow($deal->id, $this->agentA->id, 'selling'));

        $sync = app(DealSyncService::class);
        $first = $sync->ensureTwin($deal);

        // The second caller read the deal before the first finished: it still has no pointer.
        $stale = Deal::withoutGlobalScopes()->find($deal->id);
        $stale->deal_v2_id = null;
        $second = $sync->ensureTwin($stale);

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('deals_v2')->where('legacy_deal_id', $deal->id)->count());
    }

    // ── the guard ──

    public function test_recalculating_every_linked_deal_finds_the_twin_the_deal_and_the_saved_lines_in_agreement(): void
    {
        $made = [];
        foreach (self::shapes() as $name => [$inc, $lSplit, $lExt, $sSplit, $sExt, $expect]) {
            $made[$name] = $this->linkedPair($inc, $lSplit, $lExt, $sSplit, $sExt, true);
        }

        $result = app(DealTwinIntegrityService::class)->audit();

        $this->assertSame(count(self::shapes()), $result['pairs'], 'every linked deal was compared');
        $fails = array_filter($result['findings'], fn ($f) => $f['severity'] === DealTwinIntegrityService::FAIL);
        $this->assertSame([], array_values($fails), 'no FAIL for any linked deal');

        foreach ($made as $name => [$deal, $twin]) {
            $real = DealMoney::fromDeal($deal->fresh());
            $this->assertSame($real->snapshot(), $twin->fresh()->money()->snapshot(), "{$name}: twin = deal");

            // The saved money lines the dashboards sum agree with the same figures, per side.
            foreach (DealMoney::SIDES as $side) {
                $lines = DB::table('deal_money_lines')->where('deal_id', $deal->id)->where('side', $side)->whereNull('deleted_at')->pluck('side_pool_ex_vat');
                foreach ($lines as $stored) {
                    $this->assertSame($real->sidePoolCents($side), DealMoney::scaled($stored, 2), "{$name}: {$side} saved line");
                }
            }

            // The existing screens' own calculation agrees to the cent (they hold floats, we hold cents).
            $pools = DealMoneyLineRebuilder::computeDealPools($deal->fresh());
            $this->assertEqualsWithDelta(DealMoney::toFloat($real->sidePoolCents('listing')), $pools['listingPool'], 0.005, "{$name}: listing vs deal screen");
            $this->assertEqualsWithDelta(DealMoney::toFloat($real->sidePoolCents('selling')), $pools['sellingPool'], 0.005, "{$name}: selling vs deal screen");
            $this->assertEqualsWithDelta(DealMoney::toFloat($real->externalPayableTotalCents()), $pools['externalPayableTotal'], 0.005, "{$name}: payable vs deal screen");
        }
    }

    public function test_parity_check_passes_and_lists_a_stale_saved_copy_without_failing(): void
    {
        $this->linkedPair('58650.00', '50', true, '50', false, true); // twin's own copy is stale (the 1818 fault)

        $code = Artisan::call('deals:parity-check');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('WARN', $out);
        $this->assertStringContainsString('out of date', $out);
        $this->assertStringContainsString('R 25500.00 vs R 51000.00', $out, 'the exact right and wrong figures are on the record');
        $this->assertSame(1, Artisan::call('deals:parity-check', ['--strict' => true]), '--strict makes a stale copy fail');
    }

    public function test_parity_check_fails_when_the_saved_money_lines_no_longer_match_the_deal(): void
    {
        [$deal] = $this->linkedPair('58650.00', '50', false, '50', false, true);
        // The deal changes without its lines being rebuilt (a path that skipped the rebuild).
        DB::table('deals')->where('id', $deal->id)->update(['selling_external' => 1]);

        $this->assertSame(1, Artisan::call('deals:parity-check'));
        $this->assertStringContainsString('saved money lines say', Artisan::output());
    }

    public function test_parity_check_fails_when_the_link_is_broken(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', false, '50', false);
        DB::table('deals')->where('id', $deal->id)->update(['deal_v2_id' => 0]);

        $this->assertSame(1, Artisan::call('deals:parity-check'));
        $this->assertStringContainsString('FAIL', Artisan::output());
    }

    public function test_the_guard_fails_when_a_deal_and_its_v2_row_belong_to_different_agencies(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', true, '50', false);
        $otherAgency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(6), 'slug' => 'other-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deals_v2')->where('id', $twin->id)->update(['agency_id' => $otherAgency]);

        $this->assertSame(1, Artisan::call('deals:parity-check'));
        $this->assertStringContainsString('never cross agencies', Artisan::output());
    }

    public function test_the_guard_fails_if_a_v2_screen_ever_reads_something_other_than_the_deal(): void
    {
        [$deal, $twin] = $this->linkedPair('58650.00', '50', true, '50', false);

        // Simulate the regression this whole change exists to prevent: v2 quietly answering from
        // its own saved copy again. The guard must call it out, with the wrong and the right figure.
        $regressed = new class extends DealTwinIntegrityService {
            protected function shownMoney(DealV2 $twin): DealMoney
            {
                return $twin->ownColumnsMoney();
            }
        };

        $fails = array_values(array_filter($regressed->audit()['findings'], fn ($f) => $f['severity'] === DealTwinIntegrityService::FAIL));

        $this->assertCount(1, $fails);
        $this->assertSame('v2_screen_differs', $fails[0]['code']);
        $this->assertSame('25500.00', $fails[0]['right']);
        $this->assertSame('51000.00', $fails[0]['wrong']);
    }

    // ── fixtures ──

    private function user(string $role, bool $admin = false): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'role' => $role, 'is_active' => true, 'is_admin' => $admin,
        ]);
    }

    private function deal(string $inc, string $lSplit, bool $lExt, string $sSplit, bool $sExt): Deal
    {
        return Deal::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(10000, 99999), 'period' => '2026-08', 'deal_date' => '2026-08-10',
            'property_value' => 680000, 'sale_price' => 680000, 'total_commission' => $inc,
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'listing_split_percent' => $lSplit, 'listing_external' => $lExt ? 1 : 0, 'listing_our_share_percent' => $lExt ? 0 : 100,
            'selling_split_percent' => $sSplit, 'selling_external' => $sExt ? 1 : 0, 'selling_our_share_percent' => $sExt ? 0 : 100,
        ]);
    }

    private function dealUserRow(int $dealId, int $userId, string $side): array
    {
        return [
            'deal_id' => $dealId, 'user_id' => $userId, 'side' => $side,
            'agent_split_percent' => 100, 'agent_cut_percent' => 60, 'paye_method' => 'percentage', 'paye_value' => 18,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** A twin row with NEUTRAL defaults — what the backfill / ensureTwin build (no splits, no external marker). */
    private function twinRow(?int $legacyDealId, array $over = []): DealV2
    {
        $row = new DealV2();
        $row->forceFill(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'legacy_deal_id' => $legacyDealId, 'reference' => 'DR1-' . ($legacyDealId ?? Str::random(8)),
            'deal_type' => 'cash', 'status' => 'active', 'property_id' => null,
            'listing_agent_id' => $this->agentA->id, 'pipeline_template_id' => null,
            'purchase_price' => 680000, 'commission_amount' => '51000.00', 'commission_vat' => '7650.00',
            'commission_status' => 'Not Paid', 'offer_date' => '2026-08-10', 'overall_rag' => 'grey',
            'backfilled_at' => now(), 'created_by_id' => $this->agentA->id,
        ], $over));
        $row->saveQuietly();

        return $row;
    }

    /** @return array{0:Deal,1:DealV2} */
    private function linkedPair(string $inc, string $lSplit, bool $lExt, string $sSplit, bool $sExt, bool $withLines = false): array
    {
        $deal = $this->deal($inc, $lSplit, $lExt, $sSplit, $sExt);
        $twin = $this->twinRow($deal->id);
        DB::table('deals')->where('id', $deal->id)->update(['deal_v2_id' => $twin->id]);

        if ($withLines) {
            foreach (['listing' => $lExt, 'selling' => $sExt] as $side => $ext) {
                if (! $ext) {
                    DB::table('deal_user')->insert($this->dealUserRow($deal->id, $side === 'listing' ? $this->agentA->id : $this->agentB->id, $side));
                }
            }
            DealMoneyLineRebuilder::rebuildDealId($deal->id);
        }

        return [Deal::withoutGlobalScopes()->find($deal->id), $twin->fresh()];
    }
}
