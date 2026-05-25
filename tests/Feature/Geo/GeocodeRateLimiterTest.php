<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\User;
use App\Services\Geocoding\GeocodeRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Phase 11a Part F — G4-G7. Rate limiter behaviour against the array cache
 * driver (configured by phpunit.xml).
 */
final class GeocodeRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('geo.geocoding.enabled', true);
        Config::set('geo.geocoding.admin_override_enabled', false);
        Config::set('geo.geocoding.environment_daily_cap', 5);
        Config::set('geo.geocoding.user_daily_cap', 3);
        GeocodeRateLimiter::releaseRuntimeOverride();
    }

    /** G4 — env cap trips after N calls. */
    public function test_g4_environment_cap_blocks_further_calls(): void
    {
        $limiter = app(GeocodeRateLimiter::class);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->canGeocode(), "should allow call {$i}");
            $limiter->recordCall();
        }

        $this->assertFalse($limiter->canGeocode(), 'env cap should now block');
        $this->assertSame(0, $limiter->getRemainingToday()['env_remaining']);
    }

    /** G5 — per-user cap trips while env still has room. */
    public function test_g5_user_cap_blocks_one_user_while_env_still_has_room(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $limiter = app(GeocodeRateLimiter::class);

        // User A burns through their user cap (3) — env counter now at 3 of 5.
        $this->actingAs($userA);
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($limiter->canGeocode());
            $limiter->recordCall();
        }
        $this->assertFalse($limiter->canGeocode(), 'user A blocked by user cap');

        // User B still has full user cap because env (3/5) hasn't tripped.
        $this->actingAs($userB);
        $this->assertTrue($limiter->canGeocode(), 'user B unaffected by user A cap');
    }

    /** G6 — config admin override bypasses both caps. */
    public function test_g6_config_admin_override_bypasses_env_cap(): void
    {
        Config::set('geo.geocoding.admin_override_enabled', true);
        $limiter = app(GeocodeRateLimiter::class);

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($limiter->canGeocode(), "override should keep call {$i} allowed");
            $limiter->recordCall();
        }
        // Even with the cap tripped, override still says yes.
        $this->assertTrue($limiter->canGeocode());
    }

    /** G7 — runtime override engages and releases as expected. */
    public function test_g7_runtime_override_engages_and_releases(): void
    {
        $limiter = app(GeocodeRateLimiter::class);

        // Pre-trip the env cap.
        for ($i = 0; $i < 5; $i++) $limiter->recordCall();
        $this->assertFalse($limiter->canGeocode(), 'pre-condition: cap is tripped');

        GeocodeRateLimiter::engageRuntimeOverride();
        $this->assertTrue($limiter->canGeocode(), 'runtime override should bypass cap');

        GeocodeRateLimiter::releaseRuntimeOverride();
        $this->assertFalse($limiter->canGeocode(), 'release should restore cap enforcement');
    }
}
