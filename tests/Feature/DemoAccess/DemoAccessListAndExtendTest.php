<?php

namespace Tests\Feature\DemoAccess;

use App\Events\Demo\DemoAccessExtended;
use App\Models\DemoAccessGrant;
use App\Models\DemoPageView;
use App\Models\DemoSession;
use App\Models\DemoTncVersion;
use App\Models\Role;
use App\Models\User;
use App\Support\DemoAccessListing;
use Database\Seeders\DemoTncVersionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Demo Access list (views / filters / sort / date range / pagination / header)
 * and "Add time" (extend a grant without issuing a new one).
 *
 * Spec: .ai/specs/demo-access-control.md §9, §9.1, §9.2, §11 (R21–R32)
 *
 * Time is frozen so "hot", "expiring soon" and "went quiet" are deterministic.
 */
class DemoAccessListAndExtendTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $agencyAdmin;
    private string $hash;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow('2026-10-07 12:00:00');

        $this->seed(DemoTncVersionSeeder::class);

        $ownerRole = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();

        $this->owner = User::factory()->create(['role' => 'super_admin', 'name' => 'Johan Reichel', 'agency_id' => null]);

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Agency Admin', 'sort_order' => 2]);
        Role::clearCache();
        $this->agencyAdmin = User::factory()->create(['role' => 'admin', 'name' => 'Agency Admin']);

        $this->hash = DemoAccessGrant::hashCode('AAAA-BBBB-CCCC-DDDD');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function grant(string $company, array $attrs = []): DemoAccessGrant
    {
        return DemoAccessGrant::create(array_merge([
            'company_name'      => $company,
            'contact_email'     => Str::slug($company) . '@example.test',
            'credential_hash'   => $this->hash,
            'expiry_hours'      => 72,
            'issued_by_user_id' => $this->owner->id,
        ], $attrs));
    }

    /** A signed-in grant: first login in the past, end date in the future by default. */
    private function active(string $company, string $expires = '+5 days', array $attrs = []): DemoAccessGrant
    {
        return $this->grant($company, array_merge([
            'first_login_at' => Carbon::now()->subDays(2),
            'expires_at'     => Carbon::parse($expires),
        ], $attrs));
    }

    private function seen(DemoAccessGrant $g, string $lastSeen, int $pageViews = 0, ?string $viewedAt = null): DemoSession
    {
        $s = DemoSession::create([
            'demo_access_grant_id' => $g->id,
            'session_token'        => (string) Str::uuid(),
            'started_at'           => Carbon::parse($lastSeen)->subMinutes(5),
            'last_seen_at'         => Carbon::parse($lastSeen),
        ]);
        for ($i = 0; $i < $pageViews; $i++) {
            DemoPageView::create([
                'demo_session_id' => $s->id,
                'path'            => '/corex/properties',
                'viewed_at'       => $viewedAt ? Carbon::parse($viewedAt) : Carbon::parse($lastSeen),
            ]);
        }

        return $s;
    }

    private function extend(DemoAccessGrant $g, $days, ?string $token = null, ?string $note = null)
    {
        return $this->actingAs($this->owner)->post(route('admin.demo-access.extend', $g), array_filter([
            'days'  => $days,
            'token' => $token ?? Str::random(32),
            'note'  => $note,
        ], fn ($v) => $v !== null));
    }

    /** The standard seven-grant scenario, one per list view. */
    private function scenario(): array
    {
        $hot   = $this->active('Hot Co', '+5 days');
        $this->seen($hot, '2026-10-07 10:00:00', 2);

        $soon  = $this->active('Soon Co', '+20 hours');
        $this->seen($soon, '2026-10-06 00:00:00', 1);

        $quiet = $this->active('Quiet Co', '+6 days');
        $this->seen($quiet, '2026-10-02 12:00:00', 1);

        $unused = $this->grant('Unused Co', ['contact_name' => 'Thandi Zulu']);

        $expired = $this->grant('Expired Co', [
            'first_login_at' => Carbon::now()->subDays(10),
            'expires_at'     => Carbon::now()->subDays(2),
        ]);
        $this->seen($expired, '2026-09-28 12:00:00', 1);

        $revoked = $this->active('Revoked Co', '+4 days', ['revoked_at' => Carbon::now()->subDay()]);

        $archived = $this->grant('Archived Co', ['archived_at' => Carbon::now()->subDay()]);

        return compact('hot', 'soon', 'quiet', 'unused', 'expired', 'revoked', 'archived');
    }

    private function listing(string $query = ''): array
    {
        return $this->actingAs($this->owner)
            ->get(route('admin.demo-access.index') . $query)
            ->assertOk()
            ->viewData('listing');
    }

    private function companies(array $listing): array
    {
        return collect($listing['rows']->items())->pluck('company')->all();
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  LIST
    // ═══════════════════════════════════════════════════════════════════════

    /** R21 — every view's count equals what clicking it shows. */
    public function test_each_view_counts_exactly_what_it_lists(): void
    {
        $this->scenario();

        $l = $this->listing();
        $counts = collect($l['views'])->map(fn ($v) => $v['count'])->all();

        $this->assertSame(
            ['all' => 6, 'hot' => 1, 'soon' => 1, 'quiet' => 1, 'unused' => 1, 'ended' => 2, 'archived' => 1],
            $counts
        );

        $this->assertSame(['Hot Co'], $this->companies($this->listing('?view=hot')));
        $this->assertSame(['Soon Co'], $this->companies($this->listing('?view=soon')));
        $this->assertSame(['Quiet Co'], $this->companies($this->listing('?view=quiet')));
        $this->assertSame(['Unused Co'], $this->companies($this->listing('?view=unused')));
        $this->assertEqualsCanonicalizing(['Expired Co', 'Revoked Co'], $this->companies($this->listing('?view=ended')));
        $this->assertSame(['Archived Co'], $this->companies($this->listing('?view=archived')));
    }

    /** R22 — archived grants live only in their own view; the old ?archived=1 link still works. */
    public function test_archived_grants_only_appear_in_the_archived_view(): void
    {
        $this->scenario();

        $this->assertNotContains('Archived Co', $this->companies($this->listing()));
        $this->assertSame(['Archived Co'], $this->companies($this->listing('?archived=1')));
        $this->assertDatabaseHas('demo_access_grants', ['company_name' => 'Archived Co']);
    }

    /** R23 — the hide switches shape the counts too, so a view never promises rows it cannot show. */
    public function test_hide_not_used_and_hide_ended_filter_the_list_and_the_counts(): void
    {
        $this->scenario();

        $l = $this->listing('?hide_unused=1');
        $this->assertSame(5, $l['views']['all']['count']);
        $this->assertSame(0, $l['views']['unused']['count']);
        $this->assertNotContains('Unused Co', $this->companies($l));

        $l = $this->listing('?hide_ended=1');
        $this->assertSame(4, $l['views']['all']['count']);
        $this->assertSame(0, $l['views']['ended']['count']);
        $this->assertNotContains('Expired Co', $this->companies($l));
        $this->assertNotContains('Revoked Co', $this->companies($l));
    }

    /** R24 — default order is most recent activity; never-opened grants sink, newest invite first. */
    public function test_default_sort_is_most_recent_activity_with_never_opened_last(): void
    {
        $this->scenario();

        $this->assertSame(
            ['Hot Co', 'Soon Co', 'Quiet Co', 'Expired Co', 'Revoked Co', 'Unused Co'],
            $this->companies($this->listing())
        );
        $this->assertSame(
            ['Expired Co', 'Hot Co', 'Quiet Co', 'Revoked Co', 'Soon Co', 'Unused Co'],
            $this->companies($this->listing('?sort=company'))
        );
        // Expiring soonest: Soon Co (20 h) first, those with no running end date last.
        $this->assertSame('Soon Co', $this->companies($this->listing('?sort=expiry'))[0]);
        // Most pages viewed: Hot Co has 2, the one-pagers follow.
        $this->assertSame('Hot Co', $this->companies($this->listing('?sort=pages'))[0]);
    }

    public function test_search_matches_company_contact_name_and_email_and_survives_wildcards(): void
    {
        $this->scenario();

        $this->assertSame(['Unused Co'], $this->companies($this->listing('?q=thandi')));
        $this->assertSame(['Hot Co'], $this->companies($this->listing('?q=hot-co@example')));
        $this->assertSame([], $this->companies($this->listing('?q=' . urlencode('%'))));
    }

    public function test_issued_date_range_filters_the_list_and_ignores_garbage_dates(): void
    {
        $s = $this->scenario();
        $s['hot']->forceFill(['created_at' => '2026-09-01 09:00:00'])->save();

        $this->assertNotContains('Hot Co', $this->companies($this->listing('?issued_from=2026-10-01')));
        $this->assertSame(['Hot Co'], $this->companies($this->listing('?issued_to=2026-09-30')));
        // Nonsense is dropped, not a 500.
        $this->assertContains('Hot Co', $this->companies($this->listing('?issued_from=banana&issued_to=99-99-99')));
    }

    public function test_the_list_paginates_at_24_a_page(): void
    {
        foreach (range(1, 30) as $i) {
            $this->grant("Bulk {$i}");
        }

        $p1 = $this->listing();
        $this->assertSame(24, count($p1['rows']->items()));
        $this->assertSame(30, $p1['rows']->total());
        $this->assertSame(6, count($this->listing('?page=2')['rows']->items()));
    }

    public function test_an_unknown_view_or_sort_falls_back_instead_of_failing(): void
    {
        $this->scenario();

        $l = $this->listing('?view=nonsense&sort=nonsense');
        $this->assertSame('all', $l['filters']['view']);
        $this->assertSame('recent', $l['filters']['sort']);
    }

    /** R25 — page views per day, oldest first, today last. */
    public function test_the_sparkline_counts_page_views_per_day(): void
    {
        $g = $this->active('Spark Co');
        $this->seen($g, '2026-10-07 09:00:00', 3, '2026-10-07 09:00:00');
        $this->seen($g, '2026-10-05 09:00:00', 1, '2026-10-05 09:00:00');
        $this->seen($g, '2026-09-01 09:00:00', 4, '2026-09-01 09:00:00');   // outside the 14 days

        $spark = DemoAccessListing::sparklines([$g->id])[$g->id];

        $this->assertCount(14, $spark);
        $this->assertSame(3, $spark[13]);
        $this->assertSame(1, $spark[11]);
        $this->assertSame(4, array_sum($spark));
    }

    /** The header is the flat Properties bar, not the old branded banner — and Add time is on the card. */
    public function test_the_page_uses_the_flat_header_and_offers_add_time(): void
    {
        $this->scenario();

        $this->actingAs($this->owner)->get(route('admin.demo-access.index'))
            ->assertOk()
            ->assertDontSee('corex-page-banner', false)
            ->assertSee('Demo Access')
            ->assertSee('Add time')
            ->assertSee('Terms version')
            ->assertSee('Demo not connected');   // no connector in the test DB — said loudly
    }

    public function test_an_empty_system_shows_the_first_grant_prompt_not_an_empty_grid(): void
    {
        $this->actingAs($this->owner)->get(route('admin.demo-access.index'))
            ->assertOk()
            ->assertSee('No demo grants yet')
            ->assertSee('Issue the first grant');
    }

    public function test_a_filtered_empty_list_says_so_and_offers_to_clear(): void
    {
        $this->scenario();

        $this->actingAs($this->owner)->get(route('admin.demo-access.index') . '?q=zzzznomatch')
            ->assertOk()
            ->assertSee('No grants in this view')
            ->assertSee('Clear all filters');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ADD TIME
    // ═══════════════════════════════════════════════════════════════════════

    /** R26 — not started: the trial they GET at first sign-in grows; no end date is invented. */
    public function test_adding_time_before_first_sign_in_lengthens_the_trial(): void
    {
        $g = $this->grant('Not Started Co');   // 72 h, expires_at NULL

        $this->extend($g, 3)->assertSessionHasNoErrors();

        $g->refresh();
        $this->assertSame(72 + 72, $g->expiry_hours);
        $this->assertNull($g->expires_at);
        $this->assertSame('pending', $g->status());
        $this->assertStringContainsString('Their trial is now 6 days', session('status'));
    }

    /** R27 — still running: the existing end date moves on. */
    public function test_adding_time_to_a_running_grant_moves_the_end_date_on(): void
    {
        $g = $this->active('Running Co', '+10 hours');

        $this->extend($g, 2);

        $this->assertSame('2026-10-09 22:00:00', $g->fresh()->expires_at->format('Y-m-d H:i:s'));   // +10 h, +48 h
        $this->assertSame('active', $g->fresh()->status());
    }

    /** R28 — already ended: access restarts from NOW; adding to a past date would change nothing. */
    public function test_adding_time_to_an_expired_grant_restarts_from_now(): void
    {
        $g = $this->grant('Lapsed Co', [
            'first_login_at' => Carbon::now()->subDays(10),
            'expires_at'     => Carbon::now()->subDays(5),
        ]);
        $this->assertSame('expired', $g->status());

        $this->extend($g, 3);

        $g->refresh();
        $this->assertSame('2026-10-10 12:00:00', $g->expires_at->format('Y-m-d H:i:s'));   // now + 72 h, NOT old date + 72 h
        $this->assertSame('active', $g->status());
        $this->assertTrue($g->isUsable());
    }

    /** R29 — a fixed-deadline (webinar) grant extends its deadline, still one clock. */
    public function test_a_fixed_deadline_grant_keeps_a_single_clock_when_extended(): void
    {
        $g = $this->grant('Webinar Co', [
            'expiry_hours'   => null,
            'first_login_at' => Carbon::now()->subDay(),
            'expires_at'     => Carbon::now()->addDays(2),
        ]);

        $this->extend($g, 1);

        $g->refresh();
        $this->assertNull($g->expiry_hours);
        $this->assertSame('2026-10-10 12:00:00', $g->expires_at->format('Y-m-d H:i:s'));
    }

    /** R30 — a revoked grant stays revoked: extending would silently undo a deliberate withdrawal. */
    public function test_a_revoked_grant_cannot_be_extended(): void
    {
        $g = $this->active('Withdrawn Co', '+1 day', ['revoked_at' => Carbon::now()]);
        $before = $g->expires_at->toDateTimeString();

        $this->extend($g, 7)->assertSessionHasErrors('extend');

        $this->assertSame($before, $g->fresh()->expires_at->toDateTimeString());
        $this->assertSame(0, DB::table('domain_event_log')->where('event_name', DemoAccessExtended::class)->count());
        $this->assertStringContainsString('revoked', session('errors')->first('extend'));
    }

    public function test_an_archived_grant_cannot_be_extended_until_restored(): void
    {
        $g = $this->active('Filed Co', '+1 day', ['archived_at' => Carbon::now()]);

        $this->extend($g, 7)->assertSessionHasErrors('extend');
        $this->assertStringContainsString('Restore it first', session('errors')->first('extend'));
    }

    /** R31 — validation: nothing changes, and a typo does not burn the token. */
    public function test_bad_input_is_refused_and_a_typo_does_not_consume_the_token(): void
    {
        $g = $this->active('Typo Co', '+1 day');
        $before = $g->expires_at->toDateTimeString();
        $token = Str::random(32);

        foreach ([0, -3, 366, 'abc', '', null] as $bad) {
            $this->extend($g, $bad, $token)->assertSessionHasErrors('days');
        }
        $this->actingAs($this->owner)->post(route('admin.demo-access.extend', $g), ['days' => 2])->assertSessionHasErrors('token');
        $this->assertSame($before, $g->fresh()->expires_at->toDateTimeString());

        // The same token still works once the input is fixed.
        $this->extend($g, 2, $token)->assertSessionHasNoErrors();
        $this->assertNotSame($before, $g->fresh()->expires_at->toDateTimeString());
    }

    /** R32 — a double-click or resubmitted form applies ONCE. */
    public function test_a_double_submit_adds_time_only_once(): void
    {
        $g = $this->active('Twice Co', '+1 day');
        $token = Str::random(32);

        $this->extend($g, 3, $token);
        $afterFirst = $g->fresh()->expires_at->toDateTimeString();

        $this->extend($g, 3, $token);
        $this->assertSame($afterFirst, $g->fresh()->expires_at->toDateTimeString());
        $this->assertStringContainsString('already applied', session('status'));
        $this->assertSame(1, DB::table('domain_event_log')->where('event_name', DemoAccessExtended::class)->count());
    }

    public function test_the_one_year_ceiling_is_enforced_and_frees_the_token(): void
    {
        $g = $this->active('Long Co', '+360 days');
        $token = Str::random(32);

        $this->extend($g, 30, $token)->assertSessionHasErrors('extend');
        $this->assertStringContainsString('more than a year', session('errors')->first('extend'));

        // Nothing was applied, so the token is free for a smaller, valid request.
        $this->extend($g, 2, $token)->assertSessionHasNoErrors();
    }

    /** Every applied extension leaves an audit row naming who, what and where it landed. */
    public function test_an_extension_is_recorded_in_the_audit_log(): void
    {
        $g = $this->active('Audited Co', '+1 day');

        $this->extend($g, 7, null, 'Wants to show their principal');

        $row = DB::table('domain_event_log')->where('event_name', DemoAccessExtended::class)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->owner->id, (int) $row->actor_user_id);
        $this->assertSame($g->id, (int) $row->subject_id);

        $ctx = json_decode($row->context, true);
        $this->assertSame(168, $ctx['hours_added']);
        $this->assertSame('from_deadline', $ctx['basis']);
        $this->assertSame('Wants to show their principal', $ctx['note']);
        $this->assertNull($row->agency_id);   // system-owner event, never tenant-scoped
    }

    public function test_the_grant_page_lists_time_added_and_hides_add_time_for_a_revoked_grant(): void
    {
        $g = $this->active('History Co', '+1 day');
        $this->extend($g, 3, null, 'Needs the weekend');

        $this->actingAs($this->owner)->get(route('admin.demo-access.show', $g))
            ->assertOk()
            ->assertSee('Time added')
            ->assertSee('+3 days')
            ->assertSee('Needs the weekend')
            ->assertSee('Johan Reichel')
            ->assertSee('Add time');

        $r = $this->active('Gone Co', '+1 day', ['revoked_at' => Carbon::now()]);
        $this->actingAs($this->owner)->get(route('admin.demo-access.show', $r))
            ->assertOk()
            ->assertDontSee('Add time');
    }

    public function test_only_an_owner_can_add_time(): void
    {
        $g = $this->active('Guarded Co', '+1 day');
        $before = $g->expires_at->toDateTimeString();

        // A guest is sent to sign in.
        $this->post(route('admin.demo-access.extend', $g), ['days' => 3, 'token' => Str::random(32)])->assertRedirect();

        // An agency admin — the most privileged non-owner role — is refused.
        $this->actingAs($this->agencyAdmin)
            ->post(route('admin.demo-access.extend', $g), ['days' => 3, 'token' => Str::random(32)])
            ->assertForbidden();

        $this->assertSame($before, $g->fresh()->expires_at->toDateTimeString());
    }

    /** Extending keeps the code and the accepted terms — that is the point of not issuing a new grant. */
    public function test_extending_keeps_the_code_and_the_terms_acceptance(): void
    {
        $g = $this->active('Same Code Co', '+1 day');
        $version = DemoTncVersion::current();
        $g->acceptances()->create([
            'demo_tnc_version_id' => $version->id,
            'accepted_at'         => Carbon::now()->subDay(),
            'ip_address'          => '127.0.0.1',
        ]);
        $hashBefore = $g->credential_hash;

        $this->extend($g, 7);

        $g->refresh();
        $this->assertSame($hashBefore, $g->credential_hash);
        $this->assertTrue($g->hasAcceptedCurrentTnc());
        $this->assertSame(1, $g->acceptances()->count());
    }
}
