<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 (lane 3) - the outcome settings screen over HTTP. Spec section 10.4.
 * Paths proven: list + seed; search / status filter / sort; add (all fields; the lazy minimum);
 * each field malformed; edit; switch off; move up/down; archive then restore; create-after-archive
 * restores; another agency's row = 404 by direct URL; no manage key = 403; empty states.
 */
final class OutcomeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        OutcomeDefinitionService::forgetMemo();
        $this->agencyId = $this->agency();
        $this->admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin', 'is_active' => true]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'road_to_sold.manage_settings', 'agency_id' => $this->agencyId]);
        PermissionService::clearCache();
    }

    private function agency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId(['name' => 'T ' . Str::random(5), 'slug' => 't-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function def(string $key): Def
    {
        return Def::withoutGlobalScopes()->withTrashed()->where('agency_id', $this->agencyId)->where('key', $key)->firstOrFail();
    }

    public function test_the_list_shows_the_seeded_eight_and_filters_search_and_sort(): void
    {
        $r = $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index'));
        $r->assertOk()->assertSee('No answer')->assertSee('Spoke - evaluation booked')->assertSee('Do not contact')->assertSee('Wrong number');
        $r->assertSee('Comes back after 7 days')->assertSee('Moves to the list');

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index', ['q' => 'wrong']))
            ->assertSee('Wrong number')->assertDontSee('Do not contact');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index', ['q' => 'zzz']))
            ->assertSee('No outcomes match this search.');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index', ['status' => 'archived']))
            ->assertSee('No outcomes match this search.');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index', ['sort' => 'name', 'status' => 'bogus']))->assertOk();
    }

    public function test_add_with_everything_and_with_the_lazy_minimum(): void
    {
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.outcomes.store'), [
            'label' => 'Phone is off', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => '2', 'counts_as_no_answer' => '1',
        ])->assertRedirect(route('road-to-sold.settings.outcomes.index'));
        $d = $this->def('phone_is_off');
        $this->assertTrue($d->counts_as_no_answer);
        $this->assertSame(2, $d->delay_days);

        // minimum legal input: a name, a kind, "never again"
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.outcomes.store'), [
            'label' => 'Deceased', 'kind' => 'conversation', 'next_action' => 'never_again',
        ])->assertRedirect(route('road-to-sold.settings.outcomes.index'));
        $this->assertFalse($this->def('deceased')->records_do_not_contact);
    }

    public function test_each_malformed_field_is_refused_with_a_message(): void
    {
        $post = fn (array $d) => $this->actingAs($this->admin)->from(route('road-to-sold.settings.outcomes.index'))
            ->post(route('road-to-sold.settings.outcomes.store'), $d);

        $post(['label' => '', 'kind' => 'attempt', 'next_action' => 'never_again'])->assertSessionHasErrorsIn('outcome', 'label');
        $post(['label' => 'X', 'kind' => 'huh', 'next_action' => 'never_again'])->assertSessionHasErrorsIn('outcome', 'kind');
        $post(['label' => 'X', 'kind' => 'attempt', 'next_action' => 'warp'])->assertSessionHasErrorsIn('outcome', 'next_action');
        $post(['label' => 'X', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => '-3'])->assertSessionHasErrorsIn('outcome', 'delay_days');
        $post(['label' => 'X', 'kind' => 'attempt', 'next_action' => 'move_to_list', 'list_tag' => ''])->assertSessionHasErrorsIn('outcome', 'list_tag');
        $this->assertSame(8, Def::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count());
    }

    public function test_edit_switch_off_move_archive_restore_and_recreate(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index'));   // seeds
        $na = $this->def('no_answer');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.outcomes.update', $na->id), [
            'label' => 'Not reachable', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => '5', 'counts_as_no_answer' => '1',
        ])->assertRedirect();
        $this->assertSame('Not reachable', $na->fresh()->label);
        $this->assertSame('no_answer', $na->fresh()->key);

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.outcomes.toggle', $na->id));
        $this->assertFalse($na->fresh()->is_active);

        $second = $this->def('spoke_evaluation_booked');
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.outcomes.move', $second->id), ['dir' => 'up']);
        $order = Def::withoutGlobalScopes()->where('agency_id', $this->agencyId)->orderBy('sort_order')->pluck('key')->all();
        $this->assertSame('spoke_evaluation_booked', $order[0]);

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.outcomes.archive', $na->id));
        $this->assertNotNull($this->def('no_answer')->deleted_at);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index', ['status' => 'archived']))->assertSee('Not reachable')->assertSee('Restore');

        $this->actingAs($this->admin)->post(route('road-to-sold.settings.outcomes.restore', $na->id));
        $this->assertNull($this->def('no_answer')->deleted_at);

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.outcomes.archive', $na->id));
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.outcomes.store'), [
            'label' => 'No answer', 'kind' => 'attempt', 'next_action' => 'return_after_days', 'delay_days' => '9',
        ])->assertRedirect(route('road-to-sold.settings.outcomes.index'));
        $this->assertSame(1, Def::withoutGlobalScopes()->withTrashed()->where('agency_id', $this->agencyId)->where('key', 'no_answer')->count());
        $this->assertSame(9, $this->def('no_answer')->delay_days);
    }

    public function test_the_outcome_list_belongs_to_the_track_in_the_request(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index', ['track' => 'rentals']))->assertOk();
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.outcomes.store'), [
            'track' => 'rentals', 'label' => 'Tenant leaving soon', 'kind' => 'conversation', 'next_action' => 'return_on_picked_date',
        ])->assertRedirect();

        $this->assertSame('rentals', Def::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('label', 'Tenant leaving soon')->value('track'));
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index', ['track' => 'rentals']))->assertSee('Tenant leaving soon');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index'))->assertDontSee('Tenant leaving soon')->assertSee('No answer');
    }

    public function test_another_agencys_outcome_is_a_404_and_no_key_is_a_403(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.outcomes.index'));
        $bAgency = $this->agency();
        $bAdmin = User::factory()->create(['agency_id' => $bAgency, 'branch_id' => $bAgency, 'role' => 'admin', 'is_active' => true]);
        foreach (['access_road_to_sold', 'road_to_sold.manage_settings'] as $k) {
            RolePermission::create(['role' => 'admin', 'permission_key' => $k, 'agency_id' => $bAgency]);
        }
        PermissionService::clearCache();

        $mine = $this->def('no_answer');
        $this->actingAs($bAdmin)->put(route('road-to-sold.settings.outcomes.update', $mine->id), ['label' => 'Hacked', 'kind' => 'attempt', 'next_action' => 'never_again'])->assertNotFound();
        $this->actingAs($bAdmin)->delete(route('road-to-sold.settings.outcomes.archive', $mine->id))->assertNotFound();
        $this->actingAs($bAdmin)->post(route('road-to-sold.settings.outcomes.restore', $mine->id))->assertNotFound();
        $this->assertSame('No answer', $mine->fresh()->label);

        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agencyId]);
        PermissionService::clearCache();
        $this->actingAs($agent)->get(route('road-to-sold.settings.outcomes.index'))->assertForbidden();
        $this->actingAs($agent)->post(route('road-to-sold.settings.outcomes.store'), ['label' => 'X', 'kind' => 'attempt', 'next_action' => 'never_again'])->assertForbidden();
    }
}
