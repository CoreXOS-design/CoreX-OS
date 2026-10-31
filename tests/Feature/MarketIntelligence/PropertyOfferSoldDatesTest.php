<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Properties\PropertyOfferSoldDates;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** MIC step 5.4 (AT-524): under_offer_at / sold_at stamped at every status change, back-filled only from trustworthy audit events. */
final class PropertyOfferSoldDatesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function property(string $status = 'active', array $extra = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create($extra + [
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => '3 bed home', 'suburb' => 'Uvongo', 'city' => 'Margate', 'address' => '12 Marine Drive',
            'property_type' => 'house', 'status' => $status, 'price' => 1800000, 'beds' => 3, 'baths' => 2, 'size_m2' => 180,
            'description' => 'Sea views', 'listed_date' => '2026-07-01',
        ]);
    }

    private function dates(Property $p): array
    {
        $r = DB::table('properties')->where('id', $p->id)->first(['under_offer_at', 'sold_at']);

        return [$r->under_offer_at, $r->sold_at];
    }

    private function event(Property $p, string $from, string $to, string $at, ?string $source = null): void
    {
        DB::table('property_audit_log')->insert([
            'property_id' => $p->id, 'agency_id' => $this->agency->id, 'actor_type' => 'user', 'source' => $source,
            'event_category' => 'property', 'event_type' => 'status_changed', 'old_values' => json_encode(['status' => $from]),
            'new_values' => json_encode(['status' => $to]), 'created_at' => $at,
        ]);
    }

    public function test_every_status_change_stamps_the_dates_in_one_place(): void
    {
        $p = $this->property();
        $this->assertSame([null, null], $this->dates($p), 'creating a property stamps nothing');

        Carbon::setTestNow('2026-10-01 09:00:00');
        $p->update(['status' => 'under_offer']);
        $this->assertSame(['2026-10-01 09:00:00', null], $this->dates($p));

        Carbon::setTestNow('2026-10-20 14:30:00');
        $p->update(['status' => 'sold']);
        $this->assertSame(['2026-10-01 09:00:00', '2026-10-20 14:30:00'], $this->dates($p), 'the offer date stays, sold_at is the moment of the sale');

        // the sold-stock snapshot taken at that same moment reads sold_at
        $this->assertSame('2026-10-20', (string) DB::table('sold_stock')->where('property_id', $p->id)->value('sale_date'));

        $p->update(['status' => 'active']);
        $this->assertSame([null, null], $this->dates($p), 'a reversed sale clears both');
    }

    public function test_an_offer_that_falls_through_clears_the_offer_date(): void
    {
        $p = $this->property();
        $p->update(['status' => 'under_offer']);
        $this->assertNotNull($this->dates($p)[0]);
        $p->update(['status' => 'active']);
        $this->assertSame([null, null], $this->dates($p));
    }

    public function test_backfill_uses_only_audit_events_after_the_trusted_date_and_fills_empty_columns_only(): void
    {
        $sold = $this->property('sold');
        $this->event($sold, 'active', 'under_offer', '2026-07-10 10:00:00');
        $this->event($sold, 'under_offer', 'sold', '2026-08-01 15:00:00');

        $preImport = $this->property('sold');
        $this->event($preImport, 'active', 'sold', '2026-06-25 09:00:00'); // before 26 Jun = bulk import noise

        $console = $this->property('sold');
        $this->event($console, 'active', 'sold', '2026-08-02 09:00:00', 'console:tinker');

        $mismatch = $this->property('active');
        $this->event($mismatch, 'active', 'sold', '2026-08-03 09:00:00'); // log says sold, property is active: not trustworthy

        $already = $this->property('sold');
        DB::table('properties')->where('id', $already->id)->update(['sold_at' => '2026-07-20 08:00:00']);
        $this->event($already, 'active', 'sold', '2026-08-04 09:00:00');

        $imported = $this->property('sold', ['p24_imported_at' => '2026-08-05 08:00:00']);
        $this->event($imported, 'active', 'sold', '2026-08-05 12:00:00'); // within a day of the import: the load, not a sale

        $svc = app(PropertyOfferSoldDates::class);
        $dry = $svc->backfill($this->agency->id, false);
        $this->assertSame([null, null], $this->dates($sold), 'dry run writes nothing');
        $this->assertSame(1, $dry['skipped_mismatch']);

        $r = $svc->backfill($this->agency->id, true);

        $this->assertSame(['2026-07-10 10:00:00', '2026-08-01 15:00:00'], $this->dates($sold));
        $this->assertSame([null, null], $this->dates($preImport));
        $this->assertSame([null, null], $this->dates($console));
        $this->assertSame([null, null], $this->dates($mismatch));
        $this->assertSame([null, '2026-07-20 08:00:00'], $this->dates($already), 'an existing value is never overwritten');
        $this->assertSame([null, null], $this->dates($imported));
        $this->assertSame(1, $r['sold_filled']);
        $this->assertSame(1, $r['under_offer_filled']);
        $this->assertSame(1, $r['events_excluded_console']);

        $again = $svc->backfill($this->agency->id, true);
        $this->assertSame(0, $again['sold_filled'] + $again['under_offer_filled']);

        $svc->rollback($r['run_id']);
        $this->assertSame([null, null], $this->dates($sold));
    }

    public function test_a_catch_up_day_with_a_mass_of_sale_side_changes_is_not_used(): void
    {
        $catchUp = [];
        for ($i = 0; $i < 10; $i++) {
            $p = $this->property('sold');
            $this->event($p, 'active', 'sold', '2026-07-01 1' . ($i % 10) . ':00:00');
            $catchUp[] = $p;
        }
        $real = $this->property('sold');
        $this->event($real, 'active', 'sold', '2026-08-11 09:00:00');

        $r = app(PropertyOfferSoldDates::class)->backfill($this->agency->id, true);

        foreach ($catchUp as $p) {
            $this->assertSame([null, null], $this->dates($p), 'ten Sold changes in one day is data entry, not ten sales');
        }
        $this->assertSame(['sold' => null], ['sold' => $this->dates($catchUp[0])[1]]);
        $this->assertSame([null, '2026-08-11 09:00:00'], $this->dates($real));
        $this->assertSame(10, $r['events_excluded_bulk_days']);
        $this->assertSame([$this->agency->id . ' 2026-07-01' => 10], $r['bulk_days']);
    }

    public function test_sold_stock_rows_with_the_fallback_date_get_the_real_sale_date_and_days_and_the_portal_window_is_untouched(): void
    {
        $p = $this->property('sold', ['listed_date' => '2026-07-01']);
        DB::table('properties')->where('id', $p->id)->update(['sold_at' => '2026-08-01 15:00:00', 'portal_sold_until' => '2026-12-31', 'updated_at' => '2026-09-17 08:00:00']);
        $row = DB::table('sold_stock')->insertGetId([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'cycle_no' => 1, 'source' => 'backfill', 'listed_date' => '2026-07-01',
            'sale_date' => '2026-09-17', 'days_on_market' => 78, 'created_at' => '2026-09-17 12:00:00', 'updated_at' => '2026-09-17 12:00:00',
        ]);
        $real = DB::table('sold_stock')->insertGetId([
            'agency_id' => $this->agency->id, 'property_id' => $this->property('sold')->id, 'cycle_no' => 1, 'source' => 'backfill', 'listed_date' => null,
            'sale_date' => '2026-07-05', 'days_on_market' => null, 'created_at' => '2026-09-17 12:00:00', 'updated_at' => '2026-09-17 12:00:00',
        ]);

        $r = app(PropertyOfferSoldDates::class)->backfill($this->agency->id, true);

        $this->assertSame(1, $r['sold_stock_corrected']);
        $fixed = DB::table('sold_stock')->where('id', $row)->first();
        $this->assertSame('2026-08-01', (string) $fixed->sale_date);
        $this->assertSame(31, (int) $fixed->days_on_market);
        $this->assertSame('2026-07-05', (string) DB::table('sold_stock')->where('id', $real)->value('sale_date'), 'a row with a real date is left alone');
        $this->assertSame('2026-12-31', (string) DB::table('properties')->where('id', $p->id)->value('portal_sold_until'));

        app(PropertyOfferSoldDates::class)->rollback($r['run_id']);
        $this->assertSame('2026-09-17', (string) DB::table('sold_stock')->where('id', $row)->value('sale_date'));
        $this->assertSame(78, (int) DB::table('sold_stock')->where('id', $row)->value('days_on_market'));
    }
}
