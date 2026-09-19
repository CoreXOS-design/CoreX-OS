<?php

namespace Tests\Feature\Tours;

use App\Models\User;
use App\Services\AI\Ellie\EllieToolkit;
use App\Services\AI\TourKnowledgeService;
use App\Services\Features\AgencyFeatureService;
use App\Support\Tours\TourRegistry;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * Advanced Guiding + Spot Help — guards the hands-on half of the tour catalogue
 * and Ellie's guide buttons. Spec: .ai/specs/advanced-guiding.md.
 *
 * A hands-on guide fails SILENTLY in the browser: a `do` rule pointing at a
 * missing box is skipped, an `until` that never matches strands the agent on a
 * step. These assertions make those failures loud at build time.
 *
 * Pure static assertions + mocked users: no DB, so it runs fast and is immune
 * to the test-DB infra baseline.
 */
class AdvancedGuidingTest extends TestCase
{
    private const ACTIONS = ['fill', 'choose', 'click', 'appear'];

    private const PREP_ACTIONS = ['alpineSet', 'click', 'scrollTop', 'dispatch'];

    /**
     * Tours whose screen no longer renders, so no hands-on guide can run on them.
     * Deal Register V2's create + detail pages were retired: DealV2Controller
     * redirects both to the DR2 register (dr2RetiredRedirect()). Whether to retire
     * or rebuild these two tours is a product decision still open — listed here so
     * the gap is on the record, not silently skipped.
     */
    private const RETIRED_SCREENS = ['deals-create', 'deals-detail'];

    /**
     * Screens with nothing to act on — no button, link, box or picker — so the
     * guide can only walk their sections as "Got it" steps. The earnings
     * dashboard (commission/dashboard.blade.php) is a pure read-out.
     */
    private const READ_ONLY_SCREENS = ['earn-dashboard'];

    /** @return array<string, array<string, mixed>> tours that run on a page */
    private function routedTours(): array
    {
        return array_filter(TourRegistry::all(), fn ($t) => empty($t['external_url']));
    }

    public function test_every_page_tour_is_written_for_hands_on_guiding(): void
    {
        foreach ($this->routedTours() as $key => $tour) {
            if (in_array($key, self::RETIRED_SCREENS, true)) {
                continue;
            }
            $this->assertTrue(TourRegistry::supportsAdvanced($tour), "Tour '$key' has no hands-on (Advanced Guide) steps");
            $this->assertNotEmpty($tour['steps'][0]['section'] ?? null, "Tour '$key' step 0 must name its section");

            if (in_array($key, self::READ_ONLY_SCREENS, true)) {
                continue;
            }
            $doSteps = array_filter($tour['steps'], fn ($s) => ! empty($s['do']));
            $this->assertNotEmpty($doSteps, "Tour '$key' never asks the agent to DO anything");
        }
    }

    public function test_guide_fields_are_well_formed(): void
    {
        foreach ($this->routedTours() as $key => $tour) {
            foreach ($tour['steps'] as $i => $step) {
                $where = "Tour '$key' step $i";

                if (isset($step['section'])) {
                    $this->assertIsString($step['section'], "$where section must be a string");
                    $this->assertNotSame('', trim($step['section']), "$where section is blank");
                }
                if (isset($step['advanced_only'])) {
                    $this->assertIsBool($step['advanced_only'], "$where advanced_only must be a bool");
                }
                if (isset($step['skip_if'])) {
                    $this->assertIsString($step['skip_if'], "$where skip_if must be a CSS string");
                }
                foreach ($step['prep'] ?? [] as $prep) {
                    $this->assertContains($prep['action'] ?? null, self::PREP_ACTIONS, "$where has an unknown prep action");
                }

                if (! isset($step['do'])) {
                    continue;
                }
                $do = $step['do'];
                $this->assertContains($do['action'] ?? null, self::ACTIONS, "$where has an unknown do.action");
                if (($do['action'] ?? null) === 'appear') {
                    $this->assertNotEmpty($do['until'] ?? null, "$where: an appear step needs `until`");
                }
                if (isset($do['say'])) {
                    $this->assertIsString($do['say'], "$where do.say must be a string");
                    $this->assertNotSame('', trim($do['say']), "$where do.say is blank");
                }
            }
        }
    }

    public function test_every_guide_selector_exists_in_a_view(): void
    {
        $anchors = $this->viewAnchors();

        foreach ($this->routedTours() as $key => $tour) {
            foreach ($tour['steps'] as $i => $step) {
                $selectors = array_filter([
                    $step['element'] ?? null,
                    $step['skip_if'] ?? null,
                    $step['do']['until'] ?? null,
                    $step['do']['target'] ?? null,
                ]);
                foreach ($step['prep'] ?? [] as $prep) {
                    $selectors[] = $prep['selector'] ?? null;
                }

                foreach (array_filter($selectors) as $selector) {
                    preg_match_all('/data-tour="([^"]+)"/', $selector, $m);
                    foreach ($m[1] as $name) {
                        $this->assertTrue(
                            $this->anchorDeclared($name, $anchors),
                            "Tour '$key' step $i uses [data-tour=\"$name\"] but no view declares it"
                        );
                    }
                }
            }
        }
    }

