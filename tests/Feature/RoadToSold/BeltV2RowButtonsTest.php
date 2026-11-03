<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\BeltService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\ProspectStepService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Belt v2 (road-to-sold.md 23.30, Johan 3 Nov 2026): the tile strip reads Today's work / Owner found and has no Won tile; every row carries ONE button named for the next step
 * (from a station list and from My prospects alike); the Swap on a Today's work row is the Stops here control; MIC's "Pitch now" is "Claim".
 */
final class BeltV2RowButtonsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    /** stage => [station key, the row's one button] */
    private const ROWS = [
        S::CLAIMED => ['claimed', 'Find owner'], S::OWNER_FOUND => ['owner', 'Contact'], S::CONTACTED => ['contacted', 'Book appointment'], S::APPOINTMENT_SET => ['booked', 'Prepare presentation'],
        S::PRESENTATION_PREPARED => ['prepared', 'Appointment results'], S::EVALUATION_DONE => ['held', 'Record result'], S::DRAFT => ['draft', 'Make active'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => 'own']);
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $stage, string $street): RtsProspect
    {
        $p = app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '5', 'street_name' => $street])->id, 'sales', ['stage' => $stage]);
        $p->forceFill(['stage_entered_at' => '2026-10-14 08:00:00'])->saveQuietly();

        return $p->fresh();
    }

    public function test_the_tile_strip_reads_todays_work_and_owner_found_and_has_no_won_tile(): void
    {
        $tiles = collect(app(BeltService::class)->tiles($this->agent, $this->agent, 'sales', now()))->keyBy('key');

        $this->assertSame(['loaded', 'claimed', 'owner', 'contacted', 'booked', 'prepared', 'held', 'draft', 'active', 'advertised'], $tiles->keys()->all(), '10 tiles, same keys, no Won');
        $this->assertSame("Today's work", $tiles['loaded']['label']);
        $this->assertSame("Today's work", $tiles['loaded']['short']);
        $this->assertSame('Owner found', $tiles['owner']['label']);
        $this->assertSame('Owner found', $tiles['owner']['short']);

        $html = $this->actingAs($this->agent)->get(route('road-to-sold.index'))->assertOk()->getContent();
        $this->assertStringContainsString("Today's work", $html);
        $this->assertStringNotContainsString('data-qa="belt-tile-won"', $html);
        $this->assertSame(10, preg_match_all('/data-qa="belt-name-[a-z]+"/', $html));
    }

    public function test_every_station_list_row_has_one_button_named_for_the_next_step_plus_view(): void
    {
        foreach (self::ROWS as $stage => [$station, $label]) {
            $this->at($stage, ucfirst($station) . ' Row');
            $html = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'station', 'station' => $station]))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'data-qa="station-action"'), "$station: one button on the row");
            $this->assertMatchesRegularExpression('/data-qa="station-action"[^>]*data-action="' . preg_quote($label, '/') . '"/', $html, "$station: $label");
            $this->assertSame(1, substr_count($html, 'data-qa="station-view"'), "$station: and View");
            $this->assertStringNotContainsString('data-action="Log contact"', $html, "$station: Log contact is not on the row");
        }
    }

    public function test_my_prospects_cards_offer_the_same_one_action_and_the_rest_sits_behind_more(): void
    {
        $steps = app(ProspectStepService::class);
        $expect = [S::FOUND => 'Find owner', S::CLAIMED => 'Find owner', S::OWNER_FOUND => 'Contact', S::CONTACTED => 'Book appointment', S::APPOINTMENT_SET => 'Prepare presentation', S::PRESENTATION_PREPARED => 'Appointment results', S::DRAFT => 'Make active'];
        foreach ($expect as $stage => $label) {
            $this->assertSame($label, $steps->primary(['stage' => $stage], true)['label'], $stage);
        }
        $this->at(S::EVALUATION_DONE, 'Held Card');
        $html = $this->actingAs($this->agent)->get(route('road-to-sold.index', ['block' => 'prospects']))->assertOk()->getContent();

        $this->assertStringContainsString('Record result', $html);
        $this->assertStringNotContainsString('data-qa="prospect-log-contact"', $html, 'no Log contact button on the card');
        $this->assertLessThan(strpos($html, 'Stops here (say why)</button>'), strpos($html, 'data-qa="card-more-menu"'), 'Stops here sits behind More');
    }

    public function test_todays_work_rows_have_find_owner_and_swap_only_and_the_swap_is_the_stops_here_control(): void
    {
        $tp = $this->tp(['street_name' => 'Today Road', 'street_number' => '9']);
        $p = app(ProspectService::class)->place($this->agent, (int) $tp->id, 'sales', ['stage' => S::FOUND]);
        $item = RtsListItem::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id, 'list_date' => '2026-10-14', 'track' => 'sales',
            'tracked_property_id' => $tp->id, 'prospect_id' => $p->id, 'selection' => 'price_drops', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'Price dropped',
        ]);

        $html = $this->actingAs($this->agent)->get(route('road-to-sold.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-qa="intake-step"'));
        $this->assertStringContainsString('>Find owner</button>', $html);
        $this->assertSame(1, substr_count($html, 'data-qa="swap-open"'));
        $this->assertStringNotContainsString('data-qa="log-contact"', $html);
        $this->assertStringContainsString('CorexOffRamp.mount', $html, 'the Swap is the one Stops here control');

        // one list: the step 1 "Not for me" reasons, "Other" last
        $d = $this->actingAs($this->agent)->getJson(route('road-to-sold.offramp', ['item' => $item->id, 'step' => 1]))->assertOk()->json();
        $this->assertSame('not_for_me_wrong_area', $d['reasons'][0]['key']);
        $this->assertTrue((bool) end($d['reasons'])['other']);

        // and it is counted at Today's work: the swap releases the record at the loaded station
        $this->actingAs($this->agent)->postJson(route('road-to-sold.swap', $item->id), ['reason_key' => 'not_taking_this', 'step' => 1])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('swapped', $item->fresh()->status);
        $this->actingAs($this->agent)->postJson(route('road-to-sold.swap', $item->id), ['reason_key' => 'nonsense'])->assertStatus(422);
    }

    public function test_mic_says_claim_where_it_said_pitch_now(): void
    {
        $resolver = (string) file_get_contents(app_path('Services/Prospecting/SuggestedActionResolver.php'));
        $this->assertStringNotContainsString("'PITCH NOW", $resolver);
        $this->assertSame(3, substr_count($resolver, "label:       'CLAIM"));
        $this->assertStringContainsString("'label'=>'Claim · high'", (string) file_get_contents(resource_path('views/corex/market-intelligence/_stats-strip.blade.php')));
        $this->assertStringContainsString("'action_label' => 'Claim'", (string) file_get_contents(app_path('Services/MarketIntelligence/ThisWeekTileBuilder.php')));
    }
}
