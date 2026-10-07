<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\SuggestedActionThresholds;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan 2026-10-07 — the stale-listing window is 90 days (a mandate normally runs 90), per agency,
 * not a fixed 30; listings the old 30-day rule switched off but that are inside the window are
 * switched back on (one-off, idempotent, reversible).
 */
final class StaleListingWindowTest extends TestCase
{
    use RefreshDatabase;

    // ── The window is an agency setting, default 90 ───────────────────────

    public function test_default_window_is_ninety_days(): void
    {
        $agencyId = $this->makeAgency();

        $this->assertSame(90, (int) SuggestedActionThresholds::getOrCreateForAgency($agencyId)->listing_off_market_days);
    }

    public function test_settings_page_shows_and_saves_the_window(): void
    {
        [$agencyId, $owner] = $this->agencyOwner();

        $this->actingAs($owner)->get(route('settings.prospecting.stale-rules.edit'))
            ->assertOk()->assertSee('listing_off_market_days', false);

        $this->actingAs($owner)->put(route('settings.prospecting.stale-rules.update'), [
            'claim_warn_days' => 7, 'claim_release_days' => 10,
            'mic_counts_cache_fresh_seconds' => 60, 'mic_counts_cache_stale_seconds' => 300,
            'listing_off_market_days' => 120,
        ])->assertSessionHasNoErrors();

        $this->assertSame(120, (int) SuggestedActionThresholds::getOrCreateForAgency($agencyId)->listing_off_market_days);
    }

    public function test_a_form_that_does_not_post_the_window_leaves_it_alone(): void
    {
        [$agencyId, $owner] = $this->agencyOwner();
        SuggestedActionThresholds::getOrCreateForAgency($agencyId)->update(['listing_off_market_days' => 45]);

        $this->actingAs($owner)->put(route('settings.prospecting.stale-rules.update'), [
            'claim_warn_days' => 7, 'claim_release_days' => 10,
            'mic_counts_cache_fresh_seconds' => 60, 'mic_counts_cache_stale_seconds' => 300,
        ])->assertSessionHasNoErrors();

        $this->assertSame(45, (int) SuggestedActionThresholds::getOrCreateForAgency($agencyId)->listing_off_market_days);
    }

    public function test_the_wizard_market_intelligence_step_carries_the_setting(): void
    {
        $step = config('agency-onboarding-copy.market_intelligence');
        $control = collect($step['controls'] ?? [])->firstWhere('key', 'listing_off_market_days');

        $this->assertNotNull($control, 'a new setting must reach the Setup Wizard (CLAUDE.md #10a)');
        $this->assertSame(90, $control['default']);
        $this->assertNotEmpty($control['explain']);
        $this->assertNotEmpty($control['affects']);
        $this->assertContains(
            ['controller' => \App\Http\Controllers\Settings\Prospecting\StaleRulesController::class, 'method' => 'updateListingWindow'],
            $step['savers'] ?? []
        );
    }

    public function test_wizard_saver_writes_only_when_the_field_was_posted(): void
    {
        [$agencyId, $owner] = $this->agencyOwner();
        $this->actingAs($owner);
        $controller = app(\App\Http\Controllers\Settings\Prospecting\StaleRulesController::class);
        $config = app(\App\Services\Prospecting\ProspectingConfigurationService::class);
        $req = function (array $data) use ($owner) {
            $r = \Illuminate\Http\Request::create('/x', 'POST', $data);
            $r->setUserResolver(fn () => $owner);

            return $r;
        };

        $controller->updateListingWindow($req(['listing_off_market_days' => 75]), $config);
        $this->assertSame(75, (int) SuggestedActionThresholds::getOrCreateForAgency($agencyId)->listing_off_market_days);

        // absent field → untouched (wizard spec §6.1)
        $controller->updateListingWindow($req([]), $config);
        $this->assertSame(75, (int) SuggestedActionThresholds::getOrCreateForAgency($agencyId)->listing_off_market_days);
    }

