<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Http\Controllers\CoreX\RentalPortalSettingsController;
use App\Models\RentalPortalSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.27.3 — Build 1's five crew-link settings:
 * defaults, ranges, the settings screen, and the Setup Wizard wiring (the keys
 * are declared with `explain` + `affects`, each has its own saver, and a saver
 * never wipes a setting its step did not post — onboarding §6.1).
 */
final class RentalCrewLinkSettingsTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private const KEYS = [
        'crew_links_enabled', 'crew_job_link_expiry_days', 'crew_link_show_costs',
        'crew_link_show_tenant_contact', 'notify_landlord_on_crew_completion',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Settings');
    }

    public function test_defaults(): void
    {
        $id = $this->agency->id;
        $this->assertTrue(RentalPortalSetting::crewLinksEnabledFor($id));
        $this->assertSame(14, RentalPortalSetting::crewJobLinkExpiryDaysFor($id));
        $this->assertFalse(RentalPortalSetting::crewLinkShowCostsFor($id));
        $this->assertFalse(RentalPortalSetting::crewLinkShowTenantContactFor($id));
        $this->assertTrue(RentalPortalSetting::notifyLandlordOnCrewCompletionFor($id));
    }

    public function test_the_settings_are_per_agency(): void
    {
        $other = \App\Models\Agency::create(['name' => 'Second Agency', 'slug' => 'second-' . uniqid()]);
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_job_link_expiry_days' => 30, 'crew_link_show_costs' => true]);

        $this->assertSame(30, RentalPortalSetting::crewJobLinkExpiryDaysFor($this->agency->id));
        $this->assertSame(14, RentalPortalSetting::crewJobLinkExpiryDaysFor($other->id));
        $this->assertFalse(RentalPortalSetting::crewLinkShowCostsFor($other->id));
    }

    public function test_the_expiry_is_used_when_a_link_is_minted(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_job_link_expiry_days' => 3]);
        $card = $this->makeJobCard();

        $issued = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);

        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $issued['token']->expires_at->timestamp, 5);
    }

    public function test_the_settings_screen_shows_the_crew_links_section_and_saves_each_setting(): void
    {
        $this->actingAs($this->admin)->get(route('corex.settings.rental-portal.edit'))
            ->assertOk()->assertSee('Crew links')->assertSee('Job link expiry (days)')->assertSee('Show costs on the crew')->assertSee('tenant');

        $this->actingAs($this->admin)->post(route('corex.settings.rental-portal.crew-job-link-expiry-days'), ['crew_job_link_expiry_days' => 21])->assertRedirect();
        $this->actingAs($this->admin)->post(route('corex.settings.rental-portal.crew-link-show-costs'), ['crew_link_show_costs' => '1'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('corex.settings.rental-portal.crew-link-show-tenant-contact'), ['crew_link_show_tenant_contact' => '1'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('corex.settings.rental-portal.notify-landlord-on-crew-completion'), ['notify_landlord_on_crew_completion' => '0'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('corex.settings.rental-portal.crew-links-enabled'), ['crew_links_enabled' => '0'])->assertRedirect();

        $id = $this->agency->id;
        $this->assertSame(21, RentalPortalSetting::crewJobLinkExpiryDaysFor($id));
        $this->assertTrue(RentalPortalSetting::crewLinkShowCostsFor($id));
        $this->assertTrue(RentalPortalSetting::crewLinkShowTenantContactFor($id));
        $this->assertFalse(RentalPortalSetting::notifyLandlordOnCrewCompletionFor($id));
        $this->assertFalse(RentalPortalSetting::crewLinksEnabledFor($id));
    }

    public function test_the_expiry_range_is_1_to_90(): void
    {
        foreach ([0, 91, -5, 'abc', 1.5] as $bad) {
            $this->actingAs($this->admin)->post(route('corex.settings.rental-portal.crew-job-link-expiry-days'), ['crew_job_link_expiry_days' => $bad])
                ->assertSessionHasErrors('crew_job_link_expiry_days');
        }
        $this->assertSame(14, RentalPortalSetting::crewJobLinkExpiryDaysFor($this->agency->id));

        foreach ([1, 90] as $ok) {
            $this->actingAs($this->admin)->post(route('corex.settings.rental-portal.crew-job-link-expiry-days'), ['crew_job_link_expiry_days' => $ok])->assertSessionHasNoErrors();
            $this->assertSame($ok, RentalPortalSetting::crewJobLinkExpiryDaysFor($this->agency->id));
        }
    }

    public function test_a_saver_posted_without_its_field_leaves_the_saved_value_alone(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_job_link_expiry_days' => 45]);
        $request = Request::create('/x', 'POST', []);
        $request->setUserResolver(fn () => $this->admin);

        app(RentalPortalSettingsController::class)->updateCrewJobLinkExpiryDays($request);

        $this->assertSame(45, RentalPortalSetting::crewJobLinkExpiryDaysFor($this->agency->id));
    }

    public function test_every_key_is_in_the_wizard_with_explain_affects_and_a_saver(): void
    {
        $step = config('agency-onboarding-copy.leases');
        $controls = collect($step['controls'])->keyBy('key');
        $savers = collect($step['savers'])->where('controller', RentalPortalSettingsController::class)->pluck('method')->all();

        foreach (self::KEYS as $key) {
            $control = $controls->get($key);
            $this->assertNotNull($control, "{$key} must be in the wizard");
            $this->assertSame('rental_portal', $control['source']);
            $this->assertGreaterThan(40, strlen($control['explain']), "{$key} needs a full-sentence explain");
            $this->assertGreaterThan(40, strlen($control['affects']), "{$key} needs a concrete affects");
        }
        foreach (['updateCrewLinksEnabled', 'updateCrewJobLinkExpiryDays', 'updateCrewLinkShowCosts', 'updateCrewLinkShowTenantContact', 'updateNotifyLandlordOnCrewCompletion'] as $method) {
            $this->assertContains($method, $savers);
            $this->assertTrue(method_exists(RentalPortalSettingsController::class, $method));
        }

        // inserted directly after the contractor expiry row (§14.30)
        $order = $controls->keys()->values();
        $this->assertSame($order->search('contractor_secure_link_expiry_days') + 1, $order->search('crew_links_enabled'));
    }

    public function test_the_wizard_step_shows_the_saved_values_not_the_hardcoded_defaults(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_job_link_expiry_days' => 37]);

        $this->actingAs($this->admin)->get(route('corex.agency-setup.step', ['step' => 'leases']))
            ->assertOk()->assertSee('value="37"', false)->assertSee('Crew job link expiry (days)');
    }

    public function test_no_agency_specific_wording_in_the_wizard_copy(): void
    {
        foreach (self::KEYS as $key) {
            $control = collect(config('agency-onboarding-copy.leases.controls'))->firstWhere('key', $key);
            $this->assertStringNotContainsStringIgnoringCase('home finders', $control['explain'] . $control['affects'] . $control['label']);
            $this->assertStringNotContainsStringIgnoringCase('hfc', $control['explain'] . $control['affects'] . $control['label']);
        }
    }
}
