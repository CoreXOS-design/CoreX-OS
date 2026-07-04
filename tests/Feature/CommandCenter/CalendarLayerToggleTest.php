<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarUserPreference;
use App\Models\User;
use App\Services\CommandCenter\Calendar\CalendarLayers;
use App\Services\CommandCenter\CalendarTileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-164 Gate 6 — layer toggles. Layers derive from event_type; Personal off by
 * default; a toggle hides a species on the grid AND filters the Notifications tile;
 * the choice persists per-user (§15.6).
 */
final class CalendarLayerToggleTest extends TestCase
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

    private function event(string $type, string $category, Carbon $date, bool $allDay): CalendarEvent
    {
        return CalendarEvent::create([
            'user_id' => $this->user->id, 'created_by_id' => $this->user->id,
            'event_type' => $type, 'category' => $category, 'title' => Str::headline($category),
            'event_date' => $date, 'all_day' => $allDay, 'status' => 'pending',
            'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]);
    }

    public function test_defaults_exclude_personal_and_recurring(): void
    {
        $active = CalendarLayers::resolveActive($this->user);
        $this->assertContains('appointments', $active);
        $this->assertContains('property', $active);
        $this->assertNotContains('personal', $active, 'Personal is off by default');
        $this->assertNotContains('recurring', $active, 'Recurring is off by default');
    }

    public function test_layer_classification(): void
    {
        $this->assertSame('property', CalendarLayers::layerForType('property'));
        $this->assertSame('lease', CalendarLayers::layerForType('lease'));
        // Unknown type falls back to appointments (never silently dropped).
        $this->assertSame('appointments', CalendarLayers::layerForType('mystery'));
    }

    public function test_save_layers_endpoint_sanitises_and_persists(): void
    {
        $resp = $this->actingAs($this->user)->postJson(route('command-center.calendar.layers.save'), [
            'layers' => ['appointments', 'deal', 'not_a_layer'],
        ]);
        $resp->assertOk()->assertJson(['ok' => true, 'layers' => ['appointments', 'deal']]);

        $pref = CalendarUserPreference::where('user_id', $this->user->id)->first();
        $this->assertSame(['appointments', 'deal'], $pref->calendar_layers);
    }

    public function test_deck_tiles_never_respect_layer_toggles(): void
    {
        // AT-164 doctrine (Johan): DECK TILES are independent instruments — their content
        // contracts are their own and NEVER a projection of the calendar's layer lens.
        // Toggling a layer off must NOT change any tile's count. (Previously the
        // Notifications tile server-filtered by layer, which emptied it when the user hid
        // layers on the grid — the reported defect.)
        $this->event('property', 'portal_listing_expiry', now()->addDays(3)->startOfDay(), true);

        $svc = app(CalendarTileService::class);

        CalendarLayers::save($this->user, ['property']);
        $withLayerOn = $svc->buildTile($this->user->fresh(), CalendarTileService::TILE_DEADLINES)['count'];
        $this->assertSame(1, $withLayerOn, 'deadline present in the tile');

        // Hide the Listings layer entirely — the deck tile is unaffected.
        CalendarLayers::save($this->user, ['deal']);
        $withLayerOff = $svc->buildTile($this->user->fresh(), CalendarTileService::TILE_DEADLINES)['count'];
        $this->assertSame(1, $withLayerOff, 'deck tile count unchanged when a layer is hidden');

        // Same for Upcoming Events (was gated behind the Appointments layer).
        $this->event('viewing', 'viewing', now()->addDays(2)->setTime(9, 0), false);
        CalendarLayers::save($this->user, []); // ALL layers off
        $upcoming = $svc->buildTile($this->user->fresh(), CalendarTileService::TILE_UPCOMING)['count'];
        $this->assertGreaterThanOrEqual(1, $upcoming, 'Upcoming Events shows appointments even with all layers off');
    }

    public function test_grid_and_panel_agenda_carry_the_layer_for_client_side_hiding(): void
    {
        // The CALENDAR surfaces (grid week rows + panel agenda) respect layers the SAME
        // client-side way — every chip/item carries data-layer / a layer key, and the
        // client hides it via cal-layerable. Nothing is server-filtered by layer (so a
        // layer toggled back ON always reveals its items).
        // A clearly-future event so it falls inside the panel-agenda window (today..+30)
        // regardless of which weekday the test runs on.
        $future = now()->addDays(3)->setTime(10, 0);
        $this->event('viewing', 'viewing', $future, false);

        // Grid (week rows) tags every chip.
        $resp = $this->actingAs($this->user)->get(route('command-center.calendar.week-rows', [
            'start' => $future->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->toDateString(), 'count' => 2,
        ]));
        $resp->assertOk();
        $resp->assertSee('cal-layerable', false);
        $resp->assertSee('data-layer=', false);

        // Panel agenda items carry a 'layer' and are NOT server-filtered by the active set.
        CalendarLayers::save($this->user, []); // hide everything
        $agenda = app(CalendarTileService::class)->panelAgenda($this->user->fresh());
        $this->assertNotEmpty($agenda, 'panel agenda still returns items with all layers off (client hides them)');
        $this->assertArrayHasKey('layer', $agenda[0], 'each agenda item carries its layer');
    }
}
