<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MIC capture lane step 3.6 (.ai/specs/mic-work-tab.md §11.2.2): POST /api/v1/prospecting/area-pages with the golden
 * payloads the extension builds from REAL saved Property24 area pages (public/chrome-extension/portal-capture/tests/
 * fixtures/p24-area). Chain: saved page -> payload (node test) -> stored rows (this test).
 */
final class MicAreaPagesIngestTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/prospecting/area-pages';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::create(['name' => 'Margate Agency', 'slug' => 'margate-agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);
        Sanctum::actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function golden(string $name): array
    {
        return json_decode(file_get_contents(base_path('public/chrome-extension/portal-capture/tests/fixtures/p24-area/' . $name . '.payload.json')), true);
    }

    private function trend(string $metric, string $period, string $segment = ''): ?float
    {
        $v = DB::table('p24_suburb_trends')->where(['p24_suburb_id' => 6359, 'metric' => $metric, 'period' => $period, 'segment' => $segment])->value('value');

        return $v === null ? null : (float) $v;
    }

    public function test_trends_page_becomes_dated_rows_per_metric(): void
    {
        $this->postJson(self::URL, $this->golden('uvongo-property-trends-6359'))->assertOk()
            ->assertJsonPath('limits.street_pages_per_run', 150)->assertJsonPath('limits.agency_pages_per_run', 10);

        $this->assertSame(51.0, $this->trend('sales_count', '2017'));
        $this->assertSame(840000.0, $this->trend('avg_sold_price', '2017'));
        $this->assertSame(1278000.0, $this->trend('avg_asking_price', '2017'));
        $this->assertSame(77.0, $this->trend('stock_new', '2025-10'));
        $this->assertSame(1206.0, $this->trend('stock_total', '2025-10'), 'the labelled total');
        $this->assertSame(1129.0, $this->trend('stock_total_plotted', '2025-10'), 'the page plots another figure; both are kept');
        $this->assertSame($this->trend('stock_by_beds', '2026-09', 'All'), $this->trend('stock_total', '2026-09'), 'the labelled total equals the bedrooms table total');
        $this->assertSame(35.0, $this->trend('sold_count', '2017', 'erven'));
        $this->assertSame(16.0, $this->trend('sold_count', '2017', 'sectional'));
        $this->assertSame(950000.0, $this->trend('sold_avg_price', '2017', 'erven'));
        $this->assertSame(735000.0, $this->trend('sold_avg_price', '2017', 'sectional'));
        $this->assertSame(600000.0, $this->trend('avg_asking_by_beds', 'current', '1 bed'));
        $this->assertEqualsWithDelta(0.0689655, (float) $this->trend('age_profile_share', 'Age 36 - 49', 'sellers'), 0.0001);
        $this->assertSame(284.0, $this->trend('stock_by_beds', '2026-09', '3 bed'));
        $this->assertSame(788.0, $this->trend('stock_by_beds', '2026-09', 'All'));
        $this->assertSame('Uvongo', DB::table('p24_suburb_trends')->value('suburb'));
    }

    public function test_a_reread_the_same_day_changes_nothing_and_next_month_adds_a_dated_set(): void
    {
        Carbon::setTestNow('2026-10-31 10:00:00');
        $this->postJson(self::URL, $this->golden('uvongo-property-trends-6359'))->assertOk();
        $n = DB::table('p24_suburb_trends')->count();
        $this->assertGreaterThan(100, $n);

        $this->postJson(self::URL, $this->golden('uvongo-property-trends-6359'))->assertOk();
        $this->assertSame($n, DB::table('p24_suburb_trends')->count());

        Carbon::setTestNow('2026-11-30 10:00:00');
        $this->postJson(self::URL, $this->golden('uvongo-property-trends-6359'))->assertOk();
        $this->assertSame($n * 2, DB::table('p24_suburb_trends')->count());
        $this->assertSame(['2026-10-31', '2026-11-30'], DB::table('p24_suburb_trends')->distinct()->orderBy('read_on')->pluck('read_on')->map(fn ($d) => substr((string) $d, 0, 10))->all());
    }

    public function test_agencies_page_stores_each_agency_once_per_day(): void
    {
        $this->postJson(self::URL, $this->golden('uvongo-estate-agencies-6359'))->assertOk()->assertJsonPath('agencies', 20);
        $row = DB::table('p24_suburb_agencies')->where('agency_portal_id', '32381')->first();
        $this->assertSame('PdH Properties', $row->agency_name);
        $this->assertSame([78, 461], [(int) $row->listings_in_suburb, (int) $row->total_listings]);

        $this->postJson(self::URL, $this->golden('uvongo-estate-agencies-6359'))->assertOk();
        $this->assertSame(20, DB::table('p24_suburb_agencies')->count());
    }

    public function test_street_page_stores_house_numbers_and_never_a_price(): void
    {
        $res = $this->postJson(self::URL, $this->golden('uvongo-marine-drive-values'))->assertOk();
        $this->assertGreaterThanOrEqual(6, $res->json('house_numbers'));
        $this->assertSame(['96', '98'], DB::table('p24_street_numbers')->where('street_slug', 'marine-drive')->orderByRaw('CAST(house_number AS UNSIGNED)')->limit(2)->pluck('house_number')->all());
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('p24_street_numbers');
        $this->assertEmpty(array_filter($cols, fn ($c) => str_contains($c, 'price')), 'paid prices have no column to land in');

        $before = DB::table('p24_street_numbers')->count();
        $again = $this->postJson(self::URL, $this->golden('uvongo-marine-drive-values'))->assertOk();
        $this->assertSame(0, $again->json('house_numbers_new'));
        $this->assertSame($before, DB::table('p24_street_numbers')->count());
    }

    public function test_a_year_is_kept_only_when_it_is_a_plausible_year_and_rows_without_a_house_number_are_skipped(): void
    {
        $this->postJson(self::URL, ['kind' => 'streets', 'p24_suburb_id' => 6359, 'streets' => [[
            'street_slug' => 'test-road', 'street_name' => 'Test Road',
            'addresses' => [
                ['address' => '12A Test Road', 'last_sold_year' => 2019],
                ['address' => '14 Test Road', 'last_sold_year' => 1066],
                ['address' => 'Erf 9 Test Road'],
                ['address' => 'R 1 500 000'],
            ],
        ], ['street_slug' => 'Bad Slug!', 'addresses' => [['address' => '1 Bad']]]]])->assertOk()->assertJsonPath('house_numbers', 2);

        $this->assertSame(2019, (int) DB::table('p24_street_numbers')->where('house_number', '12A')->value('last_sold_year'));
        $this->assertNull(DB::table('p24_street_numbers')->where('house_number', '14')->value('last_sold_year'));
        $this->assertFalse(DB::table('p24_street_numbers')->where('street_slug', 'bad slug!')->exists());
    }

    public function test_bad_requests_are_refused(): void
    {
        $this->postJson(self::URL, ['kind' => 'nonsense', 'p24_suburb_id' => 1])->assertStatus(422);
        $this->postJson(self::URL, ['kind' => 'trends', 'p24_suburb_id' => 6359])->assertStatus(422);
        $this->postJson(self::URL, ['kind' => 'agencies', 'p24_suburb_id' => 0, 'agencies' => []])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->postJson(self::URL, $this->golden('uvongo-estate-agencies-6359'))->assertStatus(401);
    }

    public function test_the_suburb_snapshot_names_the_figures_the_record_card_needs(): void
    {
        $snap = app(\App\Services\Prospecting\P24SuburbSnapshot::class);
        $this->assertNull($snap->forP24Suburb(6359), 'never read');

        Carbon::setTestNow('2026-10-31 10:00:00');
        foreach (['uvongo-property-trends-6359', 'uvongo-estate-agencies-6359', 'uvongo-marine-drive-values'] as $n) {
            $this->postJson(self::URL, $this->golden($n))->assertOk();
        }
        Carbon::setTestNow('2026-11-10 10:00:00');
        $s = $snap->forP24Suburb(6359);

        $this->assertSame(['2026-10-31', 10], [$s['read_on'], $s['age_days']]);
        $this->assertSame([2026, 33.0, 1400000.0, 1350000.0], [$s['sales_year'], $s['sales_count'], $s['avg_asking_price'], $s['avg_sold_price']]);
        $this->assertSame([27.0, 1435000.0, 6.0, 1067500.0], [$s['erven_sold_count'], $s['erven_avg_sold_price'], $s['sectional_sold_count'], $s['sectional_avg_sold_price']]);
        $this->assertSame(600000.0, $s['avg_asking_by_beds']['1 bed']);
        $this->assertSame(['2026-09', 63.0, 788.0], [$s['stock_month'], $s['stock_new'], $s['stock_total']]);
        $this->assertSame($s['stock_total'] - $s['stock_total_3m_ago'], $s['stock_total_change_3m']);
        $this->assertSame('Age 65 or older', $s['seller_age_band']);
        $this->assertSame(20, $s['agencies_count']);
        $this->assertSame('PdH Properties', $s['top_agencies'][0]['agency']);
        $this->assertGreaterThanOrEqual(6, $s['street_house_numbers_known']);
    }
}

