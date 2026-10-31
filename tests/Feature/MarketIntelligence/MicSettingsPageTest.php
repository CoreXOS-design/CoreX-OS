<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\MicAgencySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** mic-work-tab.md section 9 (step 6.5): the MIC Settings page - per agency, validated, and read back by the Work tab and the other lanes' accessors. */
final class MicSettingsPageTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;
    private Agency $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function payload(array $over = []): array
    {
        return array_replace_recursive([
            'refresh_interval_days' => 5, 'gone_tile_days' => 20, 'neighbour_months' => 4, 'neighbour_each_side' => 6,
            'detail_pages_per_session' => 30, 'area_agency_pages_per_run' => 8, 'area_street_pages_per_run' => 120,
            'tiles_shown' => [1, 2, 4, 8],
            'weights' => ['seller' => 40, 'buyer' => 20, 'pocket' => 15, 'known' => 15, 'reachable' => 10],
        ], $over);
    }

    public function test_page_renders_with_defaults_and_the_matching_summary_and_has_a_tab(): void
    {
        $this->actingAs($this->admin)->get(route('market-intelligence.settings'))->assertOk()
            ->assertSee('data-qa="mic-settings-form"', false)->assertSee('data-qa="matching-summary"', false)->assertSee('Opportunity score weights', false);
        $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->assertSee(route('market-intelligence.settings'), false);
    }

    public function test_saving_stores_per_agency_values_that_the_accessors_and_the_tile_row_read_back(): void
    {
        $this->actingAs($this->admin)->put(route('market-intelligence.settings.update'), $this->payload())->assertRedirect(route('market-intelligence.settings'));

        $s = MicAgencySetting::for($this->agency->id);
        $this->assertSame(5, MicAgencySetting::refreshIntervalDays($this->agency->id));
        $this->assertSame(4, MicAgencySetting::neighbourMonths($this->agency->id));
        $this->assertSame(6, MicAgencySetting::neighbourEachSide($this->agency->id));
        $this->assertSame(20, MicAgencySetting::goneTileDays($this->agency->id));
        $this->assertSame(30, $s->detailPagesPerSession());
        $this->assertSame(120, MicAgencySetting::areaStreetPagesPerRun($this->agency->id));
        $this->assertSame(40, $s->scoreWeights()['seller']);
        $this->assertSame([1, 2, 4, 8], $s->tilesShown());
        $this->assertFalse($s->optOutRegistryCheck(), 'the opt-out registry switch is OFF unless ticked');
        $this->assertSame(7, MicAgencySetting::refreshIntervalDays($this->b->id), 'another agency keeps the default');
        $this->assertSame(0, DB::table('mic_agency_settings')->where('agency_id', $this->b->id)->count());

        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work'))->getContent();
        $this->assertStringContainsString('data-qa="tile-8"', $html);
        $this->assertStringNotContainsString('data-qa="tile-3"', $html, 'a tile the agency switched off is not shown');
    }

    public function test_weights_must_add_up_to_100_and_at_least_one_tile_must_show(): void
    {
        $this->actingAs($this->admin)->put(route('market-intelligence.settings.update'), $this->payload(['weights' => ['seller' => 50]]))->assertSessionHasErrors('weights');
        $this->actingAs($this->admin)->put(route('market-intelligence.settings.update'), array_diff_key($this->payload(), ['tiles_shown' => 1]))->assertSessionHasErrors('tiles_shown');
        $this->assertSame(0, DB::table('mic_agency_settings')->where('agency_id', $this->agency->id)->count(), 'nothing saved on a rejected form');
        $this->actingAs($this->admin)->put(route('market-intelligence.settings.update'), $this->payload(['neighbour_months' => 9]))->assertSessionHasErrors('neighbour_months');
    }

    public function test_areas_by_exact_name_only(): void
    {
        DB::table('p24_suburbs')->insert(['name' => 'Margate', 'slug' => 'margate', 'p24_id' => 987001, 'created_at' => now(), 'updated_at' => now()]);
        $id = (int) DB::table('p24_suburbs')->where('name', 'Margate')->value('id');
        $this->actingAs($this->admin)->put(route('market-intelligence.settings.update'), $this->payload(['add_areas' => 'margate, Nowheresville']))
            ->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'Nowheresville'));
        $this->assertSame([$id], MicAgencySetting::for($this->agency->id)->areas());
    }

    public function test_count_a_suburb_as_ours_is_saved_read_back_and_an_older_form_without_it_does_not_reset_it(): void
    {
        $this->actingAs($this->admin)->put(route('market-intelligence.settings.update'), $this->payload(['working_suburb_min_count' => 5]))->assertRedirect(route('market-intelligence.settings'));
        $this->assertSame(5, \App\Models\MicAgencySetting::for((int) $this->admin->agency_id)->workingSuburbMinCount());
        $this->actingAs($this->admin)->get(route('market-intelligence.settings'))->assertOk()->assertSee('Count a suburb as ours from', false);

        $this->actingAs($this->admin)->put(route('market-intelligence.settings.update'), $this->payload())->assertRedirect();
        $this->assertSame(5, \App\Models\MicAgencySetting::for((int) $this->admin->agency_id)->workingSuburbMinCount(), 'a form that did not post the field leaves it alone');

        $this->actingAs($this->admin)->put(route('market-intelligence.settings.update'), $this->payload(['working_suburb_min_count' => 0]))->assertSessionHasErrors('working_suburb_min_count');
    }
}
