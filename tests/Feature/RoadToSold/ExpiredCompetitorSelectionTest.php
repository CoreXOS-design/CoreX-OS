<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsSetting;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v3 - the tenth source: competitors' adverts the portal reported WITHDRAWN on a known date, inside the agency's look-back
 * (road-to-sold.md 22.6). Nothing is guessed from a listing with no dated status.
 */
final class ExpiredCompetitorSelectionTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        Cache::flush();
        $this->setUpAgency();
        $this->setting(['selections' => [['key' => 'expired_competitor', 'enabled' => true]]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setting(array $attrs): void
    {
        RtsSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], $attrs);
        SettingsService::forget();
    }

    private function withdrawn(string $daysAgo, array $extra = [], $tp = true): int
    {
        return $this->listing($extra + [
            'is_active' => 0, 'portal_status' => 'withdrawn',
            'portal_status_changed_at' => Carbon::parse(self::WED)->subDays((int) $daysAgo)->toDateTimeString(),
            'agency_name' => 'Sam Realty',
        ], $tp);
    }

    private function build()
    {
        Cache::flush();

        return app(ListBuilderService::class)->buildFor($this->agent, self::WED);
    }

    public function test_a_recently_withdrawn_competitor_advert_is_offered_with_the_fact_in_plain_words(): void
    {
        $id = $this->withdrawn('5');

        $items = $this->build();

        $this->assertSame([$id], $items->pluck('prospecting_listing_id')->all());
        $this->assertSame('expired_competitor', $items->first()->selection);
        $this->assertSame('Expired with Sam Realty 9 Oct', $items->first()->reason);
        $this->assertSame('2026-10-09', $items->first()->reason_facts['withdrawn_on']);
    }

    public function test_only_what_was_withdrawn_inside_the_agencys_look_back_is_offered(): void
    {
        $this->withdrawn('40');
        $this->assertCount(0, $this->build(), 'the standard look-back is 30 days');

        $this->setting(['expired_competitor_lookback_days' => 60]);
        $this->assertCount(1, $this->build(), 'the agency widened it');
    }

    public function test_nothing_is_guessed_from_a_listing_without_a_dated_withdrawal(): void
    {
        $this->listing(['is_active' => 0, 'portal_status' => 'withdrawn', 'portal_status_changed_at' => null]);
        $this->listing(['is_active' => 0, 'portal_status' => null]);
        $this->listing(['is_active' => 1, 'portal_status' => 'active']);

        $this->assertCount(0, $this->build());
    }

    public function test_a_relisted_property_a_sold_one_and_an_under_offer_one_are_not_expired(): void
    {
        $relisted = $this->tp();
        $this->withdrawn('3', [], $relisted);
        $this->listing(['is_active' => 1, 'portal_status' => 'active'], $relisted);

        $sold = $this->tp();
        $this->withdrawn('3', [], $sold);
        $this->listing(['is_active' => 0, 'portal_status' => 'sold', 'portal_status_changed_at' => Carbon::parse(self::WED)->subDay()->toDateTimeString()], $sold);

        $this->assertCount(0, $this->build());
    }

    public function test_it_is_never_offered_to_a_second_agent_once_the_first_has_it(): void
    {
        $this->withdrawn('2');

        $this->assertCount(1, $this->build());
        Cache::flush();
        $this->assertCount(0, app(ListBuilderService::class)->buildFor($this->mate, self::WED), 'the allocation (road-to-sold.md 22.6)');
    }
}
