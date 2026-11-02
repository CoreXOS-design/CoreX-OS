<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RolePermission;
use App\Services\PermissionService;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\TargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** "Add to my day" always answers, beside the button: what was added, or why nothing was (the day is full / nothing more from that selection). */
final class AddToDayReplyTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        Carbon::setTestNow('2026-10-12 08:00:00');   // a Monday
        $this->setUpAgency();
        config(['road-to-sold-belt.enforce_owner_gate' => false, 'road-to-sold-belt.require_channel' => false]);
        foreach (['access_road_to_sold', 'road_to_sold.view'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => $key === 'road_to_sold.view' ? 'own' : null]);
        }
        PermissionService::clearCache();
        app(TargetService::class)->saveWeek($this->agent, [1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2], $this->agent->id);
        \App\Models\RoadToSold\RtsSetting::updateOrCreate(['agency_id' => $this->agency->id, 'track' => 'sales'], ['selections' => [['key' => 'long_on_market', 'enabled' => true]]]);
        SettingsService::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function long(string $street): int
    {
        $tp = $this->tp(['street_number' => '1', 'street_name' => $street, 'suburb' => 'Margate']);

        return $this->listing(['first_seen_at' => now()->subDays(200), 'listed_date' => now()->subDays(200)->toDateString()], $tp);
    }

    private function add(int $count, bool $json = true)
    {
        $req = $json ? $this->actingAs($this->agent)->postJson(route('road-to-sold.add-to-day'), ['selection' => 'long_on_market', 'count' => $count])
            : $this->actingAs($this->agent)->post(route('road-to-sold.add-to-day'), ['selection' => 'long_on_market', 'count' => $count]);

        return $req;
    }

    public function test_it_says_what_was_added(): void
    {
        $this->long('Alpha Road'); $this->long('Beta Road'); $this->long('Gamma Road'); $this->long('Delta Road'); $this->long('Epsilon Road');
        app(ListBuilderService::class)->buildFor($this->agent, now());

        $one = $this->add(1)->assertOk()->json();
        $this->assertTrue($one['ok']);
        $this->assertSame(1, $one['added']);
        $this->assertMatchesRegularExpression('/^Added: .*Road/', $one['message']);

        $two = $this->add(2)->assertOk()->json();
        $this->assertSame(2, $two['added']);
        $this->assertMatchesRegularExpression('/^Added: .+, .+$/', $two['message']);
    }

    public function test_when_nothing_is_left_and_the_day_is_full_it_says_so_with_the_numbers(): void
    {
        $this->long('Alpha Road'); $this->long('Beta Road');
        app(ListBuilderService::class)->buildFor($this->agent, now());   // the target of 2 takes both

        $r = $this->add(3)->assertOk()->json();

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['added']);
        $this->assertSame('Your day is full (2 of 2). Nothing more is available from Long on market right now.', $r['message']);
    }

    public function test_when_the_day_is_not_full_it_only_says_nothing_more_is_available(): void
    {
        $this->long('Alpha Road');
        app(ListBuilderService::class)->buildFor($this->agent, now());   // one property for a target of two

        $r = $this->add(3)->assertOk()->json();

        $this->assertSame(0, $r['added']);
        $this->assertSame('Nothing more is available from Long on market right now.', $r['message']);
    }

    public function test_a_plain_post_still_answers_with_a_note_on_the_reloaded_page_and_the_page_carries_the_reply_slot(): void
    {
        $this->long('Alpha Road'); $this->long('Beta Road'); $this->long('Gamma Road');
        app(ListBuilderService::class)->buildFor($this->agent, now());

        $this->add(1, false)->assertSessionHas('status', fn ($m) => str_starts_with($m, 'Added: '));

        $html = $this->actingAs($this->agent)->get(route('road-to-sold.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="add-to-day-reply"', $html);
    }

    public function test_a_bad_count_is_answered_in_place_too(): void
    {
        $this->actingAs($this->agent)->postJson(route('road-to-sold.add-to-day'), ['selection' => 'long_on_market', 'count' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('count');
    }
}
