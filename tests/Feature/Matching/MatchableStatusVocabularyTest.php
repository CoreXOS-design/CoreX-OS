<?php

declare(strict_types=1);

namespace Tests\Feature\Matching;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live bug (found 2026-09-15, Falan/Johan, reported as "drafts now included
 * on Core Matches"). Investigated: draft was already correctly excluded
 * everywhere (MatchingService::NON_MATCHABLE_STATUSES already listed it) —
 * but 'prospecting' and 'not_selling' were NOT, and were treated as
 * matchable: 560 of 842 properties (66%) in the live agency-wide matchable
 * candidate pool were ingested-but-unmandated stock the agency doesn't hold
 * the mandate on. Same defect class as the rental to_let gap (found earlier
 * the same day): the matching engine's idea of which statuses are matchable
 * was wrong and maintained in more than one place —
 * MatchingService::NON_MATCHABLE_STATUSES and
 * CoreMatchReasonClassifier::NON_MATCHABLE_STATUSES were both independent,
 * drifted copies of Property::OFF_MARKET_STATUSES (the correct one).
 *
 * Fixed by defining match-eligibility ONCE — Property::isMatchableStatus()/
 * matchingExcludedStatusList() — and having every caller (MatchingService,
 * CoreMatchReasonClassifier) delegate to it instead of maintaining a copy.
 *
 * Johan's explicit correction mid-report: "active is not the same as
 * advertised" — an agency can hold a genuine, active mandate and be
 * instructed not to market it; that property must still match. So this
 * fix must NEVER filter on syndication/portal/advertising flags, only on
 * the base `status` column.
 */
final class MatchableStatusVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Test', 'last_name' => 'Buyer',
        ]);
    }

    private function sale(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->agent->id,
            'title'         => 'Test listing',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'price'         => 1_500_000,
            'beds'          => 3,
            'garages'       => 2,
            'property_type' => 'House',
        ], $overrides));
    }

    private function rental(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->agent->id,
            'title'         => 'Test rental',
            'status'        => 'to_let',
            'listing_type'  => 'rental',
            'price'         => 0,
            'rental_amount' => 12_000,
            'beds'          => 2,
            'garages'       => 1,
            'property_type' => 'Apartment / Flat',
        ], $overrides));
    }

    private function saleWishlist(array $overrides = []): ContactMatch
    {
        return ContactMatch::create(array_merge([
            'agency_id'      => $this->agency->id,
            'contact_id'     => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'listing_type'   => 'sale',
            'status'         => ContactMatch::STATUS_ACTIVE,
            'price_min'      => 1_000_000,
            'price_max'      => 2_000_000,
            'beds_min'       => 2,
            'property_types' => ['House'],
        ], $overrides));
    }

    private function rentalWishlist(array $overrides = []): ContactMatch
    {
        return ContactMatch::create(array_merge([
            'agency_id'      => $this->agency->id,
            'contact_id'     => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'listing_type'   => 'rental',
            'status'         => ContactMatch::STATUS_ACTIVE,
            'price_min'      => 8_000,
            'price_max'      => 15_000,
            'beds_min'       => 1,
            'property_types' => ['Apartment / Flat'],
        ], $overrides));
    }

    public function test_draft_is_excluded_pinning_existing_correct_behaviour(): void
    {
        $draft = $this->sale(['status' => 'draft']);
        $match = $this->saleWishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertFalse($result->contains('id', $draft->id));
    }

    public function test_prospecting_is_now_excluded_the_live_bug(): void
    {
        $prospecting = $this->sale(['status' => 'prospecting']);
        $match = $this->saleWishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertFalse($result->contains('id', $prospecting->id), 'prospecting (ingested-but-unmandated) stock must never match');
    }

    public function test_not_selling_is_now_excluded(): void
    {
        $notSelling = $this->sale(['status' => 'not_selling']);
        $match = $this->saleWishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertFalse($result->contains('id', $notSelling->id));
    }

    public function test_under_offer_is_excluded_already_spoken_for(): void
    {
        $underOffer = $this->sale(['status' => 'under_offer']);
        $match = $this->saleWishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertFalse($result->contains('id', $underOffer->id), 'a property another buyer already has an accepted offer on must not be offered as a new match');
    }

    /**
     * THE case Johan specifically called out as most likely to break with an
     * over-eager fix: "it can be active not advertised and should still
     * show. agencies do get requests from owners to list their property but
     * to not advertise it." No syndication/portal flag may gate matching —
     * only the base status column.
     */
    public function test_active_but_not_advertised_still_matches(): void
    {
        $activeQuiet = $this->sale([
            'status' => 'active',
            'p24_syndication_enabled' => false,
            'pp_syndication_enabled' => false,
            'p24_ref' => null,
            'pp_ref' => null,
        ]);
        $match = $this->saleWishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertTrue($result->contains('id', $activeQuiet->id), 'active-but-not-advertised is still genuinely sellable stock and must match');
    }

    public function test_to_let_rental_status_now_matches(): void
    {
        $toLet = $this->rental(['status' => 'to_let']);
        $match = $this->rentalWishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertTrue($result->contains('id', $toLet->id), 'to_let is real available-to-rent stock and must match — this was invisible before the fix');
    }

    public function test_let_out_rental_still_excluded(): void
    {
        $letOut = $this->rental(['status' => 'let_out']);
        $match = $this->rentalWishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertFalse($result->contains('id', $letOut->id), 'already-let stock must never match a new tenant search');
    }

    public function test_matching_service_delegates_to_the_one_canonical_definition(): void
    {
        foreach (['active', 'draft', 'prospecting', 'not_selling', 'under_offer', 'sold', 'to_let', 'withdrawn'] as $status) {
            $this->assertSame(
                Property::isMatchableStatus($status),
                MatchingService::isMatchableStatus($status),
                "MatchingService::isMatchableStatus('{$status}') must agree with the canonical Property::isMatchableStatus() — no drift"
            );
        }
    }
}
