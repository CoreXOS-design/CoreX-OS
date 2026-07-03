<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventClassSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-164 Gate 8 — mobile cockpit contract. The MobileCalendarController JSON envelope
 * is FROZEN: existing callers see a byte-identical response; the AT-164 surfaces
 * (Deck + layer filter sheet) appear ONLY behind ?include=..., never mutating an
 * existing field (§15.8).
 */
final class CalendarMobileContractTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

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
        CalendarEventClassSetting::create([
            'agency_id' => $this->agencyId, 'event_class' => 'viewing', 'is_active' => true,
            'event_nature' => 'actionable', 'occupies_time' => true,
            'green_days' => 30, 'amber_days' => 14, 'red_days' => 7,
            'green_visibility' => ['all'], 'amber_visibility' => ['all'], 'red_visibility' => ['all'],
            'green_notifications' => [], 'amber_notifications' => [], 'red_notifications' => [],
            'label' => 'Viewing',
        ]);
        CalendarEvent::create([
            'user_id' => $this->user->id, 'created_by_id' => $this->user->id,
            'event_type' => 'property', 'category' => 'viewing', 'title' => 'Viewing at 8 Marine Dr',
            'event_date' => now()->addDays(2)->setTime(10, 0), 'all_day' => false, 'status' => 'pending',
            'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]);
    }

    public function test_base_response_is_the_frozen_envelope(): void
    {
        Sanctum::actingAs($this->user);
        $resp = $this->getJson(route('v1.mobile.calendar.index', ['year' => now()->year, 'month' => now()->month]));
        $resp->assertOk();

        // The exact top-level key set — no deck / layers leak into the base contract.
        $this->assertSame(
            ['user_id', 'agency_id', 'range_start', 'range_end', 'total', 'events'],
            array_keys($resp->json())
        );
        // The frozen per-event field set (snake_case).
        $resp->assertJsonStructure(['events' => [[
            'id', 'title', 'description', 'event_type', 'category', 'event_date', 'end_date',
            'all_day', 'priority', 'status', 'colour', 'contact_id', 'property_id', 'created_by_ai', 'ai_source',
        ]]]);
    }

    public function test_include_adds_deck_and_layers_without_touching_events(): void
    {
        Sanctum::actingAs($this->user);
        $base = $this->getJson(route('v1.mobile.calendar.index', ['year' => now()->year, 'month' => now()->month]));
        $agg  = $this->getJson(route('v1.mobile.calendar.index', ['year' => now()->year, 'month' => now()->month, 'include' => 'deck,layers']));

        $agg->assertOk();
        $this->assertArrayHasKey('deck', $agg->json());
        $this->assertArrayHasKey('layers', $agg->json());
        $this->assertArrayHasKey('catalog', $agg->json('layers'));
        $this->assertArrayHasKey('active', $agg->json('layers'));

        // events[] is byte-identical — the redesign never mutates the frozen field.
        $this->assertSame($base->json('events'), $agg->json('events'));
    }
}
