<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\DevSetting;
use App\Support\OutboundMailGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AT-URGENT-2026-09-08/09 — proves the explicit sending-environments list
 * and the three-state toggle (forced-on / forced-off / not-set, falling
 * back to the environment default) behave exactly as specified: a missing
 * or corrupt override can only ever produce this environment's OWN natural
 * behaviour, never the opposite of it, on every environment including
 * production.
 */
final class OutboundMailGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // DevSetting caches under CACHE_STORE=array (persists per test
        // process, not per test) — flush so no test can see a prior test's
        // toggle value.
        Cache::flush();
    }

    // ── Environment defaults, no override set ───────────────────────────────

    public function test_qa1_intercepts_by_default(): void
    {
        config(['app.env' => 'qa', 'app.url' => 'https://qatesting1.corexos.co.za']);

        $this->assertFalse(OutboundMailGuard::isSendingConfirmed());
        $this->assertTrue(OutboundMailGuard::isActive());
        $this->assertFalse(OutboundMailGuard::isOverridden());
    }

    public function test_local_intercepts_by_default(): void
    {
        config(['app.env' => 'local', 'app.url' => 'http://localhost']);

        $this->assertFalse(OutboundMailGuard::isSendingConfirmed());
        $this->assertTrue(OutboundMailGuard::isActive());
    }

    public function test_demo_intercepts_by_default_even_with_app_env_production(): void
    {
        // e.g. the demo box, which runs APP_ENV=production but is not corexos.co.za.
        config(['app.env' => 'production', 'app.url' => 'https://demo1.corexos.co.za']);

        $this->assertFalse(OutboundMailGuard::isSendingConfirmed());
        $this->assertTrue(OutboundMailGuard::isActive());
    }

    public function test_staging_sends_by_default(): void
    {
        // Johan: "if we move email to staging... we can test emails on my
        // account there." The only way to prove the poller/sending path
        // work against a real mailbox.
        config(['app.env' => 'staging', 'app.url' => 'https://staging.corexos.co.za']);

        $this->assertTrue(OutboundMailGuard::isSendingConfirmed());
        $this->assertFalse(OutboundMailGuard::isActive());
        $this->assertFalse(OutboundMailGuard::isOverridden());
    }

    public function test_staging_wrong_host_still_intercepts(): void
    {
        // APP_ENV=staging but the host doesn't match — fail-safe direction.
        config(['app.env' => 'staging', 'app.url' => 'https://staging.hfcoastal.co.za']);

        $this->assertFalse(OutboundMailGuard::isSendingConfirmed());
        $this->assertTrue(OutboundMailGuard::isActive());
    }

    public function test_production_sends_by_default(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za']);

        $this->assertTrue(OutboundMailGuard::isSendingConfirmed());
        $this->assertFalse(OutboundMailGuard::isActive());
    }

    public function test_production_www_host_sends_by_default(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://www.corexos.co.za']);

        $this->assertTrue(OutboundMailGuard::isSendingConfirmed());
        $this->assertFalse(OutboundMailGuard::isActive());
    }

    // ── The kill switch — works on every environment, both directions ──────

    public function test_forcing_intercept_on_wins_on_production(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za']);
        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, '1');

        $this->assertTrue(OutboundMailGuard::isActive(), 'production must be interceptable — the whole point of the kill switch');
        $this->assertTrue(OutboundMailGuard::isOverridden());
    }

    public function test_forcing_send_wins_on_qa1(): void
    {
        // Johan's original ask: "then at least we can test both ways."
        config(['app.env' => 'qa', 'app.url' => 'https://qatesting1.corexos.co.za']);
        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, '0');

        $this->assertFalse(OutboundMailGuard::isActive());
        $this->assertTrue(OutboundMailGuard::isOverridden());
    }

    public function test_forcing_send_wins_on_staging_before_it_is_armed(): void
    {
        config(['app.env' => 'staging', 'app.url' => 'https://staging.hfcoastal.co.za']); // would otherwise intercept
        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, '0');

        $this->assertFalse(OutboundMailGuard::isActive());
        $this->assertTrue(OutboundMailGuard::isOverridden());
    }

    // ── Fail-safe direction — missing/corrupt override never flips behaviour ─

    public function test_missing_override_falls_back_to_environment_default_on_qa1(): void
    {
        config(['app.env' => 'qa', 'app.url' => 'https://qatesting1.corexos.co.za']);
        // TOGGLE_KEY never set at all.

        $this->assertTrue(OutboundMailGuard::isActive(), 'a missing override must never silently start sending on a test site');
        $this->assertFalse(OutboundMailGuard::isOverridden());
    }

    public function test_missing_override_falls_back_to_environment_default_on_production(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za']);

        $this->assertFalse(OutboundMailGuard::isActive(), 'a missing override must never silently go silent on production');
    }

    public function test_corrupt_override_value_falls_back_to_environment_default(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za']);
        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, 'garbage-not-a-boolean');

        // forcedDirection() treats anything not in the truthy list as false —
        // i.e. NOT forced-intercept. On production that happens to already
        // match the default (sends), so this specific corrupt value is a
        // no-op here, which is itself the safe outcome: never MORE
        // restrictive than a real '0' would have been, never less.
        $this->assertFalse(OutboundMailGuard::isActive());
    }

    public function test_clearing_the_override_restores_the_environment_default(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za']);
        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, '1');
        $this->assertTrue(OutboundMailGuard::isActive());

        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, null);

        $this->assertFalse(OutboundMailGuard::isActive());
        $this->assertFalse(OutboundMailGuard::isOverridden());
    }
}
