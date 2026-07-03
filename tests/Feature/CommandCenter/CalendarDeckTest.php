<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\CommandCenter\CalendarUserPreference;
use App\Models\User;
use App\Services\CommandCenter\CalendarTileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-164 Gate 4 — the Tile Deck. Per-user Deck of tiles below the grid: default
 * layout, save/reset persistence (cross-device), the My Deals capability gate
 * (FLAGGED HIDDEN behind the DR2 hold), RAG-ranked deadlines, agency-configurable
 * slot count, and degrade-not-500 robustness (§15.4/§15.5/§15.8/§15.11).
 */
final class CalendarDeckTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $owner;

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
        $this->owner = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);

        $this->classSetting('viewing', true);                 // appointment species
        $this->classSetting('portal_listing_expiry', false);  // deadline species
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

    private function event(string $type, string $category, Carbon $date, bool $allDay, array $extra = []): CalendarEvent
    {
        return CalendarEvent::create(array_merge([
            'user_id' => $this->owner->id, 'created_by_id' => $this->owner->id,
            'event_type' => $type, 'category' => $category, 'title' => Str::headline($category),
            'event_date' => $date, 'all_day' => $allDay, 'status' => 'pending',
            'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ], $extra));
    }

    private function svc(): CalendarTileService
    {
        return app(CalendarTileService::class);
    }

    // ── Deck defaults & building ──

    public function test_default_deck_is_the_three_launch_tiles(): void
    {
        $deck = $this->svc()->buildDeck($this->owner);
        $ids = array_column($deck, 'card_id');

        $this->assertSame(
            [CalendarTileService::TILE_UPCOMING, CalendarTileService::TILE_DEADLINES, CalendarTileService::TILE_TODOS],
            $ids,
            'a fresh user gets the three launch tiles in order'
        );
        // Every tile conforms to the contract shape.
        foreach ($deck as $card) {
            $this->assertArrayHasKey('title', $card);
            $this->assertArrayHasKey('items', $card);
            $this->assertArrayHasKey('count', $card);
        }
    }

    public function test_my_deals_tile_is_absent_for_a_non_owner_role(): void
    {
        $agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true,
        ]);
        // Seed permissions so the gate is real (not the "unseeded → allow" fallback).
        DB::table('role_permissions')->insert([
            'role' => 'agent', 'permission_key' => 'command_center.calendar.view', 'scope' => 'own',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Reset the static permission cache — $seeded may be poisoned to a stale
        // "unseeded" value by a prior test in the same process (RefreshDatabase
        // rolls back rows but not PHP statics).
        \App\Services\PermissionService::clearCache();

        $svc = $this->svc();
        $this->assertFalse($svc->canSeeMyDeals($agent), 'default OFF for non-owner');
        $this->assertNotContains(
            CalendarTileService::TILE_MY_DEALS,
            array_column($svc->catalog($agent), 'tile_id'),
            'My Deals is not pickable until the capability is granted'
        );
        $this->assertNull($svc->buildTile($agent, CalendarTileService::TILE_MY_DEALS), 'builder refuses to build it');
    }

    public function test_deadlines_tile_ranks_worst_rag_first_with_a_worst_accent(): void
    {
        // amber (~10 days out) + red (~2 days out) deadlines.
        $amberDay = now()->addDays(10)->startOfDay();
        $redDay   = now()->addDays(2)->startOfDay();
        $this->event('property', 'portal_listing_expiry', $amberDay, true);
        $this->event('property', 'portal_listing_expiry', $redDay, true);

        $card = $this->svc()->buildTile($this->owner, CalendarTileService::TILE_DEADLINES);

        $this->assertSame(2, $card['count']);
        $this->assertSame('red', $card['rag'], 'card accent is the worst RAG in the tile');
        $this->assertSame('red', $card['items'][0]['rag'], 'worst item ranks first');
    }

    // ── Persistence (cross-device) ──

    public function test_save_and_reset_layout_via_http(): void
    {
        $saveResp = $this->actingAs($this->owner)->postJson(
            route('command-center.calendar.deck.save'),
            ['tiles' => [CalendarTileService::TILE_TODOS, CalendarTileService::TILE_UPCOMING]]
        );
        $saveResp->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(
            [CalendarTileService::TILE_TODOS, CalendarTileService::TILE_UPCOMING],
            $saveResp->json('layout')
        );

        // Persisted to the per-user row (survives across devices).
        $pref = CalendarUserPreference::where('user_id', $this->owner->id)->first();
        $this->assertSame(
            [CalendarTileService::TILE_TODOS, CalendarTileService::TILE_UPCOMING],
            $pref->calendar_deck_layout
        );

        // Reset restores the default.
        $resetResp = $this->actingAs($this->owner)->postJson(route('command-center.calendar.deck.reset'));
        $resetResp->assertOk();
        $this->assertSame(
            [CalendarTileService::TILE_UPCOMING, CalendarTileService::TILE_DEADLINES, CalendarTileService::TILE_TODOS],
            $resetResp->json('layout')
        );
    }

    public function test_deck_endpoint_returns_the_full_shape(): void
    {
        $resp = $this->actingAs($this->owner)->getJson(route('command-center.calendar.deck'));
        $resp->assertOk()
            ->assertJsonStructure(['cards', 'catalog', 'layout', 'slots']);
        $this->assertSame(4, $resp->json('slots'), 'default slot count');
    }

    public function test_unknown_or_excess_tiles_are_dropped_server_side(): void
    {
        $resp = $this->actingAs($this->owner)->postJson(
            route('command-center.calendar.deck.save'),
            ['tiles' => ['not_a_real_tile', CalendarTileService::TILE_UPCOMING, CalendarTileService::TILE_UPCOMING]]
        );
        $resp->assertOk();
        // Bogus id stripped; the duplicate de-duped.
        $this->assertSame([CalendarTileService::TILE_UPCOMING], $resp->json('layout'));
    }

    // ── Agency configuration ──

    public function test_slot_count_is_agency_configurable_and_clamps_the_layout(): void
    {
        DB::table('agency_contact_settings')->updateOrInsert(
            ['agency_id' => $this->agencyId],
            ['calendar_deck_slots' => 2, 'updated_at' => now(), 'created_at' => now()]
        );

        $svc = $this->svc();
        $this->assertSame(2, $svc->slotCount($this->owner));

        // Saving 3 tiles clamps to 2.
        $saved = $svc->saveLayout($this->owner, [
            CalendarTileService::TILE_UPCOMING,
            CalendarTileService::TILE_DEADLINES,
            CalendarTileService::TILE_TODOS,
        ]);
        $this->assertCount(2, $saved);
    }
}
