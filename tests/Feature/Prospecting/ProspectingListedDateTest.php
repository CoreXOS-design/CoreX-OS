<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\ProspectingListingDateCorrection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-454 (Road to Sold) piece 2 — a REAL listed date for MIC listings.
 *
 *  - the capture API accepts and stores the advert's listed date, apart from first_seen_at
 *  - a malformed / future date never fails the batch (absorbed to null)
 *  - daysOnMarket / "new" / "long" use the listed date when present, else first sighting
 *  - an agent can correct the date with a reason; history kept; later captures never overwrite it
 */
final class ProspectingListedDateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Margate Agency', 'slug' => 'margate-agency-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
    }

    // ── where a real date comes from: listing-page captures only ────────

    private function recorder(): \App\Services\Prospecting\ListedDateRecorder
    {
        return app(\App\Services\Prospecting\ListedDateRecorder::class);
    }

    private function listedDateOf(string $ref): ?string
    {
        return ProspectingListing::withoutGlobalScopes()->where('portal_ref', $ref)->firstOrFail()->listed_date?->toDateString();
    }

    public function test_search_result_captures_never_set_a_listed_date_and_first_seen_is_untouched(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/prospecting/import', [
            'source' => 'p24',
            'search_context' => ['url' => 'https://www.property24.com/for-sale/margate/1/p1', 'search_term' => 'Margate', 'total_results' => 1, 'pages_captured' => 1],
            'listings' => [['portal_ref' => 'P24-5001', 'address' => '12 Marine Drive', 'suburb' => 'Margate', 'price' => 1850000,
                'portal_url' => 'https://www.property24.com/listing/5001', 'listed_date' => '2026-07-17']],
        ])->assertOk();

        $l = ProspectingListing::withoutGlobalScopes()->where('portal_ref', 'P24-5001')->firstOrFail();
        $this->assertNull($l->listed_date, 'tiles carry no date: even a stray field is ignored by the search capture');
        $this->assertNull($l->listed_date_source);
        $this->assertTrue($l->first_seen_at->isToday());
    }

    public function test_the_recorder_stores_a_listing_pages_date_on_the_matching_mic_listing_in_either_reference_form(): void
    {
        $this->listing('P24-117001', listed: null, firstSeen: now()->subDays(3));
        $this->listing('PP-T555', listed: null, firstSeen: now()->subDays(3));

        $this->assertSame(1, $this->recorder()->record($this->agency->id, 'p24', '117001', '17 July 2026'));
        $this->assertSame(1, $this->recorder()->record($this->agency->id, 'pp', 'T555', '2026-06-01'));

        $this->assertSame('2026-07-17', $this->listedDateOf('P24-117001'));
        $this->assertSame('2026-06-01', $this->listedDateOf('PP-T555'));
        $this->assertSame('portal', ProspectingListing::withoutGlobalScopes()->where('portal_ref', 'P24-117001')->value('listed_date_source'));

        // prefixed form, same advert: same date -> nothing to change
        $this->assertSame(0, $this->recorder()->record($this->agency->id, 'p24', 'P24-117001', '2026-07-17'));
        // a changed date on the page is taken
        $this->assertSame(1, $this->recorder()->record($this->agency->id, 'p24', 'P24-117001', '2026-07-20'));
        $this->assertSame('2026-07-20', $this->listedDateOf('P24-117001'));
    }

    public function test_the_recorder_never_overwrites_an_agents_correction_and_never_creates_a_listing(): void
    {
        $l = $this->listing('P24-117002', listed: now()->subDays(40), firstSeen: now()->subDays(3));
        DB::table('prospecting_listings')->where('id', $l->id)->update(['listed_date_source' => 'agent']);
        $before = DB::table('prospecting_listings')->count();

        $this->assertSame(0, $this->recorder()->record($this->agency->id, 'p24', '117002', '2026-01-01'));
        $this->assertSame(now()->subDays(40)->toDateString(), $this->listedDateOf('P24-117002'));

        $this->assertSame(0, $this->recorder()->record($this->agency->id, 'p24', '999999', '2026-01-01'), 'an advert MIC does not hold is not created');
        $this->assertSame($before, DB::table('prospecting_listings')->count());
    }

    public function test_the_recorder_only_touches_its_own_agency_and_live_listings(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $ou = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'role' => 'admin']);
        $theirs = $this->listing('P24-117003', listed: null, firstSeen: now()->subDays(3), agencyId: $other->id, branchId: $ob->id, capturedBy: $ou->id);
        $gone = $this->listing('P24-117004', listed: null, firstSeen: now()->subDays(3));
        DB::table('prospecting_listings')->where('id', $gone->id)->update(['deleted_at' => now()]);

        $this->assertSame(0, $this->recorder()->record($this->agency->id, 'p24', '117003', '2026-07-01'), 'another agency\'s advert');
        $this->assertSame(0, $this->recorder()->record($this->agency->id, 'p24', '117004', '2026-07-01'), 'a deleted listing');
        $this->assertNull($theirs->fresh()->listed_date);
        $this->assertSame(0, $this->recorder()->record(0, 'p24', '117003', '2026-07-01'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableDates')]
    public function test_a_blank_future_or_impossible_page_date_is_ignored(string $raw): void
    {
        $this->listing('P24-117005', listed: null, firstSeen: now()->subDays(3));

        $this->assertSame(0, $this->recorder()->record($this->agency->id, 'p24', '117005', $raw), "'{$raw}'");
        $this->assertNull($this->listedDateOf('P24-117005'));
    }

    public static function unusableDates(): array
    {
        return [
            'blank'       => [''],
            'zero'        => ['0'],
            'dash'        => ['-'],
            'words'       => ['Listed soon'],
            'future'      => [now()->addDays(10)->toDateString()],
            'before 2000' => ['1989-03-01'],
            'impossible'  => ['2026-02-31'],
        ];
    }

    public function test_an_unknown_portal_or_blank_reference_is_ignored(): void
    {
        $this->listing('P24-117006', listed: null, firstSeen: now()->subDays(3));

        $this->assertSame(0, $this->recorder()->record($this->agency->id, 'property24', '117006', '2026-07-01'));
        $this->assertSame(0, $this->recorder()->record($this->agency->id, 'p24', '  ', '2026-07-01'));
        $this->assertSame(0, $this->recorder()->record($this->agency->id, 'p24', 'abc', '2026-07-01'));
    }

    /** The Other Agency Stock import already reads the page's "Listing Date" (date_posted): it now reaches MIC too. */
    public function test_the_other_agency_stock_import_stores_the_pages_date_on_the_mic_listing(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 404)]);
        $this->listing('PP-T99999', listed: null, firstSeen: now()->subDays(3));
        $payload = [
            'portal' => 'pp', 'listing_ref' => 'T99999', 'consent' => true, 'price' => 1500000, 'property_type' => 'house',
            'listing_url' => 'https://www.privateproperty.co.za/for-sale/kwazulu-natal/margate/T99999',
            'beds' => 3, 'baths' => 2, 'suburb' => 'Uvongo', 'description' => 'A lovely home',
            'source_agency_name' => 'Seeff', 'source_agent_name' => 'Jane Agent', 'date_posted' => '17 July 2026',
        ];

        $this->actingAs($this->user)->postJson(route('v1.other-agency-stock.import'), $payload)->assertOk();

        $this->assertSame('2026-07-17', $this->listedDateOf('PP-T99999'));
    }

    public function test_the_other_agency_stock_import_still_succeeds_when_no_mic_listing_or_no_date(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 404)]);
        $base = [
            'portal' => 'pp', 'listing_ref' => 'T88888', 'consent' => true, 'price' => 1500000, 'property_type' => 'house',
            'listing_url' => 'https://www.privateproperty.co.za/for-sale/kwazulu-natal/margate/T88888',
            'beds' => 3, 'baths' => 2, 'suburb' => 'Uvongo', 'description' => 'A lovely home',
            'source_agency_name' => 'Seeff', 'source_agent_name' => 'Jane Agent',
        ];

        $this->actingAs($this->user)->postJson(route('v1.other-agency-stock.import'), $base + ['date_posted' => '17 July 2026'])->assertOk();
        $this->assertSame(0, DB::table('prospecting_listings')->where('portal_ref', 'PP-T88888')->count(), 'nothing is created in MIC');

        $this->listing('PP-T88889', listed: null, firstSeen: now()->subDays(3));
        $this->actingAs($this->user)->postJson(route('v1.other-agency-stock.import'), array_merge($base, ['listing_ref' => 'T88889']))->assertOk();
        $this->assertNull($this->listedDateOf('PP-T88889'));
    }

    /** The single-listing "Pull property" capture carries the page's Listing Date as date_posted. */
    public function test_pulling_a_property_stores_the_pages_date_on_the_mic_listing(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 404)]);
        $this->listing('P24-117010', listed: null, firstSeen: now()->subDays(3));

        $this->actingAs($this->user)->postJson(route('v1.properties.pull-from-portal'), [
            'portal_ref' => 'P24-117010', 'portal_url' => 'https://www.property24.com/for-sale/margate/kwazulu-natal/1/117010',
            'title' => '3 Bedroom House in Margate', 'price' => 1850000, 'suburb' => 'Margate', 'beds' => 3, 'baths' => 2,
            'date_posted' => '3 June 2026',
        ])->assertOk();

        $this->assertSame('2026-06-03', $this->listedDateOf('P24-117010'));
    }

    /** @return array<string,array{0:mixed,1:?string}> */
    public static function jsonLdShapes(): array
    {
        $graph = ['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'BreadcrumbList'], ['@type' => 'RealEstateListing', 'datePosted' => '2026-09-01'],
        ]];

        return [
            'graph'      => [$graph, '2026-09-01'],
            'single'     => [['@type' => 'RealEstateListing', 'datePosted' => '2026-08-02'], '2026-08-02'],
            'list'       => [[['@type' => 'Thing'], ['@type' => 'RealEstateListing', 'datePosted' => '2026-07-03']], '2026-07-03'],
            'none'       => [['@type' => 'RealEstateListing', 'offers' => ['price' => 1]], null],
            'not json'   => [null, null],
            'non-string' => [['datePosted' => ['x']], null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jsonLdShapes')]
    public function test_the_date_is_found_in_the_pages_json_ld_whatever_its_shape(mixed $jsonLd, ?string $expected): void
    {
        $this->assertSame($expected, \App\Services\Prospecting\ListedDateRecorder::datePostedFromJsonLd($jsonLd));
    }

    /** The presentation portal capture uploads the whole P24 listing page; its JSON-LD datePosted now reaches MIC. */
    public function test_a_presentation_portal_capture_of_a_listing_page_stores_the_pages_date_on_the_mic_listing(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->listing('P24-117580701', listed: null, firstSeen: now()->subDays(3));

        $this->actingAs($this->user)->postJson('/portal-captures/ingest', $this->capturePayload([
            '@context' => 'https://schema.org',
            '@graph'   => [['@type' => 'RealEstateListing', 'datePosted' => '2026-09-01']],
        ]))->assertSuccessful();

        $this->assertSame('2026-09-01', $this->listedDateOf('P24-117580701'));
    }

    public function test_a_presentation_portal_capture_without_a_date_or_mic_listing_changes_nothing_and_succeeds(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->listing('P24-117580701', listed: null, firstSeen: now()->subDays(3));

        $this->actingAs($this->user)->postJson('/portal-captures/ingest', $this->capturePayload(['@type' => 'RealEstateListing']))
            ->assertSuccessful();
        $this->assertNull($this->listedDateOf('P24-117580701'));

        // A listing MIC does not hold: no row is created.
        $other = $this->capturePayload(['@type' => 'RealEstateListing', 'datePosted' => '2026-09-01']);
        $other['source_url'] = $other['final_url'] = 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/123/117999999';
        $this->actingAs($this->user)->postJson('/portal-captures/ingest', $other)->assertSuccessful();
        $this->assertSame(0, DB::table('prospecting_listings')->where('portal_ref', 'P24-117999999')->count());
    }

    public function test_a_presentation_portal_capture_never_overwrites_an_agents_correction(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $l = $this->listing('P24-117580701', listed: now()->subDays(40), firstSeen: now()->subDays(60));
        DB::table('prospecting_listings')->where('id', $l->id)->update(['listed_date_source' => 'agent']);

        $this->actingAs($this->user)->postJson('/portal-captures/ingest', $this->capturePayload(['@type' => 'RealEstateListing', 'datePosted' => '2026-09-01']))
            ->assertSuccessful();

        $this->assertSame(now()->subDays(40)->toDateString(), $this->listedDateOf('P24-117580701'));
    }

    private function capturePayload(array $jsonLd): array
    {
        $url = 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/123/117580701';

        return [
            'source_site' => 'www.property24.com', 'page_type' => 'property', 'source_url' => $url, 'final_url' => $url,
            'captured_at' => now()->toIso8601String(), 'extractor_version' => 'test', 'parse_status' => 'parsed',
            'html' => '<html><body><h1 class="p24_listing">3 Bedroom House</h1><div class="p24_price">R 1 850 000</div></body></html>',
            'jsonld' => $jsonLd,
        ];
    }

    // ── days on market, new, long ────────────────────────────────────────

    public function test_days_on_market_uses_the_listed_date_else_first_seen(): void
    {
        $withDate = $this->listing('P24-A', listed: now()->subDays(40), firstSeen: now()->subDays(5));
        $without  = $this->listing('P24-B', listed: null, firstSeen: now()->subDays(5));

        $this->assertSame(40, $withDate->daysOnMarket());
        $this->assertSame(5, $without->daysOnMarket());
        $this->assertTrue($withDate->hasRealListedDate());
        $this->assertFalse($without->hasRealListedDate());
    }

    public function test_days_on_market_stops_at_the_off_market_date(): void
    {
        $l = $this->listing('P24-C', listed: now()->subDays(30), firstSeen: now()->subDays(2));
        $l->off_market_at = now()->subDays(10);

        $this->assertSame(20, $l->daysOnMarket());
    }

    public function test_new_and_long_on_market_scopes_prefer_the_real_date(): void
    {
        // captured yesterday, but the portal says it has been up 100 days -> LONG, not new
        $old      = $this->listing('P24-OLD', listed: now()->subDays(100), firstSeen: now()->subDay());
        // captured long ago, portal date is last week -> NEW
        $fresh    = $this->listing('P24-FRESH', listed: now()->subDays(3), firstSeen: now()->subDays(200));
        // no real date: first sighting decides (the legacy behaviour)
        $legacyNew  = $this->listing('P24-LNEW', listed: null, firstSeen: now()->subDays(2));
        $legacyLong = $this->listing('P24-LLONG', listed: null, firstSeen: now()->subDays(150));

        $new  = ProspectingListing::withoutGlobalScopes()->listedWithinDays(14)->pluck('id')->all();
        $long = ProspectingListing::withoutGlobalScopes()->listedBeforeDays(90)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$fresh->id, $legacyNew->id], $new);
        $this->assertEqualsCanonicalizing([$old->id, $legacyLong->id], $long);
    }

    // ── correction ───────────────────────────────────────────────────────

    public function test_an_agent_can_correct_the_date_with_a_reason_and_history_is_kept(): void
    {
        $l = $this->listing('P24-D', listed: null, firstSeen: now()->subDays(5));

        $this->actingAs($this->user)
            ->put(route('market-intelligence.listing.listed-date.update', $l->id), [
                'listed_date' => now()->subDays(60)->toDateString(),
                'reason'      => 'Seller confirmed it went on in August',
            ])->assertRedirect();

        $l->refresh();
        $this->assertSame(now()->subDays(60)->toDateString(), $l->listed_date->toDateString());
        $this->assertSame(ProspectingListing::LISTED_DATE_SOURCE_AGENT, $l->listed_date_source);

        $h = ProspectingListingDateCorrection::withoutGlobalScopes()->where('prospecting_listing_id', $l->id)->get();
        $this->assertCount(1, $h);
        $this->assertNull($h[0]->old_date);
        $this->assertSame((int) $this->user->id, (int) $h[0]->user_id);
        $this->assertSame((int) $this->agency->id, (int) $h[0]->agency_id);
        $this->assertStringContainsString('August', $h[0]->reason);

        // a second correction keeps the first as history
        $this->actingAs($this->user)
            ->put(route('market-intelligence.listing.listed-date.update', $l->id), [
                'listed_date' => now()->subDays(45)->toDateString(),
                'reason'      => 'Re-checked the mandate paperwork',
            ])->assertRedirect();

        $this->assertSame(2, ProspectingListingDateCorrection::withoutGlobalScopes()->where('prospecting_listing_id', $l->id)->count());
        $second = ProspectingListingDateCorrection::withoutGlobalScopes()->where('prospecting_listing_id', $l->id)->orderByDesc('id')->first();
        $this->assertSame(now()->subDays(60)->toDateString(), $second->old_date->toDateString());
    }

    public function test_a_correction_needs_a_reason_and_a_sane_date(): void
    {
        $l = $this->listing('P24-E', listed: null, firstSeen: now()->subDays(5));
        $url = route('market-intelligence.listing.listed-date.update', $l->id);

        $this->actingAs($this->user)->put($url, ['listed_date' => now()->subDays(3)->toDateString(), 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->user)->put($url, ['listed_date' => now()->addDay()->toDateString(), 'reason' => 'Because I said so'])
            ->assertSessionHasErrors('listed_date');
        $this->actingAs($this->user)->put($url, ['listed_date' => 'not a date', 'reason' => 'Because I said so'])
            ->assertSessionHasErrors('listed_date');

        $this->assertNull($l->fresh()->listed_date);
        $this->assertSame(0, ProspectingListingDateCorrection::withoutGlobalScopes()->count());
    }

    public function test_another_agencys_listing_is_a_404_not_a_403(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $otherUser = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);
        $theirs = $this->listing('P24-THEIRS', listed: null, firstSeen: now()->subDay(), agencyId: $other->id, branchId: $otherBranch->id, capturedBy: $otherUser->id);

        $this->actingAs($this->user)
            ->put(route('market-intelligence.listing.listed-date.update', $theirs->id), [
                'listed_date' => now()->subDays(3)->toDateString(), 'reason' => 'Trying another agency',
            ])->assertNotFound();

        $this->assertNull($theirs->fresh()->listed_date);
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        // Make the role grants non-empty so the "no grants = permit all" fallback does not apply.
        DB::table('role_permissions')->insert([
            'role' => 'agent', 'permission_key' => 'access_prospecting', 'scope' => null,
            'agency_id' => $this->agency->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $l = $this->listing('P24-F', listed: null, firstSeen: now()->subDay());

        $this->actingAs($agent)
            ->put(route('market-intelligence.listing.listed-date.update', $l->id), [
                'listed_date' => now()->subDays(3)->toDateString(), 'reason' => 'No permission for this',
            ])->assertForbidden();

        $this->assertNull($l->fresh()->listed_date);
    }

    public function test_the_opportunity_page_shows_the_listed_date_and_the_correction_form(): void
    {
        $tp = \App\Models\Prospecting\TrackedProperty::withoutAgencyStamping(fn () => \App\Models\Prospecting\TrackedProperty::create([
            'agency_id' => $this->agency->id, 'street_number' => '12', 'street_name' => 'Marine Drive',
            'suburb' => 'Margate', 'source_chain' => [],
        ]));
        $l = $this->listing('P24-G', listed: now()->subDays(20), firstSeen: now()->subDays(2));
        DB::table('prospecting_listings')->where('id', $l->id)->update(['tracked_property_id' => $tp->id]);

        $res = $this->actingAs($this->user)->get(route('market-intelligence.opportunities.show', $tp->id));
        $res->assertOk();
        $res->assertSee('Correct listed date');
        $res->assertSee(now()->subDays(20)->format('j M Y'));
        $res->assertSee('from the portal');

    }

    public function test_an_agent_without_the_permission_sees_the_date_but_not_the_correction_form(): void
    {
        $tp = \App\Models\Prospecting\TrackedProperty::withoutAgencyStamping(fn () => \App\Models\Prospecting\TrackedProperty::create([
            'agency_id' => $this->agency->id, 'street_number' => '12', 'street_name' => 'Marine Drive',
            'suburb' => 'Margate', 'source_chain' => [],
        ]));
        $l = $this->listing('P24-H', listed: now()->subDays(20), firstSeen: now()->subDays(2));
        DB::table('prospecting_listings')->where('id', $l->id)->update(['tracked_property_id' => $tp->id]);

        DB::table('role_permissions')->insert([
            'role' => 'agent', 'permission_key' => 'access_prospecting', 'scope' => null,
            'agency_id' => $this->agency->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $res2 = $this->actingAs($agent)->get(route('market-intelligence.opportunities.show', $tp->id));
        $res2->assertOk();
        $res2->assertSee('from the portal');
        $res2->assertDontSee('Correct listed date');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function listing(
        string $ref,
        $listed,
        $firstSeen,
        ?int $agencyId = null,
        ?int $branchId = null,
        ?int $capturedBy = null,
    ): ProspectingListing {
        $id = (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id'           => $agencyId ?? $this->agency->id,
            'branch_id'           => $branchId ?? $this->branch->id,
            'captured_by_user_id' => $capturedBy ?? $this->user->id,
            'portal_source'       => str_starts_with($ref, 'PP-') ? 'pp' : 'p24',
            'portal_ref'          => $ref,
            'portal_url'          => 'https://example.test/' . $ref,
            'address'             => '1 Test Road',
            'suburb'              => 'Margate',
            'price'               => 1000000,
            'is_active'           => true,
            'listed_date'         => $listed?->toDateString(),
            'listed_date_source'  => $listed ? 'portal' : null,
            'first_seen_at'       => $firstSeen,
            'last_seen_at'        => now(),
            'created_at'          => now(), 'updated_at' => now(),
        ]);

        return ProspectingListing::withoutGlobalScopes()->findOrFail($id);
    }
}
