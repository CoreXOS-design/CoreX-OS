<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\MicAgencySetting;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** mic-work-tab.md 13.2: the top filter bar replaces the left column, filters live in the URL and are remembered per user, the Adverts | List switch is remembered. */
final class MicWorkFilterBarTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function advert(string $ref, array $extra = []): ProspectingListing
    {
        return ProspectingListing::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'portal_source' => 'p24', 'portal_ref' => $ref, 'portal_url' => 'https://p.test/' . $ref, 'address' => '1 ' . $ref . ' Road', 'suburb' => 'Margate',
            'property_type' => 'House', 'price' => 1_000_000, 'bedrooms' => 3, 'captured_by_user_id' => $this->admin->id, 'first_seen_at' => now()->subDays(10), 'last_seen_at' => now(), 'is_active' => true,
        ], $extra));
    }

    private function page(array $q = []): string
    {
        return $this->actingAs($this->admin)->get(route('market-intelligence.work', $q))->assertOk()->getContent();
    }

    public function test_the_bar_replaces_the_left_column_and_keeps_every_old_group_under_more_filters(): void
    {
        $this->advert('P24-150000001');
        $html = $this->page();
        foreach (['filter-bar', 'filter-search', 'filter-suburb', 'filter-type', 'filter-beds', 'more-filters-toggle', 'filter-clear', 'filter-sort', 'layout-toggle', 'layout-adverts', 'layout-list'] as $qa) {
            $this->assertStringContainsString('data-qa="' . $qa . '"', $html, $qa);
        }
        $this->assertStringContainsString('class="mi-filter-bar-more', $html);
        $this->assertStringContainsString('Prospect for buyer', $html, 'the old groups are still there, under More filters');
        $this->assertStringContainsString('data-qa="more-show_status"', $html);
        $this->assertStringContainsString('data-qa="more-owner_only"', $html);
        $this->assertStringContainsString('data-qa="more-sole_mandate_only"', $html);
        $this->assertStringContainsString('mic-slot-buyer-legend', $html);
        foreach (['Price ↓', 'Newest', 'Days on market', 'Biggest price cut', 'Most buyers'] as $label) {
            $this->assertStringContainsString($label, $html, "sort: {$label}");
        }
        $this->assertSame(1, substr_count($html, 'id="mi-sort-select"'), 'one sort control only');
    }

    public function test_the_new_filters_narrow_the_list_and_show_as_chips(): void
    {
        $sub = DB::table('p24_suburbs')->insertGetId(['name' => 'Uvongo', 'slug' => 'uvongo', 'p24_id' => 988001, 'created_at' => now(), 'updated_at' => now()]);
        $this->advert('P24-150000010', ['suburb' => 'Uvongo', 'p24_suburb_id' => $sub]);
        $this->advert('P24-150000011', ['suburb' => 'Margate']);
        $this->advert('P24-150000012', ['advertiser_type' => 'owner']);
        $this->advert('P24-150000013');
        DB::table('prospecting_listings')->where('portal_ref', 'P24-150000013')->update(['description_snippet' => 'Sole mandate - lovely sea view home', 'sole_mandate_wording' => 1]);
        $this->advert('P24-150000014', ['is_active' => false, 'portal_status' => 'sold']);
        $tpFor = fn (string $ref, string $no) => app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, ['street_number' => $no, 'street_name' => 'Claim Road', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => $ref]);
        $a = $tpFor('P24-150000015', '1');
        $b = $tpFor('P24-150000016', '2');
        $this->advert('P24-150000015', ['tracked_property_id' => $a->id]);
        $this->advert('P24-150000016', ['tracked_property_id' => $b->id]);
        foreach ([[$a, $this->admin], [$b, $this->other]] as [$f, $u]) {
            DB::table('tracked_property_agency_work')->insert(['agency_id' => $this->agency->id, 'tracked_property_id' => $f->id, 'claimed_by_user_id' => $u->id, 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $has = fn (string $html, string $ref) => str_contains($html, $ref);

        $h = $this->page(['suburbs' => [$sub]]);
        $this->assertTrue($has($h, 'P24-150000010') && ! $has($h, 'P24-150000011'), 'suburb multi-select');
        $this->assertStringContainsString('Suburb · Uvongo', $h);

        $h = $this->page(['owner_only' => 1]);
        $this->assertTrue($has($h, 'P24-150000012') && ! $has($h, 'P24-150000011'), 'owner advertised');
        $this->assertStringContainsString('Owner advertised', $h);

        $h = $this->page(['sole_mandate_only' => 1]);
        $this->assertTrue($has($h, 'P24-150000013') && ! $has($h, 'P24-150000011'), 'sole mandate');

        $this->assertFalse($has($this->page(['p24' => 1]), 'P24-150000014'), 'sold adverts are hidden by default');
        $h = $this->page(['show_status' => 'all']);
        $this->assertTrue($has($h, 'P24-150000014'), 'show under offer / sold brings them in');
        $this->assertStringContainsString('Under offer / sold shown', $h);

        $h = $this->page(['claimed' => 'mine']);
        $this->assertTrue($has($h, 'P24-150000015') && ! $has($h, 'P24-150000016') && ! $has($h, 'P24-150000011'), 'claimed by me');
        $h = $this->page(['claimed' => 'others']);
        $this->assertTrue($has($h, 'P24-150000016') && ! $has($h, 'P24-150000015'), 'claimed by others');
        $h = $this->page(['claimed' => 'unclaimed']);
        $this->assertTrue($has($h, 'P24-150000011') && ! $has($h, 'P24-150000015') && ! $has($h, 'P24-150000016'), 'unclaimed');
    }

    public function test_the_last_filter_set_and_the_view_are_remembered_per_user_and_clear_forgets_them(): void
    {
        $this->advert('P24-150000020');
        $this->advert('P24-150000021', ['advertiser_type' => 'owner']);
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['owner_only' => 1, 'view' => 'list']))->assertOk();
        $row = DB::table('mic_user_preferences')->where('user_id', $this->admin->id)->first();
        $this->assertSame('list', $row->view);
        $this->assertSame(['owner_only' => '1'], json_decode($row->filters, true));

        // opened with no filters: the last set applies and shows as a chip
        $h = $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->getContent();
        $this->assertStringContainsString('P24-150000021', $h);
        $this->assertStringNotContainsString('P24-150000020', $h);
        $this->assertStringContainsString('Owner advertised', $h);
        // another user is not affected
        $h = $this->actingAs($this->other)->get(route('market-intelligence.work'))->assertOk()->getContent();
        $this->assertStringContainsString('P24-150000020', $h);

        // Clear forgets the set
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['clear' => 1]))->assertOk();
        $this->assertNull(DB::table('mic_user_preferences')->where('user_id', $this->admin->id)->value('filters'));
        $this->assertStringContainsString('P24-150000020', $this->actingAs($this->admin)->get(route('market-intelligence.work'))->getContent());
    }

    public function test_the_view_defaults_to_the_agency_setting_and_the_users_own_choice_wins(): void
    {
        $this->assertSame('adverts', MicAgencySetting::for($this->agency->id)->defaultView());
        $this->assertMatchesRegularExpression('/data-qa="layout-adverts"[^>]*aria-pressed="true"/', $this->page());
        MicAgencySetting::saveValues($this->agency->id, ['default_view' => 'list']);
        $this->assertMatchesRegularExpression('/data-qa="layout-list"[^>]*aria-pressed="true"/', $this->page());
        $this->page(['view' => 'adverts']);
        $this->assertMatchesRegularExpression('/data-qa="layout-adverts"[^>]*aria-pressed="true"/', $this->page());
    }

    public function test_the_new_sorts_order_by_price_age_cut_and_buyers(): void
    {
        $tp = fn (string $ref, string $no) => app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, ['street_number' => $no, 'street_name' => 'Sort Road', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => $ref]);
        $a = $tp('P24-150000030', '1');
        $b = $tp('P24-150000031', '2');
        $this->advert('P24-150000030', ['address' => '1 Sort Road', 'price' => 900_000, 'first_seen_at' => now()->subDays(100), 'tracked_property_id' => $a->id]);
        $this->advert('P24-150000031', ['address' => '2 Sort Road', 'price' => 2_000_000, 'first_seen_at' => now()->subDays(3), 'tracked_property_id' => $b->id]);
        DB::table('mic_file_stats')->insert(['agency_id' => $this->agency->id, 'tracked_property_id' => $a->id, 'cut_amount' => 50000, 'buyers_strong' => 1, 'computed_at' => now()]);
        DB::table('mic_file_stats')->insert(['agency_id' => $this->agency->id, 'tracked_property_id' => $b->id, 'cut_amount' => 400000, 'buyers_strong' => 9, 'computed_at' => now()]);
        $first = fn (array $q) => (preg_match('/(\d) Sort Road/', strip_tags($this->page($q)), $m) ? (int) $m[1] : 0);

        $this->assertSame(2, $first(['sort' => 'price', 'dir' => 'desc']), 'Price down: the dearer first');
        $this->assertSame(1, $first(['sort' => 'price', 'dir' => 'asc']), 'Price up');
        $this->assertSame(2, $first(['sort' => 'first_seen_at', 'dir' => 'desc']), 'Newest');
        $this->assertSame(1, $first(['sort' => 'first_seen_at', 'dir' => 'asc']), 'Days on market: the oldest first');
        $this->assertSame(2, $first(['sort' => 'cut', 'dir' => 'desc']), 'Biggest price cut');
        $this->assertSame(2, $first(['sort' => 'buyers', 'dir' => 'desc']), 'Most buyers');
    }
}
