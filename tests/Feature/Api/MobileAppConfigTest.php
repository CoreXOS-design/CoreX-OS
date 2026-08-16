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
}
