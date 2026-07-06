<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarUserPreference;
use App\Models\User;
use App\Services\CommandCenter\CalendarTileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-164 cockpit — EXPLICIT-SAVE arrangement model (2026-07-06). Replaces the debounced
 * auto-persist: in-session changes are transient (client sessionStorage), and the per-user
 * DEFAULT is written ONLY by "Save as my default" (saveCockpit). Adds the per-user CALENDAR
 * SCROLLING preference (continuous stream vs classic paged) and the month-boundary tint.
 *
 * These tests assert the SERVER half of the model (the transient/reload distinction is
 * client-side JS, proven by the headless harness). Covered:
 *   • Save promotes the whole current arrangement to the saved default (one write path).
 *   • A ?view= / ?scroll= param renders that shell but NEVER auto-persists (the old bug).
 *   • No-param load renders the SAVED default (view + scroll mode + panel-collapse).
 *   • scroll_mode defaults to continuous; paged shells render (month + week) with paging.
 *   • Reset endpoint (factory reset) nulls the saved default.
 *   • The single-tile build endpoint renders one card without persisting.
 *   • Continuous month carries the alternating month-tint classes; paged does not tint.
 */
final class CalendarExplicitSaveTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC ' . Str::random(6), 'slug' => 'hfc-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);

        $this->classSetting('viewing', true);
        $this->classSetting('portal_listing_expiry', false);
    }

    private function classSetting(string $class, bool $occupiesTime): void
    {
        CalendarEventClassSetting::create([
            'agency_id' => $this->agencyId, 'event_class' => $class, 'is_active' => true,
            'event_nature' => 'actionable', 'occupies_time' => $occupiesTime,
            'green_days' => 30, 'amber_days' => 14, 'red_days' => 7,
            'green_visibility' => ['all'], 'amber_visibility' => ['all'], 'red_visibility' => ['all'],
            'green_notifications' => [], 'amber_notifications' => [], 'red_notifications' => [],
            'label' => Str::headline($class),
        ]);
    }

    private function pref(): ?CalendarUserPreference
    {
        return CalendarUserPreference::where('user_id', $this->user->id)->first();
    }

    // ── Item 1: explicit-save default model ──

    public function test_save_promotes_the_whole_current_arrangement_to_the_saved_default(): void
    {
        $realTiles = array_slice(array_column(app(CalendarTileService::class)->catalog($this->user), 'tile_id'), 0, 2);

        $resp = $this->actingAs($this->user)->postJson(route('command-center.calendar.cockpit.save'), [
            'panel_collapsed' => true,
            'strip_collapsed' => true,
            'strip_height'    => 240,
            'tile_ratios'     => [1.5, 0.8],
            'deck_layout'     => $realTiles,
            'layers'          => ['appointments', 'deal'],
            'scroll_mode'     => 'paged',
            'view'            => 'week',
        ]);
        $resp->assertOk()->assertJson(['ok' => true]);

        $pref = $this->pref();
        $this->assertNotNull($pref, 'the save creates the preference row');
        $this->assertTrue($pref->calendar_cockpit['panel_collapsed'], 'panel-collapse promoted');
        $this->assertTrue($pref->calendar_cockpit['strip_collapsed'], 'strip-collapse promoted');
        $this->assertSame(240, $pref->calendar_cockpit['strip_height'], 'strip height promoted');
        $this->assertSame('paged', $pref->calendar_cockpit['scroll_mode'], 'scroll mode promoted');
        $this->assertSame('week', $pref->default_view, 'view promoted');
        $this->assertSame($realTiles, $pref->calendar_deck_layout, 'deck layout promoted');
        $this->assertSame(['appointments', 'deal'], $pref->calendar_layers, 'layers promoted');
    }

    public function test_save_drops_unknown_tiles_and_layers_but_keeps_valid_ones(): void
    {
        $real = array_column(app(CalendarTileService::class)->catalog($this->user), 'tile_id')[0];

        $this->actingAs($this->user)->postJson(route('command-center.calendar.cockpit.save'), [
            'deck_layout' => [$real, 'totally_bogus_tile'],
            'layers'      => ['appointments', 'not_a_layer'],
        ])->assertOk();

        $pref = $this->pref();
        $this->assertSame([$real], $pref->calendar_deck_layout, 'unknown tile dropped, valid kept');
        $this->assertSame(['appointments'], $pref->calendar_layers, 'unknown layer dropped, valid kept');
    }

    public function test_no_view_param_renders_the_saved_default_view(): void
    {
        CalendarUserPreference::create(['user_id' => $this->user->id, 'default_view' => 'week']);

        $resp = $this->actingAs($this->user)->get(route('command-center.calendar'));
        $resp->assertOk();
        // Week shell markup is present; the month week-stream container is not. (The JS
        // function definitions live in the shared <script> on every view — assert on the
        // rendered x-data/x-ref MARKUP, which is view-specific.)
        $resp->assertSee('x-ref="weekScroller"', false);   // continuous-week scroller
        $resp->assertDontSee('x-ref="weeks"', false);       // month week-stream container
    }

    public function test_a_view_param_renders_that_view_but_never_auto_persists_it(): void
    {
        // Saved default is month (no pref row yet → factory default).
        $this->assertNull($this->pref());

        // Explicitly request day — the OLD model would have written default_view=day here.
        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', ['view' => 'day']));
        $resp->assertOk();

        $pref = $this->pref();
        // The regression proof: requesting a view must NOT create/mutate the saved default.
        $this->assertTrue(
            $pref === null || $pref->default_view === 'month',
            'a ?view= request must never auto-persist the default (kills the debounced auto-save class)'
        );
    }

    public function test_reset_endpoint_nulls_the_saved_default(): void
    {
        CalendarUserPreference::create([
            'user_id' => $this->user->id, 'default_view' => 'week',
            'calendar_cockpit' => ['panel_collapsed' => true, 'scroll_mode' => 'paged'],
            'calendar_deck_layout' => ['cal_upcoming'], 'calendar_layers' => ['appointments'],
        ]);

        $this->actingAs($this->user)->postJson(route('command-center.calendar.cockpit.reset'))
            ->assertOk()->assertJson(['ok' => true, 'reload' => true]);

        $pref = $this->pref();
        $this->assertNull($pref->calendar_cockpit, 'arrangement reset to factory');
        $this->assertNull($pref->calendar_deck_layout, 'deck reset to role default');
        $this->assertNull($pref->calendar_layers, 'layers reset to agency default');
        $this->assertSame('month', $pref->default_view, 'view reset to default');
    }

    // ── Item 2: scroll mode preference + tints ──

    public function test_scroll_mode_defaults_to_continuous(): void
    {
        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', ['view' => 'month']));
        $resp->assertOk();
        $resp->assertSee('x-ref="weeks"', false);               // continuous month week-stream container
        $resp->assertSee('cal-scroll-continuous', false);       // tint-scoping container (continuous only)
        $resp->assertSee('cal-scrollmode', false);              // the Stream/Pages toggle
    }

    public function test_scroll_param_renders_paged_without_persisting(): void
    {
        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', ['view' => 'month', 'scroll' => 'paged']));
        $resp->assertOk();
        $resp->assertSee('Previous month', false);              // paged nav
        $resp->assertSee('cal-scroll-paged', false);            // paged container
        $resp->assertDontSee('x-ref="weeks"', false);           // NOT the continuous month stream

        // Transient only — a ?scroll= request must never write the saved default.
        $pref = $this->pref();
        $this->assertTrue(
            $pref === null || ! isset($pref->calendar_cockpit['scroll_mode']),
            'a ?scroll= request must never auto-persist the scroll mode'
        );
    }

    public function test_saved_scroll_mode_renders_paged_on_reload(): void
    {
        CalendarUserPreference::create([
            'user_id' => $this->user->id, 'default_view' => 'month',
            'calendar_cockpit' => ['scroll_mode' => 'paged'],
        ]);

        // No ?scroll= param → the saved default drives the shell.
        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', ['view' => 'month']));
        $resp->assertOk();
        $resp->assertSee('Previous month', false);
        $resp->assertDontSee('x-ref="weeks"', false);
    }

    public function test_paged_week_renders_with_paging_nav(): void
    {
        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', ['view' => 'week', 'scroll' => 'paged']));
        $resp->assertOk();
        $resp->assertSee('Previous week', false);
        $resp->assertDontSee('x-ref="weekScroller"', false);   // NOT the continuous-week scroller
    }

    public function test_continuous_month_carries_alternating_tint_classes(): void
    {
        $resp = $this->actingAs($this->user)->get(route('command-center.calendar', ['view' => 'month', 'scroll' => 'continuous']));
        $resp->assertOk();
        $resp->assertSee('cal-scroll-continuous', false);       // the tint-scoping container
        $resp->assertSee('--cal-month-tint-alpha', false);      // one-place tunable strength
        $resp->assertSee('cal-month-tint-0', false);            // both parities render
        $resp->assertSee('cal-month-tint-1', false);
    }

    // ── Item 1 support: non-persisting single-tile build ──

    public function test_tile_endpoint_builds_one_card_without_persisting(): void
    {
        $real = array_column(app(CalendarTileService::class)->catalog($this->user), 'tile_id')[0];

        $resp = $this->actingAs($this->user)->getJson(route('command-center.calendar.tile', ['tileId' => $real]));
        $resp->assertOk()->assertJson(['ok' => true]);
        $this->assertSame($real, $resp->json('card.card_id'));

        // Building a tile must NEVER touch the saved deck layout.
        $this->assertNull($this->pref(), 'the tile endpoint does not persist anything');
    }

    public function test_tile_endpoint_404s_an_unknown_tile(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('command-center.calendar.tile', ['tileId' => 'nonexistent_tile']))
            ->assertNotFound();
    }
}
