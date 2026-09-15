<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §5.2 / conductor ruling 2026-09-15 — the expiry-notice
 * window is agency-configurable with a sensible default (60 days), never
 * hardcoded, and ships with its own control from day one. Verified for real
 * via a live HTTP walk (settings screen + wizard step, both save paths) in
 * addition to this automated coverage — see the commit message.
 */
final class LeaseSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_is_60_days_when_nothing_saved(): void
    {
        self::assertSame(60, LeaseSetting::expiryNoticeWindowDaysFor(null));
        self::assertSame(60, LeaseSetting::expiryNoticeWindowDaysFor(999999));
    }

    public function test_saved_value_overrides_the_default(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);

        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 45]);

        self::assertSame(45, LeaseSetting::expiryNoticeWindowDaysFor($agency->id));
    }

    public function test_settings_screen_saves_a_new_value(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.settings.leases.edit'));
        $response->assertOk();
        $response->assertSee('value="60"', false);

        $this->actingAs($user)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 90,
        ])->assertRedirect(route('corex.settings.leases.edit'));

        self::assertSame(90, LeaseSetting::expiryNoticeWindowDaysFor($agency->id));

        $this->actingAs($user)->get(route('corex.settings.leases.edit'))
            ->assertSee('value="90"', false);
    }
}
