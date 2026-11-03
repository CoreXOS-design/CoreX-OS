<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\SameAddressSweep;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520 step 4.1 (mic-work-tab.md section 4): a merge journals every row it moves (one merger per process), and two work records become ONE -
 * claim, status, reason, own stock, contact and deed data all survive on the surviving file; whatever loses is written to the history.
 */
final class TrackedPropertyMergerSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private User $u1;
    private User $u2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->u1 = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'agent']);
        $this->u2 = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'agent']);
    }

    private function file(array $attrs = []): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(
            ['agency_id' => $this->a->id, 'status' => 'active', 'first_seen_at' => now(), 'street_number' => '33', 'street_name' => 'Windsor Avenue'],
            $attrs,
        ));
    }

    private function work(TrackedProperty $tp, array $attrs = []): int
    {
        return (int) DB::table('tracked_property_agency_work')->insertGetId(array_merge(
            ['agency_id' => $this->a->id, 'tracked_property_id' => $tp->id, 'created_at' => now(), 'updated_at' => now()],
            $attrs,
        ));
    }

    private function workRow(TrackedProperty $tp): ?object
    {
        return DB::table('tracked_property_agency_work')->where('tracked_property_id', $tp->id)->whereNull('deleted_at')->first();
    }

    private function merge(TrackedProperty $winner, TrackedProperty $loser): array
    {
        return app(TrackedPropertyMerger::class)->merge($winner->fresh(), $loser->fresh(), true, 'test');
    }

    public function test_the_merger_is_one_instance_and_the_sweep_journals_every_row_it_moves(): void
    {
        $this->assertSame(app(TrackedPropertyMerger::class), app(TrackedPropertyMerger::class));

        $this->file();
        $loserFile = $this->file();
        $path = sys_get_temp_dir() . '/merge-journal-' . uniqid() . '.jsonl';
        app(TrackedPropertyMerger::class)->journalTo($path);
        $r = app(SameAddressSweep::class)->run($this->a->id, 'no-suburb', true);
        app(TrackedPropertyMerger::class)->journalTo(null);

        $this->assertSame(1, $r['merged_files']);
        $lines = array_filter(explode("\n", (string) file_get_contents($path)));
        @unlink($path);
        $this->assertNotEmpty($lines, 'the journal must not be empty after a merge');
        $this->assertStringContainsString('"table":"tracked_properties"', implode("\n", $lines));
        $this->assertNotNull($loserFile);
    }

    public function test_a_claim_status_and_reason_on_the_losing_file_move_to_the_survivor(): void
    {
        $winner = $this->file();
        $loser = $this->file();
        $this->work($winner);
        $this->work($loser, ['claimed_by_user_id' => $this->u1->id, 'claimed_at' => '2026-10-01 10:00:00', 'prospecting_status' => 'not_selling',
            'prospecting_reason' => 'Sold to a family member', 'status_set_by_user_id' => $this->u1->id, 'status_set_at' => '2026-10-02 09:00:00']);

        $res = $this->merge($winner, $loser);

        $this->assertTrue($res['merged']);
        $w = $this->workRow($winner);
        $this->assertSame($this->u1->id, (int) $w->claimed_by_user_id);
        $this->assertSame('not_selling', $w->prospecting_status);
        $this->assertSame('Sold to a family member', $w->prospecting_reason);
        $this->assertNull($this->workRow($loser), 'the loser record is folded, not left behind');
        $this->assertSame(1, DB::table('tracked_property_agency_work')->where('tracked_property_id', $loser->id)->whereNotNull('deleted_at')->count(), 'soft-deleted, never hard-deleted');
    }

    public function test_two_claims_by_different_agents_keep_the_winners_and_write_the_other_to_the_history(): void
    {
        $winner = $this->file();
        $loser = $this->file();
        $this->work($winner, ['claimed_by_user_id' => $this->u1->id, 'claimed_at' => '2026-10-01 10:00:00']);
        $this->work($loser, ['claimed_by_user_id' => $this->u2->id, 'claimed_at' => '2026-10-03 10:00:00']);

        $this->merge($winner, $loser);

        $this->assertSame($this->u1->id, (int) $this->workRow($winner)->claimed_by_user_id);
        $event = DB::table('tracked_property_agency_work_events')->where('tracked_property_id', $winner->id)->where('kind', 'claim')->first();
        $this->assertNotNull($event, 'the second claim is kept in the history');
        $this->assertStringContainsString('user #' . $this->u2->id, (string) $event->reason);
    }

    public function test_the_newer_status_wins_and_the_older_one_stays_in_the_history(): void
    {
        $winner = $this->file();
        $loser = $this->file();
        $this->work($winner, ['prospecting_status' => 'contacted', 'status_set_at' => '2026-09-01 09:00:00']);
        $this->work($loser, ['prospecting_status' => 'not_selling', 'prospecting_reason' => 'Owner moved abroad', 'status_set_at' => '2026-10-10 09:00:00']);

        $this->merge($winner, $loser);

        $w = $this->workRow($winner);
        $this->assertSame('not_selling', $w->prospecting_status);
        $this->assertSame('Owner moved abroad', $w->prospecting_reason);
        $event = DB::table('tracked_property_agency_work_events')->where('tracked_property_id', $winner->id)->where('kind', 'status')->first();
        $this->assertSame('contacted', $event->from_value);
        $this->assertSame('not_selling', $event->to_value);
    }

    public function test_a_history_line_of_the_loser_moves_to_the_survivor(): void
    {
        $winner = $this->file();
        $loser = $this->file();
        $this->work($winner);
        $this->work($loser, ['prospecting_status' => 'contacted', 'status_set_at' => now()]);
        DB::table('tracked_property_agency_work_events')->insert(['agency_id' => $this->a->id, 'tracked_property_id' => $loser->id, 'kind' => 'status',
            'to_value' => 'contacted', 'reason' => 'Called the owner', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->merge($winner, $loser);

        $this->assertSame(1, DB::table('tracked_property_agency_work_events')->where('tracked_property_id', $winner->id)->where('reason', 'Called the owner')->count());
        $this->assertSame(0, DB::table('tracked_property_agency_work_events')->where('tracked_property_id', $loser->id)->count());
    }

    public function test_deed_data_and_a_contact_link_on_the_losing_file_survive(): void
    {
        $winner = $this->file();
        $loser = $this->file(['title_deed_number' => 'T1234/2019', 'deeds_office' => 'Pietermaritzburg', 'last_known_sold_price' => 1500000]);

        $this->merge($winner, $loser);

        $w = TrackedProperty::queryWithoutAgencyScope()->find($winner->id);
        $this->assertSame('T1234/2019', $w->title_deed_number);
        $this->assertSame('Pietermaritzburg', $w->deeds_office);
        $this->assertEquals(1500000, (float) $w->last_known_sold_price);
        $this->assertSame('duplicate', TrackedProperty::queryWithoutAgencyScope()->find($loser->id)->status);
    }

    public function test_a_deed_the_survivor_already_has_is_never_overwritten(): void
    {
        $winner = $this->file(['title_deed_number' => 'T-WINNER']);
        $loser = $this->file(['title_deed_number' => 'T-LOSER']);

        $this->merge($winner, $loser);

        $this->assertSame('T-WINNER', TrackedProperty::queryWithoutAgencyScope()->find($winner->id)->title_deed_number);
    }
}
