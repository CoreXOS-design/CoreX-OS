<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\MicWork\MicUserPreferences;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** road-to-sold.md 23.23: the one-property MIC card the pitch popup and the clickable header show, and the entry points that open the popup. */
final class PitchPopupCardTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true, 'name' => 'Gerda Botha']);
    }

    private function advert(string $ref, int $no, array $extra = [], ?int $agencyId = null): ProspectingListing
    {
        $agencyId ??= $this->agency->id;
        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($agencyId, ['street_number' => (string) $no, 'street_name' => 'Pitch Road', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => $ref]);
        $l = new ProspectingListing();
        $l->forceFill(array_merge([
            'agency_id' => $agencyId, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => $ref, 'portal_url' => 'https://www.property24.com/x/' . $ref, 'address' => $no . ' Pitch Road',
            'suburb' => 'Margate', 'property_type' => 'House', 'price' => 1_250_000, 'bedrooms' => 3, 'captured_by_user_id' => $this->admin->id, 'first_seen_at' => now()->subDays(10), 'last_seen_at' => now(),
            'is_active' => true, 'portal_status' => 'active', 'card_heading' => '3 Bedroom House', 'description_snippet' => 'Near the beach.',
        ], $extra))->save();

        return $l;
    }

    private function card(array $q): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->get(route('market-intelligence.work', $q));
    }

    public function test_the_card_page_is_one_property_and_the_same_card_the_list_uses(): void
    {
        $a = $this->advert('P24-170000001', 1);
        $this->advert('P24-170000002', 2);

        $h = $this->card(['_pitch_card' => $a->id, 'embed' => 1])->assertOk()->getContent();
        $this->assertSame(1, substr_count($h, 'data-qa="advert-card"'), 'one card, not the list');
        $this->assertStringContainsString('data-qa="pitch-card"', $h);
        $this->assertStringContainsString('3 Bedroom House', $h);
        $this->assertStringNotContainsString('P24-170000002', $h);
        $this->assertStringNotContainsString('id="corex-sidebar"', $h, 'chrome-less in embed mode');
        $this->assertStringContainsString('data-embed-done', $h, 'in the popup the page ends with Done');

        $peek = $this->card(['_pitch_card' => $a->id, 'embed' => 1, 'peek' => 1])->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="advert-card"', $peek);
        $this->assertStringNotContainsString('data-embed-done', $peek, 'the header window has no Done bar of its own');
    }

    public function test_it_finds_the_card_from_the_property_or_the_tracked_property_too(): void
    {
        $a = $this->advert('P24-170000003', 3);
        $this->card(['_pitch_tracked' => $a->tracked_property_id])->assertOk()->assertSee('data-qa="pitch-card"', false);

        $propId = $this->property((int) $this->admin->id);
        $a->forceFill(['matched_property_id' => $propId])->save();
        $this->card(['_pitch_property' => $propId])->assertOk()->assertSee('data-qa="pitch-card"', false);

        $this->card(['_pitch_tracked' => 999999])->assertNotFound();
    }

    public function test_a_property_the_viewer_has_claimed_or_pitched_still_shows_and_the_filters_are_left_alone(): void
    {
        $a = $this->advert('P24-170000004', 4, ['pitched_at' => now()]);
        ProspectingClaim::query()->forceCreate(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $a->id, 'user_id' => $this->admin->id, 'is_active' => true, 'claimed_at' => now(), 'pitched_at' => now(), 'status' => 'pitched']);

        // the canvass list hides it by default (pitch lock) when the Adverts filter is "unclaimed"; the card does not care
        $prefs = app(MicUserPreferences::class);
        $prefs->remember(['view' => 'adverts', 'claimed' => 'unclaimed'], $this->admin, $this->agency->id);
        $before = $prefs->get($this->admin, $this->agency->id);

        $this->card(['_pitch_card' => $a->id])->assertOk()->assertSee('data-qa="advert-card"', false);

        $this->assertEquals($before, $prefs->get($this->admin, $this->agency->id), 'a card request never touches the remembered filters');
    }

    public function test_another_agencys_advert_is_a_404(): void
    {
        $other = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        $foreign = $this->advert('P24-170000005', 5, [], $other->id);

        $this->card(['_pitch_card' => $foreign->id])->assertNotFound();
    }

    public function test_the_pitch_screens_carry_the_clickable_header_and_the_popup_script(): void
    {
        $a = $this->advert('P24-170000006', 6);

        $h = $this->actingAs($this->admin)->get(route('seller-outreach.entry.from-prospecting', ['prospectingListingId' => $a->id, 'fresh' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-pitch-peek data-listing="' . $a->id . '"', $h, 'the Listing from P24 block opens the card');
        $this->assertStringContainsString('What is this property?', $h);
        $this->assertStringContainsString('js/pitch-popup.js', $h);
    }

    public function test_the_pitch_now_chip_opens_the_popup_and_the_work_page_loads_it(): void
    {
        $a = $this->advert('P24-170000007', 7);

        $h = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['view' => 'adverts']))->assertOk()->getContent();
        $this->assertStringContainsString('js/pitch-popup.js', $h);
        if (str_contains($h, 'mi-suggested-chip')) {
            $this->assertMatchesRegularExpression('/data-pitch-popup data-listing="' . $a->id . '"/', $h, 'Pitch now opens the popup');
        }
    }
}
