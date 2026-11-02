<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * mic-work-tab.md 13.21 - the "Link a deed" picker on the seller-outreach compose screen is LIVE: fetched when it opens and on each search keystroke,
 * newest captures first, this user's own captures of the last 24 h on top ("Captured just now"), a likely match for the property's complex and unit.
 * Johan, 2 Nov: a deed captured in CMA while the compose tab was open was not in the picker until a page refresh.
 */
final class LiveDeedPickerTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $me;
    private User $mate;
    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->me = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'name' => 'Johan Reichel']);
        $this->mate = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'name' => 'Colleague']);

        // the property being composed: unit 6 of "Uvongo Cabanas B"
        $tp = (int) app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agencyId, ['street_number' => '71', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo'], ['type' => 'p24', 'ref' => 'P24-' . random_int(10000000, 99999999)])->id;
        DB::table('tracked_properties')->where('id', $tp)->update(['complex_name' => 'Uvongo Cabanas B', 'unit_number' => '6']);
        $this->listingId = (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id' => $this->agencyId, 'tracked_property_id' => $tp, 'portal_source' => 'p24', 'portal_ref' => 'ref-' . Str::random(8), 'portal_url' => 'https://x.test/' . Str::random(5),
            'captured_by_user_id' => $this->me->id, 'is_active' => true, 'address' => '6 Uvongo Cabanas B, 71 Colin Street', 'suburb' => 'Uvongo', 'price' => 900000,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function deed(string $street, string $scheme, ?string $section, ?User $by, $at, string $owner = 'Owner One'): int
    {
        $tp = (int) DB::table('tracked_properties')->insertGetId([
            'agency_id' => $this->agencyId, 'external_id' => 'deed-' . Str::random(8), 'street_number' => '10', 'street_name' => $street, 'suburb' => 'UVONGO BEACH',
            'scheme_name' => $scheme, 'complex_name' => $scheme, 'section_number' => $section, 'capture_kind' => 'deeds_capture', 'deeds_captured_at' => $at,
            'deeds_captured_by_user_id' => $by?->id, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('tracked_property_owners')->insert(['agency_id' => $this->agencyId, 'tracked_property_id' => $tp, 'name' => $owner, 'id_number' => '8001015009087', 'is_primary' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $tp;
    }

    private function picker(array $q = [], ?User $as = null)
    {
        return ($as ?? $this->me) ? $this->actingAs($as ?? $this->me)->getJson(route('seller-outreach.entry.deeds-picker', ['listing' => $this->listingId] + $q)) : null;
    }

    public function test_a_deed_captured_after_the_page_loaded_is_in_the_picker_the_next_time_it_is_opened(): void
    {
        $this->deed('Old Road', 'OLD SCHEME', '1', $this->mate, now()->subDays(40));
        $before = $this->picker()->assertOk()->json();
        $this->assertSame(0, $before['today_count']);

        // Johan captures it in CMA while the compose tab is open
        $new = $this->deed('Tucker Avenue', 'UVONGO CABANAS B', '5', $this->me, now(), 'Goomal Venketsamy Naidoo');
        $after = $this->picker()->assertOk()->json();
        $this->assertSame($new, $after['deeds'][0]['tracked_property_id'], 'newest capture first');
        $this->assertSame(1, $after['today_count']);
        $this->assertSame('just_now', $after['deeds'][0]['group']);
    }

    public function test_the_page_carries_no_baked_in_list_and_the_banner_counts_todays_captures(): void
    {
        $this->deed('Tucker Avenue', 'UVONGO CABANAS B', '5', $this->me, now());
        $html = $this->actingAs($this->me)->get(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $this->listingId]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Goomal', $html, 'the list is fetched live, not rendered into the page');
        $this->assertStringContainsString('outreach/deeds?listing=' . $this->listingId, str_replace('\\/', '/', $html), 'the page knows where to fetch the live list');
        $this->assertStringContainsString('data-qa="deeds-today"', $html);
        $this->assertMatchesRegularExpression('/deedsTodayCount:\s*1\b/', $html);
    }

    public function test_only_my_own_captures_of_the_last_24_hours_are_just_now(): void
    {
        $mine = $this->deed('A Street', 'SCHEME A', '1', $this->me, now()->subHours(2));
        $theirs = $this->deed('B Street', 'SCHEME B', '1', $this->mate, now()->subHours(1));
        $old = $this->deed('C Street', 'SCHEME C', '1', $this->me, now()->subDays(3));
        $r = $this->picker()->assertOk()->json();
        $groups = collect($r['deeds'])->pluck('group', 'tracked_property_id');
        $this->assertSame('just_now', $groups[$mine]);
        $this->assertSame('nearby', $groups[$theirs], 'a colleague\'s capture is not "by you" (Uvongo Beach is next to Uvongo)');
        $this->assertSame('nearby', $groups[$old], 'older than 24 h');
        $this->assertSame(1, $r['today_count']);
        $this->assertSame($mine, $r['deeds'][0]['tracked_property_id'], 'mine first');
    }

    public function test_typing_filters_on_the_server_and_finds_a_deed_the_default_list_would_not_show(): void
    {
        $needle = $this->deed('Tucker Avenue', 'UVONGO CABANAS B', '5', $this->me, now()->subDays(60), 'Goomal Venketsamy Naidoo');
        for ($i = 0; $i < 6; $i++) {
            $this->deed('Filler ' . $i, 'FILLER ' . $i, (string) $i, $this->mate, now()->subDays(1));
        }
        $this->assertContains($needle, collect($this->picker(['q' => 'goomal'])->assertOk()->json('deeds'))->pluck('tracked_property_id')->all());
        $this->assertSame([$needle], collect($this->picker(['q' => 'goomal tucker'])->json('deeds'))->pluck('tracked_property_id')->all(), 'every word must match');
        $this->assertSame([], $this->picker(['q' => 'nobody-by-this-name'])->json('deeds'));
        $this->deed('Fresh Street', 'FRESH', '2', $this->me, now()->subMinutes(5));
        $this->assertSame(1, $this->picker(['q' => 'goomal'])->json('today_count'), 'the banner count is the day\'s captures, not the typed-filtered list');
    }

    public function test_the_deed_of_the_same_complex_is_a_likely_match_and_comes_before_the_others(): void
    {
        $other = $this->deed('Some Road', 'OTHER SCHEME', '6', $this->mate, now()->subHours(30));
        $likely = $this->deed('Colin Street', 'SS UVONGO CABANAS B', '6', $this->mate, now()->subDays(20));   // same scheme (SS prefix ignored), section 6 = unit 6
        $sameComplex = $this->deed('Tucker Avenue', 'UVONGO CABANAS B', '5', $this->mate, now()->subDays(10)); // same scheme, a different section
        $r = collect($this->picker()->assertOk()->json('deeds'));
        $this->assertSame([$likely, $sameComplex, $other], $r->pluck('tracked_property_id')->take(3)->all());
        $this->assertTrue($r->firstWhere('tracked_property_id', $likely)['likely_match']);
        $this->assertSame('same_complex', $r->firstWhere('tracked_property_id', $sameComplex)['group']);
        $this->assertFalse($r->firstWhere('tracked_property_id', $sameComplex)['likely_match']);
    }

    public function test_it_is_agency_scoped(): void
    {
        $theirs = $this->deed('Secret Road', 'SECRET', '1', $this->mate, now());
        $otherAgency = (int) DB::table('agencies')->insertGetId(['name' => 'Other ' . Str::random(6), 'slug' => 'other-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $otherAgency, 'agency_id' => $otherAgency, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tracked_properties')->where('id', $theirs)->update(['agency_id' => $otherAgency]);
        $this->assertSame([], collect($this->picker()->json('deeds'))->pluck('tracked_property_id')->all());

        $outsider = User::factory()->create(['agency_id' => $otherAgency, 'branch_id' => $otherAgency, 'role' => 'agent']);
        $mine = $this->deed('Mine Road', 'MINE', '1', $this->me, now());
        $this->assertNotContains($mine, collect($this->picker([], $outsider)->json('deeds') ?? [])->pluck('tracked_property_id')->all(), 'another agency never sees this one\'s deeds');
    }

    /** A deed in another place: its own suburb and town, far away. */
    private function farDeed(string $suburb, string $town, string $street = 'Far Road', $capturedAt = null): int
    {
        $capturedAt ??= now()->subDays(5);

        return (int) DB::table('tracked_properties')->insertGetId([
            'agency_id' => $this->agencyId, 'external_id' => 'deed-' . Str::random(8), 'street_number' => '3', 'street_name' => $street, 'suburb' => $suburb, 'town' => $town,
            'capture_kind' => 'deeds_capture', 'deeds_captured_at' => $capturedAt, 'deeds_captured_by_user_id' => $this->mate->id, 'last_known_sold_date' => '2005-04-16 00:00:00',
            'created_at' => $capturedAt, 'updated_at' => $capturedAt,
        ]);
    }

    public function test_the_blank_picker_lists_deeds_from_the_properties_own_suburb_then_nearby_and_never_the_whole_agency(): void
    {
        $same = (int) DB::table('tracked_properties')->insertGetId([
            'agency_id' => $this->agencyId, 'external_id' => 'deed-' . Str::random(8), 'street_number' => '9', 'street_name' => 'Same Street', 'suburb' => 'Uvongo', 'capture_kind' => 'deeds_capture',
            'deeds_captured_at' => now()->subDays(9), 'deeds_captured_by_user_id' => $this->mate->id, 'last_known_sold_date' => '2005-04-16 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $beach = $this->deed('Beach Road', 'BEACH SCHEME', '1', $this->mate, now()->subDays(8));      // UVONGO BEACH: next door
        $manaba = $this->farDeed('MANABA BEACH', 'Margate');
        $trafalgar = $this->farDeed('TRAFALGAR', 'Southbroom');

        $r = $this->picker()->assertOk()->json();
        $groups = collect($r['deeds'])->pluck('group', 'tracked_property_id');

        $this->assertSame('same_suburb', $groups[$same]);
        $this->assertSame('nearby', $groups[$beach]);
        $this->assertArrayNotHasKey($manaba, $groups->all(), 'a deed from another suburb and town is not offered');
        $this->assertArrayNotHasKey($trafalgar, $groups->all());
        $this->assertSame([$same, $beach], collect($r['deeds'])->pluck('tracked_property_id')->all(), 'own suburb first, then nearby');
        $this->assertSame('Uvongo', $r['suburb_label']);
    }

    public function test_a_typed_search_still_reaches_the_whole_agency_and_says_so(): void
    {
        $far = $this->farDeed('MANABA BEACH', 'Margate', 'Tucker Avenue');

        $this->assertNotContains($far, collect($this->picker()->json('deeds'))->pluck('tracked_property_id')->all());
        $typed = collect($this->picker(['q' => 'tucker'])->assertOk()->json('deeds'));
        $this->assertSame([$far], $typed->pluck('tracked_property_id')->all());
        $this->assertSame('elsewhere', $typed->first()['group']);
    }

    public function test_dates_read_as_day_month_year_never_the_stored_timestamp(): void
    {
        $this->farDeed('UVONGO', 'Uvongo', 'Dated Road');
        $row = collect($this->picker()->json('deeds'))->firstWhere('suburb', 'UVONGO');
        $this->assertSame('16 Apr 2005', $row['sold_date']);
        $this->assertStringNotContainsString('00:00:00', json_encode($this->picker(['q' => 'dated'])->json()));
    }

    public function test_nothing_on_file_says_what_it_looked_for_and_the_page_carries_the_capture_link(): void
    {
        $r = $this->picker()->assertOk()->json();
        $this->assertSame([], $r['deeds']);
        $this->assertSame('Colin Street', $r['for'], 'the property\'s street when nothing was typed');
        $typed = $this->picker(['q' => 'Cycad'])->assertOk()->json();
        $this->assertSame([], $typed['deeds']);
        $this->assertSame('Cycad', $typed['for'], 'what was typed');

        $html = $this->actingAs($this->me)->get(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $this->listingId]))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="deed-none"', $html);
        $this->assertStringContainsString('No deed on file for', $html);
        $this->assertStringContainsString('capture it from CMA Info', $html);
        $this->assertStringContainsString('data-qa="deed-capture-link"', $html);
        $this->assertStringNotContainsString('No deeds match', $html);
    }
}
