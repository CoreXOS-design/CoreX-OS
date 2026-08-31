<?php

namespace Tests\Feature\Api;

use App\Models\DevSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the forced-update gate, whose failure modes are asymmetric: letting an
 * old build through for another hour is a minor annoyance, while wrongly gating
 * a build locks every agent out of the app at once. Every test here is therefore
 * about the gate staying OFF unless it has been deliberately, correctly armed.
 *
 * @see \App\Http\Controllers\Api\V1\MobileAppConfigController
 */
class MobileAppConfigTest extends TestCase
{
    use RefreshDatabase;

    private function fetchConfig(string $platform, int $build): array
    {
        return $this->getJson("/api/v1/mobile/app-config?platform={$platform}&build={$build}")
            ->assertOk()
            ->json();
    }

    public function test_it_is_reachable_without_authentication(): void
    {
        // The whole point: a build too old to log in must still get an answer.
        $this->getJson('/api/v1/mobile/app-config?platform=android&build=1')
            ->assertOk();
    }

    public function test_the_gate_is_off_by_default(): void
    {
        $body = $this->fetchConfig('android', 1);

        $this->assertSame(0, $body['min_build']);
        $this->assertFalse($body['update_required']);
    }

    public function test_it_gates_a_build_below_the_minimum(): void
    {
        DevSetting::set('mobile_min_build_android', '13');

        $body = $this->fetchConfig('android', 12);

        $this->assertSame(13, $body['min_build']);
        $this->assertTrue($body['update_required']);
        $this->assertNotEmpty($body['update_url']);
    }

    public function test_it_allows_the_minimum_build_and_anything_newer(): void
    {
        DevSetting::set('mobile_min_build_android', '13');

        $this->assertFalse($this->fetchConfig('android', 13)['update_required']);
        $this->assertFalse($this->fetchConfig('android', 14)['update_required']);
    }

    public function test_it_never_gates_ios_without_a_configured_update_url(): void
    {
        // The App Store URL contains a numeric id we cannot derive, so iOS has
        // no default. Gating here would leave agents behind an Update button
        // that goes nowhere — strictly worse than running the old build.
        DevSetting::set('mobile_min_build_ios', '13');

        $body = $this->fetchConfig('ios', 12);

        $this->assertSame(0, $body['min_build']);
        $this->assertFalse($body['update_required']);
        $this->assertNull($body['update_url']);
    }

    public function test_it_gates_ios_once_an_update_url_is_configured(): void
    {
        DevSetting::set('mobile_min_build_ios', '13');
        DevSetting::set('mobile_update_url_ios', 'https://apps.apple.com/za/app/corex-os/id123456789');

        $body = $this->fetchConfig('ios', 12);

        $this->assertTrue($body['update_required']);
        $this->assertSame('https://apps.apple.com/za/app/corex-os/id123456789', $body['update_url']);
    }

    public function test_it_never_gates_an_unrecognised_platform(): void
    {
        DevSetting::set('mobile_min_build_android', '99');
        DevSetting::set('mobile_min_build_ios', '99');

        foreach (['web', 'other', ''] as $platform) {
            $body = $this->fetchConfig($platform, 1);
            $this->assertFalse($body['update_required'], "platform '{$platform}' must not be gated");
        }
    }

    public function test_dropping_the_minimum_back_to_zero_disarms_the_gate(): void
    {
        // The emergency off-switch. If this stops working, a bad cutoff cannot
        // be undone without an app release.
        DevSetting::set('mobile_min_build_android', '13');
        $this->assertTrue($this->fetchConfig('android', 12)['update_required']);

        DevSetting::set('mobile_min_build_android', '0');
        $this->assertFalse($this->fetchConfig('android', 12)['update_required']);
    }

    public function test_it_passes_through_a_custom_message(): void
    {
        DevSetting::set('mobile_min_build_android', '13');
        DevSetting::set('mobile_update_message', 'Update to restore lead notifications.');

        $this->assertSame(
            'Update to restore lead notifications.',
            $this->fetchConfig('android', 12)['message'],
        );
    }

    // ── Optional "update available" notice — a separate dial from min_build ──

    public function test_the_notice_is_off_when_latest_build_is_unset(): void
    {
        $body = $this->fetchConfig('android', 1);

        $this->assertSame(0, $body['latest_build']);
        $this->assertNull($body['latest_version']);
        $this->assertFalse($body['update_available']);
    }

    public function test_it_announces_an_update_for_a_build_behind_latest(): void
    {
        DevSetting::set('mobile_latest_build_android', '20');
        DevSetting::set('mobile_latest_version', '1.1.0');

        $body = $this->fetchConfig('android', 19);

        $this->assertSame(20, $body['latest_build']);
        $this->assertSame('1.1.0', $body['latest_version']);
        $this->assertTrue($body['update_available']);
    }

    public function test_it_does_not_announce_for_a_build_at_or_ahead_of_latest(): void
    {
        DevSetting::set('mobile_latest_build_android', '20');

        $this->assertFalse($this->fetchConfig('android', 20)['update_available']);
        $this->assertFalse($this->fetchConfig('android', 21)['update_available']);
    }

    public function test_it_never_announces_ios_without_a_configured_update_url(): void
    {
        // Same hard safety rule as the forced gate: latest_build is force-zeroed
        // even though the DevSetting itself is set, because there is nowhere to
        // send the user. An "Update now" button that opens nothing is worse
        // than staying quiet.
        DevSetting::set('mobile_latest_build_ios', '20');

        $body = $this->fetchConfig('ios', 19);

        $this->assertSame(0, $body['latest_build']);
        $this->assertFalse($body['update_available']);
    }