    // ── The nightly job uses the per-agency window ────────────────────────

    public function test_job_flags_by_each_agencys_own_window(): void
    {
        $a = $this->makeAgency();                 // default 90
        $b = $this->makeAgency();
        SuggestedActionThresholds::getOrCreateForAgency($b)->update(['listing_off_market_days' => 30]);

        $a60  = $this->listing($a, ['last_seen_at' => now()->subDays(60)]);
        $a100 = $this->listing($a, ['last_seen_at' => now()->subDays(100)]);
        $b60  = $this->listing($b, ['last_seen_at' => now()->subDays(60)]);
        $b10  = $this->listing($b, ['last_seen_at' => now()->subDays(10)]);

        $this->artisan('prospecting:flag-stale-listings')->assertSuccessful();

        $this->assertSame(1, $this->active($a60), 'agency A (90 days): 60 days unseen stays on — the old 30-day rule would have switched it off');
        $this->assertSame(0, $this->active($a100), 'agency A: 100 days unseen is presumed off-market');
        $this->assertSame(0, $this->active($b60), "agency B set 30 days: 60 days unseen is off");
        $this->assertSame(1, $this->active($b10));
    }

    public function test_days_option_overrides_every_agency(): void
    {
        $a = $this->makeAgency();
        $l = $this->listing($a, ['last_seen_at' => now()->subDays(60)]);

        $this->artisan('prospecting:flag-stale-listings', ['--days' => 30])->assertSuccessful();

        $this->assertSame(0, $this->active($l));
    }

    public function test_explicit_off_market_portal_status_is_still_flagged_regardless_of_the_window(): void
    {
        $a = $this->makeAgency();
        $l = $this->listing($a, ['last_seen_at' => now()->subDays(1), 'portal_status' => 'sold']);

        $this->artisan('prospecting:flag-stale-listings')->assertSuccessful();

        $this->assertSame(0, $this->active($l));
    }

    // ── One-off reactivation of what the old 30-day rule switched off ─────

    public function test_reactivation_switches_back_on_only_job_flagged_rows_inside_the_window(): void
    {
        $a = $this->makeAgency();

        // Flagged by the old nightly job: last seen 60d ago, flagged at 03:50 today.
        $jobFlagged = $this->flagged($a, seen: now()->subDays(60), flaggedAt: now()->setTime(3, 50, 7));
        // Flagged by the job but last seen 120 days ago — outside the window, stays off.
        $tooOld = $this->flagged($a, seen: now()->subDays(120), flaggedAt: now()->setTime(3, 50, 7));
        // Withdrawn by a CAPTURE (status stamped at the moment it was seen, mid-afternoon): stays off.
        $capture = $this->flagged($a, seen: now()->subDays(5), flaggedAt: now()->subDays(5)->setTime(14, 12, 0));
        // Gone from a complete suburb capture at a non-job time: stays off.
        $reconcile = $this->flagged($a, seen: now()->subDays(50), flaggedAt: now()->setTime(11, 0, 0));
        // Explicitly sold: stays off.
        $sold = $this->flagged($a, seen: now()->subDays(60), flaggedAt: now()->setTime(3, 50, 7), status: 'sold');

        $this->artisan('prospecting:reactivate-stale-flagged', ['--dry-run' => true])
            ->expectsOutputToContain('inside the window: 1')->assertSuccessful();
        $this->assertSame(0, $this->active($jobFlagged), 'a dry run writes nothing');

        $this->artisan('prospecting:reactivate-stale-flagged')->assertSuccessful();

        $this->assertSame(1, $this->active($jobFlagged));
        $row = DB::table('prospecting_listings')->find($jobFlagged);
        $this->assertSame('active', $row->portal_status);
        $this->assertNull($row->off_market_at);
        $this->assertNull($row->portal_status_changed_at);
        foreach ([$tooOld, $capture, $reconcile, $sold] as $id) {
            $this->assertSame(0, $this->active($id), "listing {$id} must stay off");
        }
        $this->assertSame('sold', DB::table('prospecting_listings')->find($sold)->portal_status);
    }

