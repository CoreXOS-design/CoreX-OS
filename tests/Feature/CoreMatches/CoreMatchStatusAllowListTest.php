<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Mail\Matches\MatchDigestMail;
use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchNotification;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\BuyerCoreMatchService;
use App\Services\Matching\MatchingService;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-Core-Matches (2026-09-29), Johan's ruling — Core Matches switches from a
 * blacklist to an agency-configurable ALLOW-list: default [active, for_sale,
 * to_let, expired, other_agency_stock], under_offer explicitly OUT. Unknown
 * statuses are excluded by default (fail CLOSED), the deliberate inverse of
 * the old blacklist's fail-OPEN behaviour.
 *
 * Covers every call site the ruling named: MatchingService::propertiesForMatch()
 * / matchableCandidatePool() (via ClientMatchResolver / the property_buyer_matches
 * cache rebuild), BuyerCoreMatchService::isCoreMatch(), and the daily digest
 * (SendMatchDigests).
 */
final class CoreMatchStatusAllowListTest extends TestCase
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

    private function wishlist(array $overrides = []): ContactMatch
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

    // ---- The default allow-list itself -------------------------------

    public function test_default_allow_list_includes_active_for_sale_to_let_expired_and_other_agency_stock(): void
    {
        foreach (['active', 'for_sale', 'to_let', 'expired', Property::STATUS_OTHER_AGENCY_STOCK] as $status) {
            $this->assertTrue(
                Property::isMatchableStatus($status, $this->agency->id),
                "{$status} must be on the default Core Matches allow-list"
            );
        }
    }

    public function test_default_allow_list_excludes_draft_under_offer_withdrawn_and_capitalised_pending_rented(): void
    {
        // Johan: "only active + expired + other agency stock" — under_offer is
        // explicitly OUT (it was matching-only excluded before too, but now
        // it's simply absent from the allow-list like everything else unlisted).
        foreach (['draft', 'under_offer', 'withdrawn', 'Pending', 'Rented', 'sold', 'prospecting'] as $status) {
            $this->assertFalse(
                Property::isMatchableStatus($status, $this->agency->id),
                "{$status} must NOT be on the default Core Matches allow-list"
            );
        }
    }

    public function test_an_unknown_status_fails_closed_not_open(): void
    {
        // The whole point of switching to an allow-list (Johan: "Unknown
        // statuses are excluded by default") — the OLD blacklist would have
        // matched this (see the now-inverted assertion in
        // RentalStatusAndIncompleteDataMatchingTest).
        $this->assertFalse(Property::isMatchableStatus('available_immediately', $this->agency->id));
    }

    public function test_blank_status_stays_matchable_unrelated_leniency_preserved(): void
    {
        // Unchanged rule, orthogonal to which statuses are allow-listed: an
        // incomplete-but-live listing must not be silently suppressed.
        $this->assertTrue(Property::isMatchableStatus('', $this->agency->id));
        $this->assertTrue(Property::isMatchableStatus(null, $this->agency->id));
    }

    // ---- End-to-end through the canonical matcher ---------------------

    public function test_expired_stock_now_matches_and_draft_still_does_not(): void
    {
        $expired = $this->sale(['status' => 'expired']);
        $draft = $this->sale(['status' => 'draft']);
        $match = $this->wishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertTrue($result->contains('id', $expired->id), 'expired stock must now be a Core Match (Johan\'s ruling)');
        $this->assertFalse($result->contains('id', $draft->id), 'draft stock must remain excluded');
    }

    public function test_under_offer_withdrawn_and_capitalised_status_variants_are_excluded_end_to_end(): void
    {
        $underOffer = $this->sale(['status' => 'under_offer']);
        $withdrawn = $this->sale(['status' => 'withdrawn']);
        $capitalisedPending = $this->sale(['status' => 'Pending']);
        $match = $this->wishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertFalse($result->contains('id', $underOffer->id));
        $this->assertFalse($result->contains('id', $withdrawn->id));
        $this->assertFalse($result->contains('id', $capitalisedPending->id));
    }

    // ---- Agency-configurable custom list -------------------------------

    public function test_a_custom_agency_list_narrows_matches_to_only_what_it_names(): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update([
            'core_matches_allowed_statuses' => ['active'],
        ]);
        AgencyContactSettings::clearCoreMatchAllowedStatusesCache();

        $active = $this->sale(['status' => 'active']);
        $expired = $this->sale(['status' => 'expired']); // on the CODE default, not this agency's custom list
        $match = $this->wishlist();

        $result = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null]);

        $this->assertTrue($result->contains('id', $active->id));
        $this->assertFalse($result->contains('id', $expired->id), 'expired is not on this agency\'s custom list and must be excluded');
    }

    public function test_null_or_empty_setting_resolves_to_the_code_default(): void
    {
        $settings = AgencyContactSettings::forAgency($this->agency->id);
        $this->assertSame(Property::CORE_MATCH_DEFAULT_ALLOWED_STATUSES, $settings->coreMatchesAllowedStatuses());

        $settings->update(['core_matches_allowed_statuses' => []]);
        $this->assertSame(
            Property::CORE_MATCH_DEFAULT_ALLOWED_STATUSES,
            $settings->fresh()->coreMatchesAllowedStatuses(),
            'an empty stored array must fall back to the code default, same as null'
        );
    }

    // ---- BuyerCoreMatchService::isCoreMatch() no longer trusts the caller ---

    public function test_isCoreMatch_refuses_an_excluded_status_even_with_a_passing_score(): void
    {
        $withdrawn = $this->sale(['status' => 'withdrawn']);
        $this->wishlist();

        $service = app(BuyerCoreMatchService::class);

        $this->assertFalse($service->isCoreMatch($this->contact, $withdrawn), 'a withdrawn property must never read as a Core Match, regardless of score');
    }

    public function test_isCoreMatch_accepts_an_allow_listed_status_with_a_passing_score(): void
    {
        $active = $this->sale(['status' => 'active']);
        $this->wishlist();

        $service = app(BuyerCoreMatchService::class);

        $this->assertTrue($service->isCoreMatch($this->contact, $active));
    }

    // ---- The daily digest skips an excluded status at send time -----------

    public function test_digest_skips_an_excluded_status_property_and_still_stamps_it(): void
    {
        Mail::fake();

        $activeProperty = $this->sale(['status' => 'active', 'title' => 'Included Listing']);
        $withdrawnProperty = $this->sale(['status' => 'withdrawn', 'title' => 'Excluded Listing']);
        $match = $this->wishlist();

        $activeNotification = ContactMatchNotification::create([
            'agency_id' => $this->agency->id,
            'contact_match_id' => $match->id,
            'property_id' => $activeProperty->id,
            'score' => 80,
            'notified_user_id' => $this->agent->id,
        ]);
        $withdrawnNotification = ContactMatchNotification::create([
            'agency_id' => $this->agency->id,
            'contact_match_id' => $match->id,
            'property_id' => $withdrawnProperty->id,
            'score' => 75,
            'notified_user_id' => $this->agent->id,
        ]);

        $this->artisan('corex:matches:send-digests')->assertExitCode(0);

        Mail::assertSent(MatchDigestMail::class, function (MatchDigestMail $mail) use ($activeProperty, $withdrawnProperty) {
            $items = collect($mail->groups)->flatMap(fn ($g) => $g['items']);

            return $items->count() === 1
                && $items->first()['property_id'] === $activeProperty->id
                && $items->pluck('property_id')->doesntContain($withdrawnProperty->id);
        });

        // Both rows are swept off the queue regardless of what made the email.
        $this->assertNotNull($activeNotification->fresh()->emailed_at);
        $this->assertNotNull($withdrawnNotification->fresh()->emailed_at);
    }

    // ---- The property_buyer_matches cache drops excluded-status rows --------

    public function test_cache_rebuild_drops_a_row_once_its_status_falls_off_the_allow_list(): void
    {
        $property = $this->sale(['status' => 'active']);
        $this->wishlist();

        $scoring = app(PropertyMatchScoringService::class);
        $scoring->recomputeForBuyer($this->contact->id);

        $this->assertDatabaseHas('property_buyer_matches', [
            'contact_id' => $this->contact->id,
            'property_id' => $property->id,
        ]);

        // Simulate the setting/status change that drops this property off the
        // allow-list, then the same rebuild RegenerateBuyerMatchesJob runs.
        $property->update(['status' => 'draft']);
        $scoring->recomputeForBuyer($this->contact->id);

        $this->assertDatabaseMissing('property_buyer_matches', [
            'contact_id' => $this->contact->id,
            'property_id' => $property->id,
        ]);
    }
}
