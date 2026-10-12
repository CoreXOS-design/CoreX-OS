<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedReceipt;
use Illuminate\Support\Facades\DB;

/**
 * AT-450 - the list: default order (oldest uncollected first), every column sorts, search on the four named fields, the
 * filters (status, branch, agent, received date range), pagination, the two empty states, CSV (and formula injection).
 */
final class TitleDeedListTest extends TitleDeedTestCase
{
    private TitleDeed $old;
    private TitleDeed $mid;
    private TitleDeed $new;
    private TitleDeed $done;

    protected function setUp(): void
    {
        parent::setUp();
        $other = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchB->id, 'role' => 'agent', 'name' => 'Zinhle Cele']);

        $this->old = $this->deed([$this->contact('Nomvula', 'Dube')], ['received_at' => '2026-08-01', '_property' => $this->property('10 Alpha Road', 'Margate'), 'attorney_firm' => 'Cox Yeats']);
        $this->mid = $this->deed([$this->contact('Pieter', 'van der Merwe')], ['received_at' => '2026-09-01', '_property' => $this->property('20 Bravo Road', 'Uvongo'), 'agent_id' => $other->id, 'branch_id' => $this->branchB->id]);
        $this->new = $this->deed([$this->contact('Lerato', 'Khumalo')], ['received_at' => '2026-10-01', '_property' => $this->property('30 Charlie Road', 'Ramsgate')]);
        $this->done = $this->deed([$this->contact('Thandi', 'Mkhize')], ['received_at' => '2026-07-01', '_property' => $this->property('40 Delta Road', 'Shelly Beach')]);
        $this->done->forceFill(['status' => 'collected', 'collected_at' => '2026-07-20 10:00:00', 'collected_by_name' => 'Busisiwe Mkhize'])->save();
        TitleDeedReceipt::create([
            'agency_id' => $this->agencyId, 'title_deed_id' => $this->done->id, 'method' => 'pin', 'collector_type' => 'representative', 'collector_name' => 'Busisiwe Mkhize',
            'recorded_by_user_id' => $this->admin->id,
        ]);
    }

    private function order(string $query = ''): array
    {
        $html = $this->actingAs($this->admin)->get(route('title-deeds.index') . $query)->assertOk()->getContent();
        $found = [];
        foreach (['10 Alpha', '20 Bravo', '30 Charlie', '40 Delta'] as $needle) {
            if (($pos = strpos($html, $needle)) !== false) {
                $found[$pos] = $needle;
            }
        }
        ksort($found);

        return array_values($found);
    }

    public function test_the_default_order_is_oldest_uncollected_first_with_collected_last(): void
    {
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie', '40 Delta'], $this->order());
    }

    public function test_every_column_sorts_in_both_directions(): void
    {
        $this->assertSame(['30 Charlie', '20 Bravo', '10 Alpha', '40 Delta'], $this->order('?sort=received&direction=desc'), 'received desc');
        $this->assertSame(['40 Delta', '10 Alpha', '20 Bravo', '30 Charlie'], $this->order('?sort=received&direction=asc'));
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie', '40 Delta'], $this->order('?sort=property&direction=asc'));
        $this->assertSame(['40 Delta', '30 Charlie', '20 Bravo', '10 Alpha'], $this->order('?sort=property&direction=desc'));
        $this->assertSame('20 Bravo', $this->order('?sort=agent&direction=desc')[0], 'agent: Zinhle Cele sorts last alphabetically');
        $this->assertSame('40 Delta', $this->order('?sort=waiting&direction=desc')[0], 'most days waiting = earliest received');
        $this->assertSame('40 Delta', $this->order('?sort=collected&direction=desc')[0]);
        $this->assertCount(4, $this->order('?sort=status&direction=asc'));
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie', '40 Delta'], $this->order('?sort=nonsense&direction=sideways'), 'an unknown sort falls back to the default');
    }

    public function test_search_finds_by_property_owner_attorney_and_collector(): void
    {
        $this->assertSame(['20 Bravo'], $this->order('?search=Bravo'), 'property address');
        $this->assertSame(['30 Charlie'], $this->order('?search=Khumalo'), 'owner name');
        $this->assertSame(['30 Charlie'], $this->order('?search=Lerato%20Khumalo'), 'owner full name');
        $this->assertSame(['10 Alpha'], $this->order('?search=Cox'), 'attorney firm');
        $this->assertSame(['40 Delta'], $this->order('?search=Busisiwe'), 'collector name');
        $this->assertSame([], $this->order('?search=Nobody%20Here'));
        $this->assertSame([], $this->order('?search=%25'), 'a percent sign is searched for literally, it is not a wildcard');
    }

    public function test_filters_status_agent_branch_and_date_range(): void
    {
        $this->assertSame(['40 Delta'], $this->order('?status=collected'));
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie'], $this->order('?status=received'));
        $this->assertSame([], $this->order('?status=notified'));
        $this->assertSame(['20 Bravo'], $this->order('?agent_id=' . \App\Models\User::where('name', 'Zinhle Cele')->value('id')));
        $this->assertSame(['20 Bravo'], $this->order('?branch_id=' . $this->branchB->id));
        $this->assertSame(['20 Bravo', '30 Charlie'], $this->order('?from=2026-09-01&to=2026-10-31'));
        $this->assertSame(['10 Alpha'], $this->order('?from=2026-08-01&to=2026-08-31'));
        $this->assertSame(['10 Alpha', '20 Bravo', '30 Charlie', '40 Delta'], $this->order('?status='), 'an empty filter is no filter');
    }

    public function test_the_archived_filter_lists_archived_deeds_only(): void
    {
        $this->mid->delete();
        $this->assertSame(['10 Alpha', '30 Charlie', '40 Delta'], $this->order());
        $this->assertSame(['20 Bravo'], $this->order('?status=archived'));
    }

    public function test_a_bad_filter_value_is_refused_not_a_500(): void
    {
        $this->actingAs($this->admin)->get(route('title-deeds.index', ['status' => 'bogus']))->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->get(route('title-deeds.index', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
    }

    public function test_days_waiting_counts_for_open_deeds_only(): void
    {
        $html = $this->actingAs($this->admin)->get(route('title-deeds.index'))->getContent();
        $days = (int) \Carbon\Carbon::parse('2026-08-01')->diffInDays(now()->startOfDay());
        $this->assertSame($days, $this->old->fresh()->days_waiting);
        $this->assertNull($this->done->fresh()->days_waiting);
        $this->assertStringContainsString('20 Jul 2026 10:00', $html);
    }

    public function test_pagination_and_the_empty_states(): void
    {
        $this->actingAs($this->admin)->get(route('title-deeds.index', ['search' => 'zzzz']))->assertOk()->assertSee('No title deeds match these filters');

        DB::table('title_deeds')->whereNotNull('id')->update(['deleted_at' => now()]);
        $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()->assertSee('No title deeds logged yet');   // everything is archived: nothing active to show

        DB::table('title_deed_owners')->delete();
        DB::table('title_deed_receipts')->delete();
        DB::table('title_deed_audit_log')->delete();
        DB::table('title_deeds')->delete();
        $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()->assertSee('No title deeds logged yet');

        for ($i = 0; $i < 55; $i++) {
            DB::table('title_deeds')->insert([
                'agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'agent_id' => $this->agent->id, 'created_by' => $this->admin->id,
                'property_id' => $this->mid->property_id, 'property_address' => "Row $i Test Street", 'received_at' => '2026-09-01', 'status' => 'received',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $page1 = $this->actingAs($this->admin)->get(route('title-deeds.index'))->getContent();
        $page2 = $this->actingAs($this->admin)->get(route('title-deeds.index', ['page' => 2]))->getContent();
        $this->assertSame(50, substr_count($page1, 'data-row-actions>'));
        $this->assertStringContainsString('of 55', $page1);
        $this->assertSame(5, substr_count($page2, 'data-row-actions>'));
    }

    public function test_csv_export_follows_the_filters_and_neutralises_formulas(): void
    {
        $this->old->update(['attorney_firm' => '=HYPERLINK("http://evil.test","click")']);
        $csv = $this->actingAs($this->admin)->get(route('title-deeds.export', ['status' => 'received']))->assertOk()->streamedContent();

        $this->assertStringContainsString('10 Alpha Road', $csv);
        $this->assertStringNotContainsString('40 Delta Road', $csv, 'the filter applies to the export');
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString('Property,Owners,Agent,Branch', $csv);
    }
}
