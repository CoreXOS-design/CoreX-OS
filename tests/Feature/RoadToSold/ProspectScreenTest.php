<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v3 - THE SCREEN (road-to-sold.md section 22.3 / 22.4 / 22.9 / 22.11): the three blocks, the pop-up step routes, embed mode,
 * the manager's read-only view and scoping. The page itself is real HTML here (PHPUnit); the pop-up's iframe is checked as the redirect target
 * it opens.
 */
final class ProspectScreenTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    private ProspectService $prospects;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        $this->prospects = app(ProspectService::class);
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true, 'name' => 'Mia Manager']);
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owned(User $agent, string $street, bool $withOwner = true, string $suburb = 'Uvongo'): RtsProspect
    {
        $attrs = ['street_number' => '5', 'street_name' => $street, 'suburb' => $suburb];
        if ($withOwner) {
            $attrs['owner_contact_id'] = Contact::withoutGlobalScopes()->create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
                'first_name' => 'Owner', 'last_name' => $street, 'phone' => '0821234567',
            ])->id;
        }

        return $this->prospects->place($agent, $this->tp($attrs)->id);
    }

    private function page(User $as, array $q = [])
    {
        return $this->actingAs($as)->get(route('road-to-sold.index', $q));
    }

    // ── the three blocks ────────────────────────────────────────────────

    public function test_the_page_has_the_three_blocks_with_counts_and_opens_on_todays_intake(): void
    {
        $p = $this->owned($this->agent, 'Alpha Road');
        $q = $this->owned($this->agent, 'Beta Road');
        $this->prospects->park($q, 'not_selling_now', Carbon::parse('2026-11-20'), null, $this->agent);

        $html = $this->page($this->agent)->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="rts-blocks"', $html);
        foreach (["Today's intake", 'My prospects', 'Parked', 'My progress'] as $label) {
            $this->assertStringContainsString(e($label), $html);
        }
        $this->assertMatchesRegularExpression('/data-block="prospects"[^>]*>My prospects<small>1<\/small>/', $html, 'one live prospect');
        $this->assertMatchesRegularExpression('/data-block="parked"[^>]*>Parked<small>1<\/small>/', $html, 'one parked');
        $this->assertStringContainsString('aria-selected="true" class="is-on" data-block="intake"', $html);
        $this->assertStringContainsString('data-qa="step-modal"', $html, 'the pop-up host is on the page');
    }

    public function test_my_prospects_shows_each_card_with_the_next_action_and_due_date_and_overdue_in_red(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road');
        $b = $this->owned($this->agent, 'Beta Road', false);
        $this->prospects->setNextAction($a, 'Call again', Carbon::parse('2026-10-10'), $this->agent);   // overdue
        $this->prospects->setNextAction($b, 'Do the evaluation', Carbon::parse('2026-10-14'), $this->agent);   // today

        $html = $this->page($this->agent, ['block' => 'prospects'])->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="prospect-board"', $html);
        $this->assertStringContainsString('Get stock', $html);
        $this->assertStringNotContainsString('<section aria-label="Sell stock"', $html, 'a group with no prospects is not drawn');
        $this->assertStringContainsString('<section aria-label="Get stock"', $html);
        $this->assertStringContainsString('5 Alpha Road', $html);
        $this->assertStringContainsString('Next:</span> Call again', $html);
        $this->assertStringContainsString('Overdue - was due Sat 10 Oct', $html);
        $this->assertStringContainsString('data-due="overdue"', $html);
        $this->assertStringContainsString('rts-card is-overdue', $html);
        $this->assertStringContainsString('Due today', $html);
        $this->assertStringContainsString('Owner not known yet', $html);
        // first-time user: the primary button says what to do - Pitch when the owner is known, Find owner when not
        $this->assertMatchesRegularExpression('/data-step-url="[^"]*prospects\/' . $a->id . '\/step\/pitch"/', $html);
        $this->assertMatchesRegularExpression('/data-step-url="[^"]*prospects\/' . $b->id . '\/step\/find_owner"/', $html);
        $this->assertStringContainsString('Find owner', $html);
    }

    public function test_prospects_are_searched_filtered_by_stage_due_and_suburb_and_sorted_by_due(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road', true, 'Uvongo');
        $b = $this->owned($this->agent, 'Beta Road', true, 'Margate');
        $this->prospects->setNextAction($a, 'Call again', Carbon::parse('2026-10-20'), $this->agent);
        $this->prospects->setNextAction($b, 'Follow up', Carbon::parse('2026-10-10'), $this->agent);
        $this->prospects->advance($b->refresh(), S::APPOINTMENT_SET, $this->agent, ['subject_type' => 'x', 'subject_id' => 1, 'next_at' => Carbon::parse('2026-10-10'), 'next_label' => 'Do the evaluation']);

        $order = fn (string $html) => array_map(fn ($m) => $m, array_keys(array_filter([
            'beta' => strpos($html, '5 Beta Road'), 'alpha' => strpos($html, '5 Alpha Road'),
        ], fn ($v) => $v !== false)));
        $html = $this->page($this->agent, ['block' => 'prospects'])->getContent();
        $this->assertLessThan(strpos($html, '5 Alpha Road'), strpos($html, '5 Beta Road'), 'soonest due first');

        $only = fn (array $q, string $has, string $not) => tap($this->page($this->agent, ['block' => 'prospects'] + $q)->assertOk()->getContent(), function ($h) use ($has, $not, $q) {
            $h = substr($h, (int) strpos($h, 'data-qa="block-prospects"'));   // the block itself (the page's own JSON also names today's intake rows)
            $this->assertStringContainsString($has, $h, json_encode($q));
            $pos = strpos($h, $not);
            $this->assertFalse($pos, json_encode($q) . ' ... ' . ($pos === false ? '' : substr($h, max(0, $pos - 300), 420)));
        });
        $only(['p_q' => 'alpha'], '5 Alpha Road', '5 Beta Road');
        $only(['p_stage' => 'appointment_set'], '5 Beta Road', '5 Alpha Road');
        $only(['p_due' => 'overdue'], '5 Beta Road', '5 Alpha Road');
        $only(['p_suburb' => 'Uvongo'], '5 Alpha Road', '5 Beta Road');
        $only(['p_q' => 'zzz nothing'], 'Nothing matches this search.', '5 Alpha Road');
        $this->assertStringContainsString('data-qa="stage-strip"', $html);
    }

    public function test_an_empty_prospects_block_says_what_to_do(): void
    {
        $this->page($this->agent, ['block' => 'prospects'])->assertOk()
            ->assertSee("No prospects yet - they start when you work today's intake.", false)->assertSee("Go to today's intake", false);
    }

    public function test_parked_lists_the_reason_and_revisit_date_and_bring_back_makes_a_follow_up_for_today(): void
    {
        $p = $this->owned($this->agent, 'Parked Road');
        $this->prospects->park($p, 'chose_other_agency', Carbon::parse('2026-10-14')->addDays(30), 'Went with X', $this->agent);

        $this->page($this->agent, ['block' => 'parked'])->assertOk()->assertSee('data-qa="block-parked"', false)
            ->assertSee('Seller chose another agency')->assertSee('Went with X')->assertSee('5 Parked Road')->assertSee('Bring back now');

        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.unpark', $p->id))->assertRedirect();
        $p->refresh();
        $this->assertNull($p->parked_at);
        $this->assertSame('2026-10-14', $p->next_action_at->toDateString(), 'it is a follow-up due today');
        $this->assertSame(0, RtsProspect::query()->parked()->count());
    }

    public function test_setting_aside_needs_a_reason_and_a_future_day_and_changing_the_next_action_saves(): void
    {
        $p = $this->owned($this->agent, 'Alpha Road');
        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.park', $p->id), [])->assertSessionHasErrors('reason');
        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.park', $p->id), ['reason' => 'bogus'])->assertSessionHasErrors('reason');
        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.park', $p->id), ['reason' => 'not_selling_now', 'until' => '2026-10-01'])->assertSessionHasErrors('until');
        $this->assertNull($p->fresh()->parked_at, 'nothing saved on a refusal');

        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.park', $p->id), ['reason' => 'not_selling_now', 'until' => '2026-12-01', 'note' => 'Back in summer'])->assertSessionHasNoErrors();
        $this->assertSame('2026-12-01', $p->fresh()->parked_until->toDateString());

        $q = $this->owned($this->agent, 'Beta Road');
        $this->actingAs($this->agent)->put(route('road-to-sold.prospects.next-action', $q->id), ['label' => '', 'at' => '2026-10-20'])->assertSessionHasErrors('label');
        $this->actingAs($this->agent)->put(route('road-to-sold.prospects.next-action', $q->id), ['label' => 'Send the pitch', 'at' => '2026-10-20'])->assertSessionHasNoErrors();
        $this->assertSame(['Send the pitch', '2026-10-20'], [$q->fresh()->next_action_label, $q->fresh()->next_action_at->toDateString()]);
    }

    public function test_a_hand_capture_moves_a_prospect_forward_only(): void
    {
        $p = $this->owned($this->agent, 'Alpha Road');
        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.capture', $p->id), ['stage' => 'presentation_given', 'next_label' => 'Get the feedback', 'next_at' => '2026-10-21'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(['presentation_given', 'Get the feedback'], [$p->stage, $p->next_action_label]);
        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.capture', $p->id), ['stage' => 'contacted'])->assertSessionHasErrors('stage');
        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.capture', $p->id), ['stage' => 'nonsense'])->assertSessionHasErrors('stage');
        $this->assertSame('presentation_given', $p->fresh()->stage);
    }

    // ── the pop-up routes ────────────────────────────────────────────────

    public function test_every_step_opens_the_existing_screen_in_embed_mode_pre_filled(): void
    {
        $p = $this->owned($this->agent, 'Alpha Road');
        $owner = (int) \DB::table('tracked_properties')->where('id', $p->tracked_property_id)->value('owner_contact_id');

        $go = fn (string $step) => $this->actingAs($this->agent)->get(route('road-to-sold.prospects.step', [$p->id, $step]));
        // find owner and pitch are ONE answer, the pitch path (lane D): the composer when the owner is known, otherwise Find owner
        foreach (['pitch', 'find_owner'] as $s) {
            $loc = $go($s)->assertRedirect()->headers->get('Location');
            $this->assertStringContainsString("/road-to-sold/prospects/{$p->id}/pitch-path", $loc);
            $this->assertStringContainsString('embed=1', $loc);
            $this->assertStringContainsString('rts_prospect=' . $p->id, $loc);
        }
        $this->assertStringContainsString('/presentations/create', $go('presentation')->headers->get('Location'));
        $this->assertStringContainsString('/deals-dr2/create', $go('deal')->headers->get('Location'));
        $this->assertStringContainsString('/corex/core-matches', $go('core_match')->headers->get('Location'));
        $this->assertStringContainsString('/corex/viewing-packs', $go('viewing_pack')->headers->get('Location'));
        $go('no_such_step')->assertNotFound();
    }

    public function test_a_colleagues_prospect_is_a_404_and_a_manager_cannot_work_one(): void
    {
        $mine = $this->owned($this->agent, 'Alpha Road');
        $theirs = $this->owned($this->mate, 'Mate Road');
        // own breadth: a colleague's prospect does not exist for me
        $this->actingAs($this->agent)->get(route('road-to-sold.prospects.step', [$theirs->id, 'pitch']))->assertNotFound();
        $this->actingAs($this->agent)->post(route('road-to-sold.prospects.park', $theirs->id), ['reason' => 'not_selling_now'])->assertNotFound();
        $this->actingAs($this->agent)->get(route('road-to-sold.prospects.history', $theirs->id))->assertNotFound();
        // a manager can look (history) but every write and every step is refused
        $this->actingAs($this->manager)->get(route('road-to-sold.prospects.history', $mine->id))->assertOk()->assertSee('History');
        $this->actingAs($this->manager)->get(route('road-to-sold.prospects.step', [$mine->id, 'pitch']))->assertForbidden();
        $this->actingAs($this->manager)->post(route('road-to-sold.prospects.park', $mine->id), ['reason' => 'not_selling_now'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('road-to-sold.prospects.unpark', $mine->id))->assertForbidden();
        $this->actingAs($this->manager)->put(route('road-to-sold.prospects.next-action', $mine->id), ['label' => 'x'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('road-to-sold.prospects.capture', $mine->id), ['stage' => 'contacted'])->assertForbidden();
        $this->assertNull($mine->fresh()->parked_at);
    }

    public function test_another_agencys_prospect_is_a_404_even_for_an_admin_of_this_one(): void
    {
        $other = Agency::create(['name' => 'Other Realty', 'slug' => 'other-' . uniqid()]);
        $branch = \DB::table('branches')->insertGetId(['agency_id' => $other->id, 'name' => 'Theirs', 'created_at' => now(), 'updated_at' => now()]);
        $stranger = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch, 'role' => 'agent', 'is_active' => true]);
        $tp = $this->tp(['street_number' => '9', 'street_name' => 'Far Road'], $other);
        $theirs = RtsProspect::withoutGlobalScopes()->create(['agency_id' => $other->id, 'branch_id' => $branch, 'track' => 'sales', 'user_id' => $stranger->id,
            'tracked_property_id' => $tp->id, 'stage' => 'found', 'stage_entered_at' => now()]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => 'all']);
        PermissionService::clearCache();
        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->get(route('road-to-sold.prospects.history', $theirs->id))->assertNotFound();
    }

    // ── the manager's read-only view (R9) ───────────────────────────────

    public function test_a_manager_sees_an_agents_prospects_read_only_with_per_stage_counts(): void
    {
        $p = $this->owned($this->agent, 'Alpha Road');
        $this->prospects->advance($p->refresh(), S::EVALUATION_DONE, $this->agent, ['subject_type' => 'x', 'subject_id' => 2, 'next_label' => 'Give the presentation']);

        $html = $this->page($this->manager, ['block' => 'prospects', 'agent' => $this->agent->id])->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="read-only"', $html);
        $this->assertStringContainsString('5 Alpha Road', $html);
        $this->assertStringContainsString('data-qa="stage-counts"', $html, 'per-stage counts');
        $this->assertStringContainsString('On track', $html);
        $this->assertStringNotContainsString('data-qa="prospect-step"', $html, 'no step buttons for a manager');
        $this->assertStringNotContainsString('@click="openPark(', $html);
        $this->assertStringNotContainsString('@click="openNext(', $html);
        $this->assertStringNotContainsString('Record a result', $html);
        // the agent's own page has no manager table
        $own = $this->page($this->agent, ['block' => 'prospects'])->getContent();
        $this->assertStringNotContainsString('data-qa="stage-counts"', $own);
        $this->assertStringContainsString('data-qa="prospect-step"', $own);
    }

    public function test_a_manager_sees_the_parked_pile_across_agents_grouped_by_reason(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road');
        $b = $this->owned($this->mate, 'Beta Road');
        $this->prospects->park($a, 'not_selling_now', Carbon::parse('2026-12-01'), null, $this->agent);
        $this->prospects->park($b, 'not_selling_now', Carbon::parse('2026-12-02'), null, $this->mate);

        $html = $this->page($this->manager, ['block' => 'parked', 'who' => 'all', 'agent' => $this->agent->id])->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="parked-by-reason"', $html);
        $this->assertMatchesRegularExpression('/Not selling now 2/', $html);
        $this->assertStringContainsString('5 Alpha Road', $html);
        $this->assertStringContainsString('5 Beta Road', $html);
        $this->assertStringNotContainsString('Bring back now', $html, 'a manager cannot bring one back');
    }

    // ── embed mode ───────────────────────────────────────────────────────

    public function test_embed_mode_removes_the_chrome_and_adds_the_done_bar_and_nothing_else_changes(): void
    {
        $normal = $this->page($this->agent)->assertOk()->getContent();
        $this->assertStringContainsString('<aside', $normal);
        $this->assertStringNotContainsString('corex-embed-bar', $normal);

        $embedded = $this->page($this->agent, ['embed' => 1])->assertOk()->getContent();
        $this->assertStringNotContainsString('<aside', $embedded, 'no sidebar in the pop-up');
        $this->assertStringContainsString('id="corex-embed-bar"', $embedded);
        $this->assertStringContainsString('data-embed-done', $embedded);
        $this->assertStringContainsString('id="appScroll"', $embedded, 'the content is still there');

        // a frame request keeps it chrome-less across a save + redirect, without the parameter
        $framed = $this->actingAs($this->agent)->withHeader('Sec-Fetch-Dest', 'iframe')->get(route('road-to-sold.index'))->assertOk()->getContent();
        $this->assertStringContainsString('id="corex-embed-bar"', $framed);
        $this->assertStringNotContainsString('<aside', $framed);
    }

    // ── today's intake: the reason leads, the step opens a pop-up ───────

    public function test_the_intake_table_leads_with_the_reason_and_marks_follow_ups(): void
    {
        $html = $this->page($this->agent)->assertOk()->getContent();
        $why = strpos($html, 'Why - start here');
        $addr = strpos($html, '<th class="px-3 py-2 text-left">Address</th>');
        $this->assertNotFalse($why);
        $this->assertLessThan($addr, $why, 'the reason column comes before the address');
        $this->assertStringNotContainsString('Why suggested', $html);
    }
}
