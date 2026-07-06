<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\P24LeadService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * The first-ever P24 leads pull (no cursor cached yet) must reach back the FULL
 * window P24 still retains — not the old hardcoded 7 days that silently
 * abandoned ~3 weeks of still-available leads on day one. The window is
 * config-driven and clamped to [1, 29] to stay under P24 v53's 30-day `after`
 * rejection ceiling.
 */
final class P24LeadFirstPullWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Build the service with an API mock that records the `after` it is asked for. */
    private function serviceCapturing(?string &$captured): P24LeadService
    {
        $api = Mockery::mock(Property24ApiClient::class);
        $api->shouldReceive('getLeads')
            ->once()
            ->andReturnUsing(function (?string $after) use (&$captured) {
                $captured = $after;
                return ['success' => true, 'data' => ['leads' => []]];
            });

        return new P24LeadService($api, Mockery::mock(TrackedPropertyMatchOrCreateService::class));
    }

    public function test_first_pull_reaches_back_the_full_configured_window(): void
    {
        Cache::forget('p24.leads.cursor.agency.default');
        config()->set('services.property24_syndication.leads_first_pull_days', 28);

        $captured = null;
        $this->serviceCapturing($captured)->pullLeads(null);

        $this->assertNotNull($captured);
        $daysBack = Carbon::parse($captured)->diffInDays(now());

        // The old bug pulled only 7 days back; assert we now reach ~28.
        $this->assertGreaterThan(20, $daysBack, 'First pull must reach well beyond the old 7-day window.');
        $this->assertEqualsWithDelta(28, $daysBack, 1, 'First pull window should honour the configured 28 days.');
    }

    public function test_window_is_clamped_below_the_30_day_ceiling(): void
    {
        Cache::forget('p24.leads.cursor.agency.default');
        config()->set('services.property24_syndication.leads_first_pull_days', 999);

        $captured = null;
        $this->serviceCapturing($captured)->pullLeads(null);

        $daysBack = Carbon::parse($captured)->diffInDays(now());
        $this->assertLessThanOrEqual(29, $daysBack, 'Window must never exceed P24 v53 30-day ceiling.');
        $this->assertGreaterThanOrEqual(29, $daysBack, 'A large config value should clamp up to 29, not fall back.');
    }

    public function test_existing_cursor_is_respected_and_not_overwritten_by_the_first_pull_default(): void
    {
        $cursor = now()->subDays(2)->toIso8601String();
        Cache::put('p24.leads.cursor.agency.default', $cursor, now()->addDays(30));

        $captured = null;
        $this->serviceCapturing($captured)->pullLeads(null);

        $this->assertSame($cursor, $captured, 'A cached cursor must take precedence over the first-pull window.');
    }
}
