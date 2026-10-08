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

    public function test_staging_intercepts_by_default(): void
    {
        // 2026-10-08 (Johan, via conductor) — REVERSES the earlier "staging sends" ruling: a database
        // restored from live onto Staging must never be able to mail real tenants and landlords.
        config(['app.env' => 'staging', 'app.url' => 'https://staging.corexos.co.za']);

        $this->assertFalse(OutboundMailGuard::isSendingConfirmed());
        $this->assertTrue(OutboundMailGuard::isActive());
        $this->assertFalse(OutboundMailGuard::isOverridden());
    }

    public function test_a_database_restored_from_live_cannot_switch_the_guard_off_on_staging(): void
    {
        config(['app.env' => 'staging', 'app.url' => 'https://staging.corexos.co.za']);

        // Live's dev_settings row (or its absence) arrives with the restore:
        foreach (['0', 'false', 'no', 'off', 'garbage', ''] as $restored) {
            DevSetting::set(OutboundMailGuard::TOGGLE_KEY, $restored);
            Cache::flush();
            $this->assertTrue(OutboundMailGuard::isActive(), "restored value '{$restored}' must not switch the guard off on Staging");
        }
        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, null);
        Cache::flush();
        $this->assertTrue(OutboundMailGuard::isActive(), 'no row at all: still intercepting');
    }

    public function test_production_behaviour_is_unchanged_including_its_kill_switch(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za']);

        $this->assertFalse(OutboundMailGuard::isActive(), 'production sends by default');

        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, '0');
        Cache::flush();
        $this->assertFalse(OutboundMailGuard::isActive(), 'forced send is still send on production');

        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, '1');
        Cache::flush();
        $this->assertTrue(OutboundMailGuard::isActive(), 'the super-admin kill switch still works on production');
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

    public function test_forcing_send_is_ignored_on_qa1(): void
    {
        // Was "forcing send wins on qa1" (Johan's 9 Sep ask). Reversed 2026-10-08: the guard is fixed by
        // environment configuration on every non-production environment; a database value cannot open it.
        config(['app.env' => 'qa', 'app.url' => 'https://qatesting1.corexos.co.za']);
        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, '0');

        $this->assertTrue(OutboundMailGuard::isActive());
        $this->assertFalse(OutboundMailGuard::isOverridden());
    }

    public function test_forcing_send_is_ignored_on_a_production_env_that_is_not_the_live_host(): void
    {
        // e.g. /corex "live-testing" and the demo box: APP_ENV=production but not corexos.co.za.
        config(['app.env' => 'production', 'app.url' => 'https://live-testing.corexos.co.za']);
        DevSetting::set(OutboundMailGuard::TOGGLE_KEY, '0');

        $this->assertTrue(OutboundMailGuard::isActive());
    }

    // ── Boot-time check: non-production pointed at a real mail host ─────────

    public function test_boot_check_trips_when_a_non_production_environment_has_a_real_mail_host(): void
    {
        config([
            'app.env' => 'staging', 'app.url' => 'https://staging.corexos.co.za',
            'mail.mailers.smtp.host' => 'mail.hfcoastal.co.za',
            'mail.guard.sink_host' => 'mail.hfcoastal.co.za',
        ]);

        $problems = OutboundMailGuard::auditBootConfiguration();

        $this->assertNotEmpty($problems);
        $this->assertTrue(OutboundMailGuard::isTripped());
        $this->assertTrue(OutboundMailGuard::isActive());
        $this->assertFalse(OutboundMailGuard::hasLocalSink(), 'a tripped guard has no sink: nothing is forwarded anywhere');
    }

    public function test_boot_check_is_quiet_with_a_local_catcher_and_never_runs_on_production(): void
    {
        config([
            'app.env' => 'staging', 'app.url' => 'https://staging.corexos.co.za',
            'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.corex.host' => '127.0.0.1', 'mail.mailers.otp.host' => '127.0.0.1',
            'mail.guard.sink_host' => '127.0.0.1',
        ]);
        $this->assertSame([], OutboundMailGuard::auditBootConfiguration());
        $this->assertFalse(OutboundMailGuard::isTripped());

        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za', 'mail.mailers.smtp.host' => 'smtp.real-provider.example']);
        $this->assertSame([], OutboundMailGuard::auditBootConfiguration(), 'production is meant to use a real host');
        $this->assertFalse(OutboundMailGuard::isTripped());
    }

    public function test_which_hosts_count_as_local_catchers(): void
    {
        foreach (['127.0.0.1', '::1', 'localhost', 'mailpit', '10.1.2.3', '172.17.0.1', '192.168.1.5', 'x.test', '', null] as $local) {
            $this->assertTrue(OutboundMailGuard::isLocalMailHost($local), (string) $local . ' is a local catcher');
        }
        foreach (['mail.hfcoastal.co.za', 'smtp.gmail.com', '8.8.8.8', '62.238.31.82'] as $real) {
            $this->assertFalse(OutboundMailGuard::isLocalMailHost($real), $real . ' is a real mail host');
        }
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