    public function test_also_on_routes_exist(): void
    {
        foreach ($this->routedTours() as $key => $tour) {
            foreach ($tour['also_on'] ?? [] as $name) {
                $this->assertTrue(Route::has($name), "Tour '$key' also_on '$name' is not a route");
                $this->assertSame($key, TourRegistry::forRoute($name)['key'] ?? null, "Route '$name' resolves to another tour first");
            }
        }
    }

    /**
     * A tour's own `permission` must be one its page actually requires. When a
     * route is re-gated and the tour isn't (comp-seller-info kept the borrowed
     * whistleblow key after AT-161 moved the page to outreach.compose), people
     * who can open the page silently lose its guide.
     */
    public function test_tour_permission_matches_its_page_gate(): void
    {
        // Tours that cover ONE gated part of a page, so they need a narrower key
        // than the page itself: the Outreach tab on a contact (outreach.compose
        // inside a page gated by access_contacts).
        $narrower = ['outreach-composer'];
        // Page gates already reported as inconsistent and awaiting a decision —
        // the tour follows the sidebar's key until the route is settled:
        // Core Matches (route: access_contacts; sidebar + tour: access_core_matches),
        // see ContactMatchController::renderBoard().
        $pendingGateDecision = ['re-core-matches'];

        foreach ($this->routedTours() as $key => $tour) {
            if (empty($tour['permission']) || in_array($key, $narrower, true) || in_array($key, $pendingGateDecision, true)
                || in_array($key, self::RETIRED_SCREENS, true)) {
                continue;
            }
            $route = Route::getRoutes()->getByName($tour['route']);
            $gates = [];
            foreach ($route ? $route->gatherMiddleware() : [] as $m) {
                if (is_string($m) && str_starts_with($m, 'permission:')) {
                    $gates[] = strtok(substr($m, strlen('permission:')), ',');
                }
            }
            if ($gates === []) {
                continue; // gated in-controller — nothing to compare against
            }
            $this->assertContains($tour['permission'], $gates, "Tour '$key' permission '{$tour['permission']}' is not its page's gate (" . implode(', ', $gates) . ')');
        }
    }

    public function test_record_page_tours_declare_a_real_pick_list(): void
    {
        foreach ($this->routedTours() as $key => $tour) {
            if (! empty($tour['pick_from'])) {
                $this->assertTrue(Route::has($tour['pick_from']), "Tour '$key' pick_from '{$tour['pick_from']}' is not a route");
                $pick = Route::getRoutes()->getByName($tour['pick_from']);
                $this->assertEmpty($pick->parameterNames(), "Tour '$key' pick_from must be a plain list page");
                $this->assertNotEmpty($tour['pick_note'] ?? null, "Tour '$key' needs a pick_note to tell the agent what to open");
            }
        }
    }

    public function test_sections_inherit_forward_and_list_in_order(): void
    {
        $tour = $this->syntheticTour();

        $steps = TourRegistry::resolvedSteps($tour);
        $this->assertSame(['Basics', 'Basics', 'Adding spaces', 'Adding spaces'], array_column($steps, 'section'));
        $this->assertSame(['Basics', 'Adding spaces'], TourRegistry::sections($tour));
        $this->assertTrue(TourRegistry::supportsAdvanced($tour));
        $this->assertTrue(TourRegistry::supportsSpotHelp($tour));

        $single = $tour;
        $single['steps'] = [['element' => '#a', 'title' => 't', 'body' => 'b', 'section' => 'Only']];
        $this->assertFalse(TourRegistry::supportsSpotHelp($single), 'One section is not a choice — no Spot Help');

        $client = TourRegistry::forClient($tour);
        $this->assertSame(['Basics', 'Adding spaces'], $client['sections']);
        $this->assertTrue($client['advanced']);
        $this->assertTrue($client['spot']);
    }

    public function test_launch_targets_per_mode(): void
    {
        $tour = $this->syntheticTour(['route' => 'corex.contacts.index']);

        $this->assertSame('/corex/contacts?tour=synthetic', $this->pathOf(TourRegistry::launchTarget($tour, 'tour')));
        $this->assertSame('/corex/contacts?guide=synthetic&mode=advanced', $this->pathOf(TourRegistry::launchTarget($tour, 'advanced')));
        $this->assertSame(
            '/corex/contacts?guide=synthetic&mode=spot&section=Adding+spaces',
            $this->pathOf(TourRegistry::launchTarget($tour, 'spot', 'Adding spaces'))
        );
        $this->assertNull(TourRegistry::launchTarget($tour, 'spot', 'No such section'));

        // A record page goes via its pick list, flagged so the list parks the guide.
        $record = $this->syntheticTour(['route' => 'corex.properties.show', 'pick_from' => 'corex.properties.index']);
        $target = TourRegistry::launchTarget($record, 'spot', 'Adding spaces');
        $this->assertTrue($target['pick']);
        $this->assertStringStartsWith(route('corex.properties.index', [], false) . '?', $target['url']);
        $this->assertStringContainsString('pick=1', $target['url']);

        // …and without a pick list it simply can't be launched from outside.
        $orphan = $this->syntheticTour(['route' => 'corex.properties.show']);
        $this->assertNull(TourRegistry::launchTarget($orphan, 'advanced'));
    }

