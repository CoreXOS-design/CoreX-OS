<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Belt wording sweep (3 Nov 2026): the belt, its lists, buttons, outcomes, funnel and report use the TILE names and the step names. "Log contact" and "Pitch now" are gone;
 * "evaluation" stays only as the calendar's own event type ("Property evaluation"); everywhere else on the belt it is "appointment". Labels only - keys are untouched.
 */
final class BeltWordingTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => 'own']);
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** the words an agent can read: no scripts, styles or tags; the calendar's own "Property evaluation" is the one allowed use of the word */
    private function words(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html);
        $text = html_entity_decode(strip_tags($html));

        return str_ireplace('Property evaluation', '', $text);
    }

    public function test_the_seeded_outcome_says_appointment_and_an_old_row_is_reworded_once_with_its_key_unchanged(): void
    {
        $svc = app(OutcomeDefinitionService::class);
        $svc->ensureDefaults($this->agency->id);
        $row = Def::withTrashed()->where('agency_id', $this->agency->id)->where('key', 'spoke_evaluation_booked')->firstOrFail();
        $this->assertSame('Spoke - appointment booked', $row->label);

        // a row still carrying the old seeded wording is reworded; one the agency wrote itself is left alone
        $row->forceFill(['label' => 'Spoke - evaluation booked'])->save();
        OutcomeDefinitionService::forgetMemo();
        $svc->ensureDefaults($this->agency->id);
        $this->assertSame('Spoke - appointment booked', $row->fresh()->label);
        $row->forceFill(['label' => 'Seller wants a valuation'])->save();
        OutcomeDefinitionService::forgetMemo();
        $svc->ensureDefaults($this->agency->id);
        $this->assertSame('Seller wants a valuation', $row->fresh()->label);
    }

    public function test_the_belt_pages_use_the_tile_and_step_names_and_none_of_the_old_words(): void
    {
        foreach ([S::CLAIMED, S::OWNER_FOUND, S::CONTACTED, S::APPOINTMENT_SET, S::PRESENTATION_PREPARED, S::EVALUATION_DONE, S::DRAFT] as $i => $stage) {
            $p = app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => (string) ($i + 1), 'street_name' => 'Words Road'])->id, 'sales', ['stage' => $stage]);
            $p->forceFill(['stage_entered_at' => '2026-10-14 08:00:00'])->saveQuietly();
        }
        $pages = ['home' => route('road-to-sold.index'), 'prospects' => route('road-to-sold.index', ['block' => 'prospects']), 'funnel' => route('road-to-sold.belt-funnel')];
        foreach (['claimed', 'owner', 'contacted', 'booked', 'prepared', 'held', 'draft'] as $station) {
            $pages["station-$station"] = route('road-to-sold.index', ['block' => 'station', 'station' => $station]);
        }
        foreach ($pages as $name => $url) {
            $text = $this->words($this->actingAs($this->agent)->get($url)->assertOk()->getContent());
            $this->assertDoesNotMatchRegularExpression('/Log contact|Log this call|Pitch now|To pitch/i', $text, "$name: no Log contact / Pitch now");
            preg_match('/.{0,40}\bevaluations?\b.{0,40}/is', $text, $mm);
            $this->assertSame([], $mm, "$name: the belt says appointment");
        }

        $held = $this->words($this->actingAs($this->agent)->get($pages['station-booked'])->getContent());
        $this->assertStringContainsString('Booked - to do', $held);
        $this->assertStringContainsString('Held - to do', $this->words($this->actingAs($this->agent)->get($pages['station-held'])->getContent()));
        $home = $this->words($this->actingAs($this->agent)->get($pages['home'])->getContent());
        $this->assertStringContainsString("Today's work", $home);
    }

    public function test_the_next_action_banner_says_contact_and_the_funnel_and_report_labels_follow_the_tiles(): void
    {
        $nextAction = (string) file_get_contents(app_path('Services/RoadToSold/NextActionService.php'));
        $this->assertStringContainsString("'label' => 'Contact'", $nextAction);
        $this->assertStringNotContainsString("'Log contact'", $nextAction);

        $funnel = \App\Services\RoadToSold\BeltFunnelService::STATIONS;
        $this->assertSame("Today's work", $funnel['loaded'][0]);
        $this->assertSame('Booked', $funnel['booked'][0]);
        $this->assertSame('Held', $funnel['held'][0]);
        $this->assertSame('Prepared', $funnel['prepared'][0]);
        foreach (['config/road-to-sold-belt.php', 'app/Services/RoadToSold/ReportService.php', 'app/Services/RoadToSold/FunnelService.php', 'app/Services/RoadToSold/DrilldownService.php', 'app/Services/RoadToSold/RecipeCatalogue.php'] as $f) {
            preg_match_all("/'label'\\s*=>\\s*'([^']*[Ee]valuation[^']*)'/", (string) file_get_contents(base_path($f)), $m);
            $labels = array_filter($m[1], fn ($l) => ! preg_match('/^(evaluation-|evaluations_)/', $l));
            $this->assertSame([], array_values($labels), "$f: labels say appointment");
        }
    }

    public function test_the_swap_control_says_not_for_me_and_swap_and_the_mic_chip_says_to_claim(): void
    {
        $home = (string) file_get_contents(resource_path('views/road-to-sold/index.blade.php'));
        $this->assertStringContainsString("title: 'Not for me', goLabel: 'Swap'", $home);
        $js = (string) file_get_contents(public_path('js/pitch-offramp.js'));
        $this->assertStringContainsString("o.goLabel || 'Stop here'", $js);
        $this->assertStringContainsString("(o.title ? o.title : 'Stops here - '", $js);
        $this->assertStringContainsString("'To claim'", (string) file_get_contents(resource_path('views/corex/market-intelligence/_stats-strip.blade.php')));
    }
}
