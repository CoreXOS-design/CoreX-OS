<?php

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Performance\Period;
use App\Services\Performance\Providers\CommissionGrossProvider;
use App\Services\Performance\Providers\ContactsCreatedProvider;
use App\Services\Performance\Providers\DealsCreatedProvider;
use App\Services\Performance\Providers\DealsRegisteredProvider;
use App\Services\Performance\Providers\FicaSubmissionsProvider;
use App\Services\Performance\Providers\PropertiesCreatedProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-366 correctness (2026-08) — the Performance & ROI report must count GENUINE
 * agent activity, not the 2026-06 bulk import, and must count deals across BOTH
 * registers (DR1 + DR2) deduped, with commission and a real registration signal.
 */
class ImportExclusionAndDedupTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $a1;

    private function period(): Period
    {
        return new Period(
            CarbonImmutable::parse('2026-08-01 00:00:00'),
            CarbonImmutable::parse('2026-08-31 23:59:59'),
            'August', 'this_month'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['performance.count_untouched_imports' => false]); // corrected mode by default
        $this->agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'B']);
        $this->a1 = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);
    }

    private function contact(array $attrs): int
    {
        return DB::table('contacts')->insertGetId(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->a1->id, 'agent_id' => $this->a1->id,
            'first_name' => 'F', 'last_name' => 'L',
            'created_at' => '2026-08-10 09:00:00', 'updated_at' => now(),
        ], $attrs));
    }

    public function test_contacts_created_excludes_untouched_imports_only(): void
    {
        $this->contact(['loaded_at' => null]);                                   // native → counts
        $this->contact(['loaded_at' => '2026-06-17 00:00:00']);                  // untouched import → EXCLUDED
        $this->contact(['loaded_at' => '2026-06-17 00:00:00', 'buyer_pipeline_entered_at' => '2026-08-05 10:00:00']); // worked → counts
        $outreachContact = $this->contact(['loaded_at' => '2026-06-17 00:00:00']); // worked via outreach → counts
        DB::table('seller_outreach_sends')->insert([
            'agency_id' => $this->agency->id, 'contact_id' => $outreachContact, 'agent_id' => $this->a1->id,
            'channel' => 'whatsapp', 'body_snapshot' => 'hi', 'facts_snapshot' => '{}',
            'sent_at' => '2026-08-06 10:00:00', 'tracking_short_code' => 'abc123',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = app(ContactsCreatedProvider::class)->forUsers([$this->a1->id], $this->period());
        $this->assertSame(3, $res[$this->a1->id], 'native + pipeline-worked + outreach-worked; untouched import excluded');

        config(['performance.count_untouched_imports' => true]); // raw audit mode
        $raw = app(ContactsCreatedProvider::class)->forUsers([$this->a1->id], $this->period());
        $this->assertSame(4, $raw[$this->a1->id], 'raw mode restores the untouched import');
    }

    public function test_properties_created_excludes_untouched_historical_imports(): void
    {
        $mk = fn (array $a) => DB::table('properties')->insert(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->a1->id,
            'external_id' => 'X' . uniqid(), 'title' => 'P',
            'created_at' => '2026-08-10 09:00:00', 'updated_at' => now(),
        ], $a));
        $mk(['status' => 'active']);                                          // live stock → counts
        $mk(['status' => 'withdrawn']);                                       // untouched historical import → EXCLUDED
        $mk(['status' => 'expired', 'p24_last_submitted_at' => '2026-08-02 10:00:00']); // re-syndicated on CoreX → counts

        $res = app(PropertiesCreatedProvider::class)->forUsers([$this->a1->id], $this->period());
        $this->assertSame(2, $res[$this->a1->id], 'live + CoreX-syndicated count; untouched withdrawn import excluded');

        config(['performance.count_untouched_imports' => true]);
        $this->assertSame(3, app(PropertiesCreatedProvider::class)->forUsers([$this->a1->id], $this->period())[$this->a1->id]);
    }

    public function test_fica_counts_only_for_genuine_contacts(): void
    {
        $native = $this->contact(['loaded_at' => null]);
        $import = $this->contact(['loaded_at' => '2026-06-17 00:00:00']); // untouched import
        foreach ([$native, $import] as $cid) {
            DB::table('fica_submissions')->insert([
                'agency_id' => $this->agency->id, 'requested_by' => $this->a1->id, 'contact_id' => $cid,
                'status' => 'approved', 'created_at' => '2026-08-11 09:00:00', 'updated_at' => now(),
            ]);
        }
        $res = app(FicaSubmissionsProvider::class)->forUsers([$this->a1->id], $this->period());
        $this->assertSame(1, $res[$this->a1->id], 'FICA for the native contact counts; the import-stub FICA does not');
    }

    private function dr1(array $a): int
    {
        return DB::table('deals')->insertGetId(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'managed_by_user_id' => $this->a1->id, 'period' => '2026-08',
            'deal_date' => '2026-08-10', 'property_value' => 1000000, 'total_commission' => 50000,
            'created_at' => now(), 'updated_at' => now(),
        ], $a));
    }

    private function dr2(array $a): int
    {
        return DB::table('deals_v2')->insertGetId(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'reference' => 'D' . uniqid(), 'deal_type' => 'cash',
            'listing_agent_id' => $this->a1->id, 'purchase_price' => 1000000,
            'commission_amount' => 50000, 'commission_vat' => 7500,
            'offer_date' => '2026-08-10', 'created_by_id' => $this->a1->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $a));
    }

    public function test_deals_created_unions_dr1_and_dr2_and_dedupes_linked_pair(): void
    {
        $d1 = $this->dr1([]);                                   // DR1 deal
        $this->dr2(['legacy_deal_id' => $d1]);                 // its DR2 twin → must NOT double-count
        $res = app(DealsCreatedProvider::class)->forUsers([$this->a1->id], $this->period());
        $this->assertSame(1, $res[$this->a1->id], 'linked DR1+DR2 pair counts once');

        $this->dr2(['legacy_deal_id' => null]);                // a standalone DR2 (unlinked) → +1
        $res2 = app(DealsCreatedProvider::class)->forUsers([$this->a1->id], $this->period());
        $this->assertSame(2, $res2[$this->a1->id], 'unlinked DR2 adds one');
    }

    public function test_deals_registered_uses_real_dr1_registration_date(): void
    {
        $this->dr1(['registration_date' => '2026-08-12', 'deal_date' => '2026-07-01']); // registered in Aug
        $this->dr1(['registration_date' => null]);                                      // not registered
        $res = app(DealsRegisteredProvider::class)->forUsers([$this->a1->id], $this->period());
        $this->assertSame(1, $res[$this->a1->id], 'DR1 registration_date drives deals_registered (was a false 0 before)');
    }

    public function test_commission_gross_ex_vat_sums_from_money_lines(): void
    {
        $d1 = $this->dr1([]);
        DB::table('deal_money_lines')->insert([
            ['agency_id' => $this->agency->id, 'deal_id' => $d1, 'user_id' => $this->a1->id, 'period' => '2026-08', 'agent_gross_ex_vat' => 30000, 'created_at' => now(), 'updated_at' => now()],
            ['agency_id' => $this->agency->id, 'deal_id' => $d1, 'user_id' => $this->a1->id, 'period' => '2026-08', 'agent_gross_ex_vat' => 12500, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $res = app(CommissionGrossProvider::class)->forUsers([$this->a1->id], $this->period());
        $this->assertEqualsWithDelta(42500.0, $res[$this->a1->id], 0.01, 'gross ex-VAT summed for the agent');
    }
}