    public function test_reactivation_is_idempotent_and_reversible(): void
    {
        $a = $this->makeAgency();
        $id = $this->flagged($a, seen: now()->subDays(60), flaggedAt: now()->setTime(3, 50, 7));
        $before = (array) DB::table('prospecting_listings')->find($id);

        $this->artisan('prospecting:reactivate-stale-flagged')->assertSuccessful();
        $this->assertSame(1, $this->active($id));

        // Second run finds nothing.
        $this->artisan('prospecting:reactivate-stale-flagged')->expectsOutputToContain('inside the window: 0')->assertSuccessful();

        // Reverse from the snapshot restores the exact flagged state.
        $snapshot = collect(File::files(storage_path('app/private/data-backfills')))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), 'stale-listings-reactivated-'))
            ->sortByDesc(fn ($f) => $f->getMTime())->first();
        $this->assertNotNull($snapshot);
        $this->artisan('prospecting:reactivate-stale-flagged', ['--reverse' => $snapshot->getPathname()])->assertSuccessful();

        $after = (array) DB::table('prospecting_listings')->find($id);
        $this->assertSame(0, (int) $after['is_active']);
        $this->assertSame($before['portal_status'], $after['portal_status']);
        $this->assertSame($before['portal_status_changed_at'], $after['portal_status_changed_at']);
        $this->assertSame($before['off_market_at'], $after['off_market_at']);
        File::delete($snapshot->getPathname());
    }

    public function test_reversal_skips_a_row_that_was_re_captured_since(): void
    {
        $a = $this->makeAgency();
        $id = $this->flagged($a, seen: now()->subDays(60), flaggedAt: now()->setTime(3, 50, 7));
        $this->artisan('prospecting:reactivate-stale-flagged')->assertSuccessful();
        $snapshot = collect(File::files(storage_path('app/private/data-backfills')))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), 'stale-listings-reactivated-'))
            ->sortByDesc(fn ($f) => $f->getMTime())->first();

        // A capture sights it again and later it is withdrawn by something else.
        DB::table('prospecting_listings')->where('id', $id)->update(['portal_status' => 'under_offer']);

        $this->artisan('prospecting:reactivate-stale-flagged', ['--reverse' => $snapshot->getPathname()])
            ->expectsOutputToContain('1 skipped')->assertSuccessful();
        $this->assertSame('under_offer', DB::table('prospecting_listings')->find($id)->portal_status);
        File::delete($snapshot->getPathname());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeAgency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @return array{0:int,1:User} */
    private function agencyOwner(): array
    {
        $id = $this->makeAgency();
        $user = User::factory()->create(['agency_id' => $id, 'branch_id' => $id, 'role' => 'super_admin']);

        return [$id, $user];
    }

    private function listing(int $agencyId, array $extra = []): int
    {
        $captor = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $agencyId, 'is_active' => 1, 'captured_by_user_id' => $captor->id,
            'portal_source' => 'p24', 'portal_ref' => 'P24-' . Str::random(8),
            'portal_url' => 'https://www.property24.com/' . Str::random(10),
            'address' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'price' => 1_500_000, 'bedrooms' => 3, 'bathrooms' => 2, 'property_type' => 'House',
            'first_seen_at' => now()->subDays(200), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function flagged(int $agencyId, $seen, $flaggedAt, string $status = 'withdrawn'): int
    {
        return $this->listing($agencyId, [
            'is_active' => 0, 'last_seen_at' => $seen,
            'portal_status' => $status, 'portal_status_changed_at' => $flaggedAt, 'off_market_at' => $flaggedAt,
        ]);
    }

    private function active(int $id): int
    {
        return (int) DB::table('prospecting_listings')->where('id', $id)->value('is_active');
    }
}