    public function test_it_never_announces_an_unrecognised_platform(): void
    {
        DevSetting::set('mobile_latest_build_android', '99');
        DevSetting::set('mobile_latest_build_ios', '99');

        foreach (['web', 'other', ''] as $platform) {
            $body = $this->fetchConfig($platform, 1);
            $this->assertSame(0, $body['latest_build'], "platform '{$platform}' must not get a latest_build");
            $this->assertNull($body['latest_version'], "platform '{$platform}' must not get a latest_version");
            $this->assertFalse($body['update_available'], "platform '{$platform}' must not be announced to");
        }
    }

    public function test_the_forced_gate_still_wins_when_a_build_is_behind_both(): void
    {
        // min_build and latest_build are independent dials, but when a build is
        // behind BOTH, the forced gate is the one that matters — the app never
        // gets as far as showing the optional dialog.
        DevSetting::set('mobile_min_build_android', '13');
        DevSetting::set('mobile_latest_build_android', '20');

        $body = $this->fetchConfig('android', 12);

        $this->assertTrue($body['update_required']);
        $this->assertTrue($body['update_available']);
        $this->assertSame(13, $body['min_build']);
        $this->assertSame(20, $body['latest_build']);
    }

    // ── update_available_message ────────────────────────────────────────────
    // The dismissible notice needs its OWN copy. `message` is the forced-gate
    // wording ("no longer supported"), which on a dialog the agent can dismiss
    // is simply false — and a false blocking message is how agents learn to
    // dismiss the real one without reading it.

    public function test_the_notice_copy_is_returned_when_set(): void
    {
        DevSetting::set('mobile_update_available_message', 'Version 1.1 adds offline photo uploads.');

        $this->assertSame(
            'Version 1.1 adds offline photo uploads.',
            $this->fetchConfig('android', 10)['update_available_message']
        );
    }

    public function test_the_notice_copy_is_null_when_blank_so_the_app_uses_its_own(): void
    {
        DevSetting::set('mobile_update_available_message', '   ');

        $this->assertNull($this->fetchConfig('android', 10)['update_available_message']);
    }

    public function test_the_notice_copy_is_never_confused_with_the_blocking_copy(): void
    {
        DevSetting::set('mobile_update_message', 'This version is no longer supported.');
        DevSetting::set('mobile_update_available_message', 'A new version is available.');

        $body = $this->fetchConfig('android', 10);

        $this->assertSame('This version is no longer supported.', $body['message']);
        $this->assertSame('A new version is available.', $body['update_available_message']);
    }

    // ── per-platform latest_version ─────────────────────────────────────────

    public function test_latest_version_resolves_per_platform(): void
    {
        DevSetting::set('mobile_latest_version_android', '1.0.11');
        DevSetting::set('mobile_latest_version_ios', '1.2.0');

        $this->assertSame('1.0.11', $this->fetchConfig('android', 1)['latest_version']);
        $this->assertSame('1.2.0', $this->fetchConfig('ios', 1)['latest_version']);
    }

    public function test_latest_version_falls_back_to_the_global_key(): void
    {
        // Set before the per-platform split; must keep working mid-flight.
        DevSetting::set('mobile_latest_version', '1.0.9');

        $this->assertSame('1.0.9', $this->fetchConfig('android', 1)['latest_version']);
    }

    public function test_a_per_platform_version_wins_over_the_global_one(): void
    {
        DevSetting::set('mobile_latest_version', '1.0.9');
        DevSetting::set('mobile_latest_version_ios', '1.2.0');

        $this->assertSame('1.2.0', $this->fetchConfig('ios', 1)['latest_version']);
        $this->assertSame('1.0.9', $this->fetchConfig('android', 1)['latest_version']);
    }

    // ── the "nowhere to send them" rule ─────────────────────────────────────

    public function test_a_platform_with_no_update_url_has_both_dials_forced_off(): void
    {
        // iOS has no derivable store URL, so it stays inert until one is set —
        // however loudly the dials are turned up. Blocking or nagging an agent
        // behind a button that opens nothing is worse than staying quiet.
        DevSetting::set('mobile_min_build_ios', '30');
        DevSetting::set('mobile_latest_build_ios', '31');
        DevSetting::set('mobile_update_url_ios', '');

        $body = $this->fetchConfig('ios', 1);

        $this->assertSame(0, $body['min_build']);
        $this->assertSame(0, $body['latest_build']);
        $this->assertFalse($body['update_required']);
        $this->assertFalse($body['update_available']);
    }

    public function test_setting_the_ios_url_arms_the_dials_that_were_already_set(): void
    {
        DevSetting::set('mobile_min_build_ios', '30');
        DevSetting::set('mobile_latest_build_ios', '31');
        DevSetting::set('mobile_update_url_ios', 'https://apps.apple.com/za/app/corex-os/id123456789');

        $body = $this->fetchConfig('ios', 29);

        $this->assertSame(30, $body['min_build']);
        $this->assertSame(31, $body['latest_build']);
        $this->assertTrue($body['update_required']);
    }

    // ── unknown platforms ───────────────────────────────────────────────────

    public function test_an_unrecognised_platform_is_never_gated(): void
    {
        DevSetting::set('mobile_min_build_android', '99');
        DevSetting::set('mobile_latest_build_android', '99');

        foreach (['web', 'huawei', ''] as $platform) {
            $body = $this->getJson("/api/v1/mobile/app-config?platform={$platform}&build=1")->assertOk()->json();

            $this->assertSame(0, $body['min_build'], "platform '{$platform}' must not be gated");
            $this->assertSame(0, $body['latest_build']);
            $this->assertFalse($body['update_required']);
            $this->assertArrayHasKey('update_available_message', $body,
                'The key must exist for every platform so the client never has to feature-detect it.');
        }
    }
}
