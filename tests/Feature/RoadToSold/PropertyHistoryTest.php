<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Prospecting\TrackedProperty;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\PropertyHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 - Road to Sold call history on the property page and the tracked-property (MIC) page.
 * Spec: .ai/specs/road-to-sold.md section 10.9.
 *
 * Proven: who/when/outcome/note/next date come back newest first; a property with no owner contact still
 * shows its calls; an agent sees their own calls, a branch manager the branch, an admin the agency;
 * another agency's calls never show; the property page also shows calls logged on the tracked property
 * it was promoted from; paged at 10; the panel renders the real empty state, and nothing at all for a user
 * without the Road to Sold key.
 */
final class PropertyHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branch2;
    private User $anna;
    private User $bheki;
    private User $carl;
    private User $manager;
    private User $admin;
    private TrackedProperty $tp;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');

        $this->agencyId = $this->agency();
        $this->branch2 = $this->branchIn($this->agencyId, $this->agencyId + 5000);
        $this->branchIn($this->agencyId, $this->agencyId);
        $mk = fn (string $name, string $role, int $branch) => User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $branch, 'role' => $role, 'is_active' => true, 'name' => $name,
        ]);
        $this->anna = $mk('Anna Agent', 'agent', $this->agencyId);
        $this->bheki = $mk('Bheki Agent', 'agent', $this->agencyId);
        $this->carl = $mk('Carl Elsewhere', 'agent', $this->branch2);
        $this->manager = $mk('Mia Manager', 'branch_manager', $this->agencyId);
        $this->admin = $mk('Ada Admin', 'admin', $this->agencyId);

        foreach (['agent' => 'own', 'branch_manager' => 'branch', 'admin' => 'all'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agencyId]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agencyId, 'scope' => $scope]);
        }
        foreach (['access_prospecting', 'access_properties', 'properties.view'] as $key) {
            RolePermission::create(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agencyId, 'scope' => 'all']);
        }
        // a role with some grants but not the Road to Sold key (an empty grants table permits everything)
        RolePermission::create(['role' => 'viewer', 'permission_key' => 'access_contacts', 'agency_id' => $this->agencyId]);
        PermissionService::clearCache();

        // no owner contact on purpose: history must not depend on one
        $this->tp = TrackedProperty::create(['agency_id' => $this->agencyId, 'street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo', 'owner_contact_id' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function agency(): int
    {
        return (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function branchIn(int $agencyId, int $id): int
    {
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $agencyId, 'name' => 'Branch ' . $id, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** One call-log row, written straight to the table so the fixture is independent of the capture rules. */
    private function logCall(User $by, int $trackedId, string $label, string $when, array $over = []): int
    {
        return (int) DB::table('rts_contact_outcomes')->insertGetId($over + [
            'agency_id' => $by->agency_id, 'branch_id' => $by->branch_id, 'track' => 'sales', 'user_id' => $by->id,
            'tracked_property_id' => $trackedId, 'outcome_key' => Str::slug($label, '_'), 'outcome_label' => $label,
            'kind' => 'attempt', 'next_action' => 'none', 'occurred_at' => $when, 'is_current' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function svc(): PropertyHistoryService
    {
        return app(PropertyHistoryService::class);
    }

    private function labels($page): array
    {
        return collect($page->items())->pluck('outcome_label')->all();
    }

    public function test_history_is_newest_first_with_who_note_and_next_date_and_needs_no_owner_contact(): void
    {
        $this->logCall($this->anna, $this->tp->id, 'No answer', '2026-10-10 10:00:00');
        $this->logCall($this->anna, $this->tp->id, 'Spoke - call back', '2026-10-13 15:30:00', [
            'kind' => 'conversation', 'note' => 'Wants to wait for December', 'next_action' => 'picked_date', 'next_action_at' => '2026-12-01',
        ]);

        $this->actingAs($this->anna);
        $page = $this->svc()->forTrackedProperty($this->anna, $this->tp->id);

        $this->assertSame(['Spoke - call back', 'No answer'], $this->labels($page));
        $first = $page->items()[0];
        $this->assertSame('Anna Agent', $first->agent->name);
        $this->assertSame('Wants to wait for December', $first->note);
        $this->assertSame('2026-12-01', $first->next_action_at->toDateString());
        $this->assertSame('2026-10-13 15:30', $first->occurred_at->format('Y-m-d H:i'));
    }

    public function test_an_agent_sees_only_their_own_calls(): void
    {
        $this->logCall($this->anna, $this->tp->id, 'No answer', '2026-10-10 10:00:00');
        $this->logCall($this->bheki, $this->tp->id, 'Wrong number', '2026-10-11 10:00:00');

        $this->assertSame(['No answer'], $this->labels($this->svc()->forTrackedProperty($this->anna, $this->tp->id)));
        $this->assertSame(['Wrong number'], $this->labels($this->svc()->forTrackedProperty($this->bheki, $this->tp->id)));
    }

    public function test_a_branch_manager_sees_the_branch_and_an_admin_the_agency(): void
    {
        $this->logCall($this->anna, $this->tp->id, 'Anna call', '2026-10-10 10:00:00');
        $this->logCall($this->bheki, $this->tp->id, 'Bheki call', '2026-10-11 10:00:00');
        $this->logCall($this->carl, $this->tp->id, 'Carl call', '2026-10-12 10:00:00');

        $this->assertEqualsCanonicalizing(['Anna call', 'Bheki call'], $this->labels($this->svc()->forTrackedProperty($this->manager, $this->tp->id)));
        $this->assertEqualsCanonicalizing(['Anna call', 'Bheki call', 'Carl call'], $this->labels($this->svc()->forTrackedProperty($this->admin, $this->tp->id)));
    }

    public function test_another_agencys_calls_never_show(): void
    {
        $other = $this->agency();
        $otherBranch = $this->branchIn($other, $other + 9000);
        $stranger = User::factory()->create(['agency_id' => $other, 'branch_id' => $otherBranch, 'role' => 'agent', 'is_active' => true]);
        $this->logCall($stranger, $this->tp->id, 'Stranger call', '2026-10-11 10:00:00');
        $this->logCall($this->anna, $this->tp->id, 'Anna call', '2026-10-12 10:00:00');

        $this->assertSame(['Anna call'], $this->labels($this->svc()->forTrackedProperty($this->admin, $this->tp->id)));
    }

    public function test_the_property_page_also_shows_calls_on_the_tracked_property_it_was_promoted_from(): void
    {
        $property = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agencyId, 'agent_id' => $this->anna->id, 'branch_id' => $this->agencyId,
            'external_id' => (string) Str::uuid(), 'title' => 'Promoted', 'suburb' => 'Uvongo', 'property_type' => 'house',
            'listing_type' => 'sale', 'status' => 'active', 'price' => 1_000_000, 'published_at' => now(), 'mandate_type' => 'sole',
        ]);
        $unrelated = TrackedProperty::create(['agency_id' => $this->agencyId, 'street_number' => '9', 'street_name' => 'Other Road', 'suburb' => 'Uvongo']);
        $this->tp->forceFill(['promoted_to_property_id' => $property->id])->save();

        $this->logCall($this->anna, $this->tp->id, 'Before mandate', '2026-10-01 10:00:00');
        $this->logCall($this->anna, $unrelated->id, 'On the stock row', '2026-10-05 10:00:00', ['property_id' => $property->id]);
        $this->logCall($this->anna, $unrelated->id, 'Not this property', '2026-10-06 10:00:00');

        $this->assertSame(['On the stock row', 'Before mandate'], $this->labels($this->svc()->forProperty($this->anna, $property->id)));
    }

    public function test_it_pages_at_ten(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->logCall($this->anna, $this->tp->id, 'Call ' . $i, Carbon::parse('2026-10-01 08:00:00')->addHours($i)->toDateTimeString());
        }

        $this->actingAs($this->anna);
        $page1 = $this->svc()->forTrackedProperty($this->anna, $this->tp->id);
        $this->assertSame(12, $page1->total());
        $this->assertCount(10, $page1->items());
        $this->assertSame('Call 12', $page1->items()[0]->outcome_label);

        $this->get('/?rts_page=2');
        $page2 = $this->svc()->forTrackedProperty($this->anna, $this->tp->id);
        $this->assertSame(['Call 2', 'Call 1'], $this->labels($page2));
    }

    public function test_the_panel_shows_the_empty_state_in_one_line(): void
    {
        $this->actingAs($this->anna);
        $html = Blade::render('<x-road-to-sold.history-panel :tracked-property-id="$id" />', ['id' => $this->tp->id]);

        $this->assertStringContainsString('Road to Sold calls', $html);
        $this->assertStringContainsString('No Road to Sold calls on this property yet.', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function test_the_panel_lists_the_calls_and_hides_for_a_user_without_the_key(): void
    {
        $this->logCall($this->anna, $this->tp->id, 'Spoke - call back', '2026-10-13 15:30:00', [
            'kind' => 'conversation', 'note' => 'Phone him after the 20th', 'next_action_at' => '2026-12-01',
        ]);

        $this->actingAs($this->anna);
        $html = Blade::render('<x-road-to-sold.history-panel :tracked-property-id="$id" />', ['id' => $this->tp->id]);
        foreach (['Spoke - call back', 'Anna Agent', 'Phone him after the 20th', '13 Oct 2026 15:30', '1 Dec 2026'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $viewer = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer);
        $this->assertSame('', trim(Blade::render('<x-road-to-sold.history-panel :tracked-property-id="$id" />', ['id' => $this->tp->id])));
    }

    public function test_both_pages_carry_the_panel(): void
    {
        $property = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agencyId, 'agent_id' => $this->anna->id, 'branch_id' => $this->agencyId,
            'external_id' => (string) Str::uuid(), 'title' => 'Page test', 'suburb' => 'Uvongo', 'property_type' => 'house',
            'listing_type' => 'sale', 'status' => 'active', 'price' => 1_000_000, 'published_at' => now(), 'mandate_type' => 'sole',
        ]);
        $this->logCall($this->anna, $this->tp->id, 'Spoke - call back', '2026-10-13 15:30:00', ['property_id' => $property->id, 'note' => 'Page note xyz']);

        $this->actingAs($this->admin)
            ->get(route('market-intelligence.opportunities.show', $this->tp->id))
            ->assertOk()->assertSee('Road to Sold calls')->assertSee('Page note xyz');

        $this->actingAs($this->admin)
            ->get(route('corex.properties.show', $property->id))
            ->assertOk()->assertSee('Road to Sold calls')->assertSee('Page note xyz');
    }
}
