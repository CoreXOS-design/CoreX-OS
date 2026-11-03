<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\MicAgencySetting;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Services\Prospecting\MicWork\MicWorkTiles;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** "Long on market: more than N days" is ONE agency setting (Road to Sold, standard 90); the MIC tile and its "+N DOM" line follow it (Johan, 2 Nov 2026). */
final class LongOnMarketSettingTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
        Cache::flush();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function dom(): string
    {
        return MicWorkTiles::longOnMarketLabel($this->agency->id);
    }

    public function test_the_standard_is_90_for_both_and_mic_has_no_window_of_its_own(): void
    {
        $this->assertSame(90, RtsSetting::SCALAR_DEFAULTS['long_on_market_days']);
        $this->assertSame(90, MicAgencySetting::for($this->agency->id)->longOnMarketDays());
        $this->assertSame('+90 DOM', $this->dom());

        // a value an agency once stored under MIC's old key is not read any more
        MicAgencySetting::saveValues($this->agency->id, ['stuck_dom_days' => 45]);
        $this->assertSame('+90 DOM', $this->dom());
        $this->assertFalse(array_key_exists('stuck_dom_days', MicAgencySetting::DEFAULTS));
    }

    public function test_saving_the_road_to_sold_setting_changes_the_mic_tile_straight_away(): void
    {
        $page = fn () => $this->actingAs($this->admin)->get(route('market-intelligence.work', ['tile' => 'stuck']))->assertOk();
        $page()->assertSee('Tile · +90 DOM', false);   // builds (and caches) the tile row at 90

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['long_on_market_days' => 30])->assertSessionHasNoErrors();
        SettingsService::forget();

        $this->assertSame('+30 DOM', $this->dom());
        $this->assertSame(30, MicAgencySetting::for($this->agency->id)->longOnMarketDays());
        $page()->assertSee('Tile · +30 DOM', false)->assertDontSee('Tile · +90 DOM', false);
        $tile = collect(MicWorkTiles::fromContainer()->row($this->agency->id, $this->admin))->firstWhere('number', 1);
        $this->assertSame('+30 DOM', $tile['threshold'], 'the line under the tile name');
        $this->assertSame('Long on market (+30 DOM)', $tile['title']);
        $this->assertSame('stuck', $tile['key'], 'the link key stays');
    }

    public function test_the_settings_page_says_the_window_is_shared_and_no_screen_calls_it_stuck(): void
    {
        $html = $this->actingAs($this->admin)->get(route('road-to-sold.settings.index'))->assertOk()
            ->assertSee('Long on market: more than', false)->assertSee('data-qa="long-on-market-note"', false)->getContent();
        $this->assertStringContainsString('also sets the Long on market tile in Market Intelligence', $html);
        $visible = strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $html));
        $this->assertDoesNotMatchRegularExpression('/\bstuck\b/i', $visible, 'the word stuck never reaches the agent');
        $mic = strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->getContent()));
        $this->assertDoesNotMatchRegularExpression('/\bstuck\b/i', $mic);
    }
}
