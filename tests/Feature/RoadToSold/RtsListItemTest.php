<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-454 piece 3 — rts_list_items: shape, uniqueness, soft delete, and own / branch / agency scoping.
 */
final class RtsListItemTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $b1;
    private Branch $b2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Agency One', 'slug' => 'one-' . uniqid()]);
        $this->b1 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'North']);
        $this->b2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'South']);
    }

    private function agent(Branch $b, ?Agency $a = null): User
    {
        return User::factory()->create(['agency_id' => ($a ?? $this->agency)->id, 'branch_id' => $b->id, 'role' => 'agent']);
    }

    private function item(User $u, array $attrs = []): RtsListItem
    {
        return RtsListItem::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $u->agency_id, 'branch_id' => $u->branch_id, 'user_id' => $u->id,
            'list_date' => '2026-10-14', 'track' => 'sales', 'tracked_property_id' => random_int(1000, 9999999),
            'selection' => 'new_on_market', 'origin' => 'auto', 'position' => 1, 'status' => 'open',
        ], $attrs));
    }

    public function test_an_item_stores_every_contract_column_and_defaults(): void
    {
        $u = $this->agent($this->b1);
        $i = RtsListItem::withoutGlobalScopes()->create([
            'agency_id' => $u->agency_id, 'user_id' => $u->id, 'list_date' => '2026-10-14',
            'tracked_property_id' => 55, 'prospecting_listing_id' => 66, 'property_id' => 77,
            'selection' => 'price_drops', 'reason' => 'Dropped 8% this week',
        ])->fresh();

        $this->assertSame('sales', $i->track);
        $this->assertSame('auto', $i->origin);
        $this->assertSame('open', $i->status);
        $this->assertSame(0, $i->position);
        $this->assertSame([55, 66, 77], [$i->tracked_property_id, $i->prospecting_listing_id, $i->property_id]);
        $this->assertSame('2026-10-14', $i->list_date->toDateString());
        $this->assertSame('Dropped 8% this week', $i->reason);
    }

    public function test_the_same_property_cannot_be_on_one_agents_day_twice(): void
    {
        $u = $this->agent($this->b1);
        $this->item($u, ['tracked_property_id' => 500]);

        $this->expectException(QueryException::class);
        $this->item($u, ['tracked_property_id' => 500, 'selection' => 'long_on_market']);
    }

    public function test_the_same_property_is_fine_on_another_day_another_agent_or_another_track(): void
    {
        $u = $this->agent($this->b1);
        $v = $this->agent($this->b1);
        $this->item($u, ['tracked_property_id' => 500]);
        $this->item($u, ['tracked_property_id' => 500, 'list_date' => '2026-10-15']);
        $this->item($v, ['tracked_property_id' => 500]);
        $this->item($u, ['tracked_property_id' => 500, 'track' => 'rentals']);

        $this->assertSame(4, RtsListItem::withoutGlobalScopes()->count());
    }

    public function test_items_without_a_tracked_property_are_not_blocked_by_the_unique_index(): void
    {
        $u = $this->agent($this->b1);
        $this->item($u, ['tracked_property_id' => null, 'prospecting_listing_id' => 1]);
        $this->item($u, ['tracked_property_id' => null, 'prospecting_listing_id' => 2]);

        $this->assertSame(2, RtsListItem::withoutGlobalScopes()->whereNull('tracked_property_id')->count());
    }

    public function test_soft_delete_hides_the_row_and_restore_brings_it_back(): void
    {
        $u = $this->agent($this->b1);
        $i = $this->item($u);
        $i->delete();

        // keep the soft-delete filter, drop only the agency / branch walls
        $q = fn () => RtsListItem::withoutGlobalScopes([\App\Models\Scopes\AgencyScope::class, \App\Models\Scopes\BranchScope::class]);

        $this->assertSame(0, $q()->count());
        $this->assertSame(1, $q()->withTrashed()->count());

        $q()->withTrashed()->find($i->id)->restore();
        $this->assertSame(1, $q()->count());
    }

    public function test_for_day_open_and_counting_toward_target_scopes(): void
    {
        $u = $this->agent($this->b1);
        $other = $this->agent($this->b1);
        $auto  = $this->item($u);
        $fu    = $this->item($u, ['origin' => 'follow_up', 'selection' => 'follow_up']);
        $added = $this->item($u, ['origin' => 'added']);
        $done  = $this->item($u, ['status' => 'worked']);
        $this->item($u, ['list_date' => '2026-10-15']);
        $this->item($other);

        $day = RtsListItem::withoutGlobalScopes()->forDay($u, '2026-10-14');
        $this->assertEqualsCanonicalizing([$auto->id, $fu->id, $added->id, $done->id], (clone $day)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$auto->id, $fu->id, $added->id], (clone $day)->open()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$auto->id, $fu->id, $done->id], (clone $day)->countingTowardTarget()->pluck('id')->all());
    }

    public function test_own_scope_sees_only_the_viewers_own_list(): void
    {
        $me = $this->agent($this->b1);
        $mate = $this->agent($this->b1);
        $mine = $this->item($me);
        $this->item($mate);

        $ids = RtsListItem::withoutGlobalScopes()->visibleTo($me, 'own')->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
    }

    public function test_branch_scope_sees_the_branch_not_the_other_branch(): void
    {
        $bm = $this->agent($this->b1);
        $inBranch = $this->item($this->agent($this->b1));
        $this->item($this->agent($this->b2));

        $ids = RtsListItem::withoutGlobalScopes()->visibleTo($bm, 'branch')->pluck('id')->all();
        $this->assertSame([$inBranch->id], $ids);
    }

    public function test_all_scope_sees_every_branch_of_the_agency(): void
    {
        $admin = $this->agent($this->b1);
        $a = $this->item($this->agent($this->b1));
        $b = $this->item($this->agent($this->b2));

        $ids = RtsListItem::withoutGlobalScopes()->visibleTo($admin, 'all')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function test_no_scope_or_none_sees_nothing(): void
    {
        $me = $this->agent($this->b1);
        $this->item($me);

        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->visibleTo($me, 'none')->count());
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->visibleTo($me, 'nonsense')->count());
    }

    /** The agency wall is the model's own global scope — a second agency never sees the first's rows. */
    public function test_another_agency_never_sees_this_agencys_list_even_on_all_scope(): void
    {
        $mine = $this->item($this->agent($this->b1));

        $other = Agency::create(['name' => 'Agency Two', 'slug' => 'two-' . uniqid()]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $outsider = $this->agent($ob, $other);

        $this->actingAs($outsider);
        $this->assertSame(0, RtsListItem::query()->visibleTo($outsider, 'all')->count());
        $this->assertNull(RtsListItem::query()->find($mine->id), 'direct lookup by id is blocked by the agency scope');
    }

    public function test_the_branch_follows_the_agent_whose_list_it_is(): void
    {
        $u = $this->agent($this->b2);
        $i = RtsListItem::withoutGlobalScopes()->create([
            'agency_id' => $u->agency_id, 'user_id' => $u->id, 'list_date' => '2026-10-14',
            'tracked_property_id' => 1, 'selection' => 'new_on_market',
        ]);

        $this->assertSame((int) $this->b2->id, (int) $i->fresh()->branch_id);
    }
}
