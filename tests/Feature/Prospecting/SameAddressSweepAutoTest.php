<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Events\Prospecting\TrackedPropertyCreated;
use App\Jobs\Prospecting\RunSameAddressSweepJob;
use App\Models\AddressMatchSetting;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\SameAddressSweep;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AT-520 step 4.2 (property-identity.md 17.3): the after-import same-address sweep - one debounced job per agency, off when the agency's switch is
 * off, journalled, scoped to its own agency, and the switch lives on the address-matching Settings page (default on).
 */
final class SameAddressSweepAutoTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
    }

    private function file(Agency $agency, array $attrs = []): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(
            ['agency_id' => $agency->id, 'status' => 'active', 'first_seen_at' => now(), 'street_number' => '33', 'street_name' => 'Windsor Avenue'],
            $attrs,
        ));
    }

    private function created(Agency $agency, int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            event(new TrackedPropertyCreated($i + 1, $agency->id, 'chrome_capture'));
        }
    }

    public function test_a_burst_of_imported_files_queues_one_delayed_sweep_for_that_agency(): void
    {
        Bus::fake();
        $this->created($this->a, 25);

        Bus::assertDispatchedTimes(RunSameAddressSweepJob::class, 1);
        Bus::assertDispatched(RunSameAddressSweepJob::class, fn (RunSameAddressSweepJob $j) => $j->agencyId === $this->a->id && $j->delay !== null);
    }

    public function test_it_is_on_by_default_and_each_agency_gets_its_own_job(): void
    {
        Bus::fake();
        $this->assertTrue(AddressMatchSetting::forAgency($this->a->id)['same_address_sweep']);
        $this->created($this->a, 3);
        $this->created($this->b, 3);

        Bus::assertDispatchedTimes(RunSameAddressSweepJob::class, 2);
    }

    public function test_an_agency_that_switched_it_off_gets_no_sweep(): void
    {
        Bus::fake();
        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->a->id], ['same_address_sweep' => false]);
        $this->created($this->a, 5);

        Bus::assertNotDispatched(RunSameAddressSweepJob::class);
    }

    public function test_the_job_merges_houses_journals_the_rows_and_lets_the_next_import_schedule_again(): void
    {
        $this->file($this->a);
        $loser = $this->file($this->a);
        $mine = $this->file($this->b);
        $theirs = $this->file($this->b);
        Cache::put(RunSameAddressSweepJob::key($this->a->id), 1, 600);
        $journal = storage_path('app/reports/same-address-auto-' . now()->format('Ymd') . '.jsonl');
        @unlink($journal);

        (new RunSameAddressSweepJob($this->a->id))->handle(app(SameAddressSweep::class), app(TrackedPropertyMerger::class));

        $statuses = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->a->id)->pluck('status')->sort()->values()->all();
        $this->assertSame(['active', 'duplicate'], $statuses);
        $this->assertSame(['active', 'active'], TrackedProperty::queryWithoutAgencyScope()->whereIn('id', [$mine->id, $theirs->id])->pluck('status')->all(), "another agency's files are not touched by this agency's sweep");
        $this->assertFileExists($journal);
        $this->assertStringContainsString('"table":"tracked_properties"', (string) file_get_contents($journal));
        @unlink($journal);
        $this->assertFalse(Cache::has(RunSameAddressSweepJob::key($this->a->id)), 'work arriving after the run schedules the next one');
        $this->assertNotNull($loser);
    }

    public function test_the_job_does_nothing_when_the_switch_was_turned_off_after_it_was_queued(): void
    {
        $this->file($this->a);
        $this->file($this->a);
        AddressMatchSetting::query()->updateOrCreate(['agency_id' => $this->a->id], ['same_address_sweep' => false]);

        (new RunSameAddressSweepJob($this->a->id))->handle(app(SameAddressSweep::class), app(TrackedPropertyMerger::class));

        $this->assertSame(2, TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->a->id)->where('status', 'active')->count());
    }

    public function test_the_settings_page_shows_and_saves_the_switch_and_a_partial_post_never_turns_it_off(): void
    {
        $branch = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $admin = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $base = array_intersect_key(AddressMatchSetting::forAgency($this->a->id), array_flip(['possible_min_agreeing_columns', 'neighbour_suburb_credit', 'gps_radius_m', 'unit_missing_on_one_side', 'fuzzy_threshold', 'auto_merge_min_confidence']));

        $this->actingAs($admin)->get(route('settings.prospecting.address-matching.edit'))->assertOk()->assertSee('Tidy up same-address properties after every import');

        $this->actingAs($admin)->put(route('settings.prospecting.address-matching.update'), $base + ['same_address_sweep_present' => '1'])->assertRedirect();
        $this->assertFalse(AddressMatchSetting::forAgency($this->a->id)['same_address_sweep'], 'the form was submitted with the box unticked');

        $this->actingAs($admin)->put(route('settings.prospecting.address-matching.update'), $base + ['same_address_sweep_present' => '1', 'same_address_sweep' => '1'])->assertRedirect();
        $this->assertTrue(AddressMatchSetting::forAgency($this->a->id)['same_address_sweep']);

        $this->actingAs($admin)->put(route('settings.prospecting.address-matching.update'), $base)->assertRedirect();
        $this->assertTrue(AddressMatchSetting::forAgency($this->a->id)['same_address_sweep'], 'a post without the switch leaves it alone');
        $this->assertTrue(AddressMatchSetting::forAgency($this->b->id)['same_address_sweep'], "another agency's switch is its own");
    }
}