    public function test_best_section_needs_a_real_hit(): void
    {
        $svc = app(TourKnowledgeService::class);
        $tour = $this->syntheticTour();

        $this->assertSame('Adding spaces', $svc->bestSection('how do i add spaces to a property', $tour));
        $this->assertNull($svc->bestSection('how do i capture a listing', $tour), 'A whole-job question picks no section');
    }

    public function test_guide_buttons_are_permission_filtered(): void
    {
        $this->featureSwitch(true);
        $svc = app(TourKnowledgeService::class);

        $buttons = $svc->guideButtons('how do i capture a contact', $this->user(true));
        $advanced = array_values(array_filter($buttons, fn ($b) => $b['key'] === 'contact-capture' && $b['mode'] === 'advanced'));
        $this->assertCount(1, $advanced, 'A permitted agent gets the Advanced Guide button');
        $this->assertStringContainsString('guide=contact-capture', $advanced[0]['url']);

        // The outreach board is gated (tour permission + route permission).
        $denied = $svc->guideButtons('reading the outreach scoreboard board', $this->user(false));
        $this->assertEmpty(array_filter($denied, fn ($b) => $b['key'] === 'outreach-summary'));
    }

    public function test_guide_buttons_follow_the_guided_tours_switch(): void
    {
        $this->featureSwitch(false);

        $this->assertSame([], app(TourKnowledgeService::class)->guideButtons('how do i capture a contact', $this->user(true)));
    }

    public function test_ellie_find_how_to_collects_buttons_for_the_reply(): void
    {
        $this->featureSwitch(true);
        $toolkit = app(EllieToolkit::class);
        $toolkit->resetGuides();

        $raw = json_decode($toolkit->execute('find_how_to', ['query' => 'how do i capture a contact'], $this->user(true)), true);

        $this->assertNotEmpty($raw['guide_buttons'] ?? [], 'The model is told buttons will be shown');
        $keys = array_column($toolkit->guides(), 'key');
        $this->assertContains('contact-capture', $keys);

        $toolkit->resetGuides();
        $this->assertSame([], $toolkit->guides(), 'Buttons never leak into the next answer');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function syntheticTour(array $overrides = []): array
    {
        return array_merge([
            'key'   => 'synthetic',
            'title' => 'Synthetic',
            'route' => 'corex.contacts.index',
            'steps' => [
                ['element' => '#a', 'title' => 'Headline', 'body' => 'Type the headline.', 'section' => 'Basics', 'do' => ['action' => 'fill']],
                ['element' => '#b', 'title' => 'Price', 'body' => 'The asking price.'],
                ['element' => '#c', 'title' => 'Add a space', 'body' => 'Bedrooms, bathrooms and garages.', 'section' => 'Adding spaces', 'do' => ['action' => 'click']],
                ['element' => '#d', 'title' => 'Set the count', 'body' => 'How many of this room.'],
            ],
        ], $overrides);
    }

    private function pathOf(?array $target): ?string
    {
        return $target['url'] ?? null;
    }

    private function user(bool $grants, bool $isOwner = false): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasPermission')->andReturn($grants);
        $user->shouldReceive('isOwnerRole')->andReturn($isOwner);

        return $user;
    }

    private function featureSwitch(bool $on): void
    {
        $this->mock(AgencyFeatureService::class, fn ($m) => $m->shouldReceive('enabled')->andReturn($on));
    }

    /**
     * Every data-tour value declared in any Blade view. Values containing Blade
     * interpolation ({{ … }}) become patterns, so a looped anchor like
     * data-tour="prop-tab-{{ $tab['key'] }}" satisfies [data-tour="prop-tab-info"].
     *
     * @return array{exact: array<string,bool>, patterns: array<int,string>}
     */
    private function viewAnchors(): array
    {
        $exact = [];
        $patterns = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }
            preg_match_all('/data-tour="([^"]+)"/', file_get_contents((string) $file), $m);
            foreach ($m[1] as $value) {
                if (str_contains($value, '{{')) {
                    $parts = preg_split('/\{\{.*?\}\}/', $value);
                    $patterns[] = '/^' . implode('[A-Za-z0-9_-]+', array_map(fn ($p) => preg_quote($p, '/'), $parts)) . '$/';
                } else {
                    $exact[$value] = true;
                }
            }
        }

        return ['exact' => $exact, 'patterns' => array_values(array_unique($patterns))];
    }

    private function anchorDeclared(string $name, array $anchors): bool
    {
        if (isset($anchors['exact'][$name])) {
            return true;
        }
        foreach ($anchors['patterns'] as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
