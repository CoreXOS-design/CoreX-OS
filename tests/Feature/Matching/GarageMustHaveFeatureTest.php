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
 * Live incident (2026-08-18): contact 17097 / match 349 — a plain sale
 * wishlist (price R1.5M-2.5M, Shelly Beach/Ramsgate/Uvongo etc, 3+ beds,
 * Apartment/Flat+Townhouse) returned ZERO Core Matches on
 * /corex/contacts/17097/matches/349/results, client-facing via a live
 * WhatsApp link.
 *
 * Root cause: the wishlist had 'garage' as a MUST-HAVE feature (a normal
 * FEATURE_OPTIONS checkbox). propertyFeatureTokens() only ever read
 * 'garage' from features_json text tags — never from the property's own
 * numeric `garages` column (which garages_min already scores separately).
 * Every candidate property with real structured features_json data (so the
 * must-have gate wasn't skipped as "unknown") but no redundant "Garage"
 * text tag hard-failed the must-have unconditionally, zeroing every
 * candidate. Confirmed NOT a reconcile-merge regression — identical on
 * pre-merge live (712f937b2).
 */
final class GarageMustHaveFeatureTest extends TestCase
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

    private function property(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->agent->id,
            'title'         => 'Test listing',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'price'         => 2000000,
            'beds'          => 3,
            'garages'       => 2,
            'property_type' => 'Apartment / Flat',
            // Real structured features_json WITHOUT a "Garage" text tag —
            // exactly the shape that zeroed every live candidate.
            'features_json' => ['Pet Friendly', 'Sea View', 'Fibre', 'Alarm System'],
        ], $overrides));
    }

    public function test_garage_must_have_is_satisfied_by_the_numeric_garages_column(): void
    {
        $property = $this->property();
        $match = ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1500000,
            'price_max'          => 2500000,
            'beds_min'           => 3,
            'must_have_features' => ['garage'],
        ]);

        $score = app(MatchingService::class)->score($property, $match);
        $this->assertGreaterThan(0, $score, 'a property with a real garage must satisfy a garage must-have, even with no "Garage" text tag');
    }

    public function test_single_garage_text_tag_satisfies_a_plain_garage_must_have(): void
    {
        // Confirmed live incident data: 2 of the 4 affected properties were
        // explicitly tagged "Single Garage" — canonicalizes to single_garage,
        // never plain 'garage', without this synonym.
        $property = $this->property([
            'garages'       => 0, // isolate the synonym fix from the numeric-column bridge
            'features_json' => ['Single Garage', 'Sea View'],
        ]);
        $match = ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1500000,
            'price_max'          => 2500000,
            'beds_min'           => 3,
            'must_have_features' => ['garage'],
        ]);

        $score = app(MatchingService::class)->score($property, $match);
        $this->assertGreaterThan(0, $score, '"Single Garage" must canonicalize to garage, not single_garage');
    }

    public function test_garage_must_have_still_fails_a_property_with_zero_garages(): void
    {
        $property = $this->property(['garages' => 0]);
        $match = ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1500000,
            'price_max'          => 2500000,
            'beds_min'           => 3,
            'must_have_features' => ['garage'],
        ]);

        $score = app(MatchingService::class)->score($property, $match);
        $this->assertSame(0, $score, 'a property with genuinely no garage must still fail the must-have');
    }

    public function test_full_repro_propertiesForMatch_returns_results_with_garage_must_have(): void
    {
        $property = $this->property();
        $match = ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1500000,
            'price_max'          => 2500000,
            'beds_min'           => 3,
            'must_have_features' => ['garage'],
        ]);

        $props = app(MatchingService::class)->propertiesForMatch($match, ['agent_id' => null, 'include_hidden' => false]);
        $this->assertTrue($props->contains('id', $property->id), 'the property must appear in Core Matches results, not be silently zeroed');
    }
}
