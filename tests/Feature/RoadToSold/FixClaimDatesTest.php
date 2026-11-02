<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\ProspectingClaim;
use App\Models\RoadToSold\RtsProspect;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\WorkdayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** road-to-sold.md 23.22 (2 Nov 2026): rts:fix-claim-dates - the real claim date as Since/Days and hold clock, on the records the adoption stamped "today". */
final class FixClaimDatesTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        $this->dir = sys_get_temp_dir() . '/rts-fix-' . uniqid();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function today30(): string
    {
        return app(WorkdayService::class)->addWorkdays(now(), 30, 'ZA', $this->agent)->toDateString();
    }

    /** A claim taken $daysAgo, on the belt the way the 2 Nov adoption left it: entry date = today, hold = 30 working days from today. */
    private function adopted(int $daysAgo, ?int $trackedId = null, ?int $userId = null): array
    {
        $tp = $trackedId ? \App\Models\Prospecting\TrackedProperty::withoutGlobalScopes()->find($trackedId) : $this->tp(['street_name' => 'Adopt Street', 'street_number' => (string) random_int(1, 999)]);
        $taken = now()->subDays($daysAgo);
        $claim = ProspectingClaim::withoutEvents(fn () => ProspectingClaim::create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listing([], $tp), 'user_id' => $userId ?? $this->agent->id,
            'status' => 'claimed', 'claimed_at' => $taken, 'last_updated_at' => $taken,
        ]));
        $p = RtsProspect::withoutAgencyStamping(fn () => app(ProspectService::class)->place($this->agent, (int) $tp->id, 'sales', ['stage' => 'claimed', 'selection' => 'claimed_mic', 'actor' => $this->agent]));
        $claim->forceFill(['rts_prospect_id' => $p->id, 'hold_until' => $this->today30()])->saveQuietly();

        return [$claim->fresh(), $p->fresh(), (int) $tp->id];
    }

    private function fix(string $extra = ''): void
    {
        $this->artisan("rts:fix-claim-dates --agency={$this->agency->id} --journal-dir={$this->dir} {$extra}")->assertSuccessful();
    }

    public function test_dry_run_writes_nothing_then_apply_sets_the_real_date_and_the_hold_from_it_and_undo_puts_it_back(): void
    {
        [$c, $p] = $this->adopted(12);

        $this->fix();
        $this->assertSame('2026-11-02', $p->fresh()->stage_entered_at->toDateString(), 'a dry run writes nothing');
        $this->assertSame($this->today30(), $c->fresh()->hold_until->toDateString());
        $this->assertSame([], glob($this->dir . '/*.jsonl') ?: []);

        $this->fix('--apply');
        $taken = now()->subDays(12);
        $this->assertSame($taken->toDateString(), $p->fresh()->stage_entered_at->toDateString(), 'Since is the day the claim was taken');
        $this->assertSame(app(WorkdayService::class)->addWorkdays($taken, 30, 'ZA', $this->agent)->toDateString(), $c->fresh()->hold_until->toDateString());
        $this->assertGreaterThanOrEqual(11, (int) $p->fresh()->stage_entered_at->diffInDays(now()), 'Days reads the real age');

        $journal = glob($this->dir . '/rts-fix-claim-dates-agency' . $this->agency->id . '-*.jsonl');
        $this->assertCount(1, $journal);
        $line = json_decode(file($journal[0])[0], true);
        $this->assertSame($p->id, $line['prospect_id']);
        $this->assertFalse($line['claims'][0]['fresh_30_from_today']);

        $this->artisan("rts:fix-claim-dates --undo={$journal[0]}")->assertSuccessful();
        $this->assertSame('2026-11-02', $p->fresh()->stage_entered_at->toDateString());
        $this->assertSame($this->today30(), $c->fresh()->hold_until->toDateString());
    }

    public function test_a_claim_whose_hold_has_already_ended_gets_the_whole_hold_from_today_and_a_journal_line_says_so(): void
    {
        [$c, $p] = $this->adopted(200);

        $this->fix('--apply');

        $this->assertSame(now()->subDays(200)->toDateString(), $p->fresh()->stage_entered_at->toDateString(), 'the real date is the Since');
        $this->assertSame($this->today30(), $c->fresh()->hold_until->toDateString(), 'nobody loses a claim to this fix');
        $line = json_decode(file(glob($this->dir . '/*.jsonl')[0])[0], true)['claims'][0];
        $this->assertTrue($line['fresh_30_from_today']);
        $this->assertStringContainsString('given the whole hold from today', $line['note']);
    }

    public function test_an_extended_hold_is_never_shortened_and_the_earliest_of_two_claims_is_the_entry_date(): void
    {
        [$c1, $p, $tpId] = $this->adopted(10);
        $c1->forceFill(['hold_extensions' => 1, 'hold_extended_at' => now(), 'hold_until' => now()->addDays(120)->toDateString()])->saveQuietly();
        [$c2] = $this->adopted(20, $tpId);
        $c2->forceFill(['rts_prospect_id' => $p->id])->saveQuietly();

        $this->fix('--apply');

        $this->assertSame(now()->subDays(20)->toDateString(), $p->fresh()->stage_entered_at->toDateString(), 'the earliest live claim date');
        $this->assertSame(now()->addDays(120)->toDateString(), $c1->fresh()->hold_until->toDateString(), 'an extended hold stays');
        $this->assertSame(app(WorkdayService::class)->addWorkdays(now()->subDays(20), 30, 'ZA', $this->agent)->toDateString(), $c2->fresh()->hold_until->toDateString());
    }

    public function test_worked_moved_and_already_right_records_are_left_alone(): void
    {
        [$worked, $pw] = $this->adopted(15);
        app(ProspectService::class)->setNextAction($pw, 'Call back Friday', now()->addDay(), $this->agent);
        [$moved, $pm] = $this->adopted(15);
        app(ProspectService::class)->advance($pm, 'owner_found', $this->agent);
        [$todays, $pt] = $this->adopted(0);

        $this->fix('--apply');

        $this->assertSame('2026-11-02', $pw->fresh()->stage_entered_at->toDateString(), 'worked: untouched');
        $this->assertSame($this->today30(), $worked->fresh()->hold_until->toDateString());
        $this->assertSame('2026-11-02', $pm->fresh()->stage_entered_at->toDateString(), 'moved: untouched');
        $this->assertSame($this->today30(), $moved->fresh()->hold_until->toDateString());
        $this->assertSame($this->today30(), $todays->fresh()->hold_until->toDateString(), 'a claim taken today: nothing to correct');
        $this->assertSame([], glob($this->dir . '/*.jsonl') ? array_filter(file(glob($this->dir . '/*.jsonl')[0])) : []);
    }

    public function test_the_adoption_journal_limits_the_run_and_each_agent_is_counted(): void
    {
        [$in, $pin] = $this->adopted(9);
        [$out, $pout] = $this->adopted(9);
        $file = $this->dir . '-adopt.jsonl';
        file_put_contents($file, json_encode(['claim_id' => $in->id]) . "\n");

        $this->artisan("rts:fix-claim-dates --agency={$this->agency->id} --journal-dir={$this->dir} --apply --adoption-journal={$file}")
            ->expectsOutputToContain('agent ' . $this->agent->id . ': {"rows_changed":1')->assertSuccessful();

        $this->assertSame(now()->subDays(9)->toDateString(), $pin->fresh()->stage_entered_at->toDateString());
        $this->assertSame('2026-11-02', $pout->fresh()->stage_entered_at->toDateString(), 'not in the adoption journal: untouched');
    }
}
