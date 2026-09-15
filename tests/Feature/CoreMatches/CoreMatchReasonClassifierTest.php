<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\PropertyAuditLog;
use App\Models\User;
use App\Services\Matching\CoreMatchReasonClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-Core-Matches, "why is this new" ruling — four independent reasons,
 * never collapsed into one flag. Proves each source of truth in isolation
 * and the elimination fallback, plus the two things Johan asked for
 * explicitly: the threshold is a real agency setting (not hardcoded), and
 * a genuinely-new property never needs a threshold at all.
 */
final class CoreMatchReasonClassifierTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Contact $contact;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Reason Co', 'slug' => 'reason-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->contact = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Reason', 'last_name' => 'Test']);
    }

    private function match(array $overrides = []): ContactMatch
    {
        return ContactMatch::create(array_merge([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'agent_id'           => $this->agent->id,
            'name'               => 'Wishlist',
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1_000_000,
            'price_max'          => 2_000_000,
        ], $overrides));
    }

    private function property(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->agent->id,
            'title'         => 'Listing',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'price'         => 1_500_000,
            'beds'          => 3,
            'garages'       => 1,
            'property_type' => 'House',
        ], $overrides));
    }

    private function audit(Property $property, array $old, array $new, ?\DateTimeInterface $at = null): void
    {
        PropertyAuditLog::create([
            'property_id'    => $property->id,
            'agency_id'      => $this->agency->id,
            'event_category' => 'property',
            'event_type'     => 'price_changed',
            'old_values'     => $old,
            'new_values'     => $new,
            'created_at'     => $at ?? now(),
        ]);
    }

    private function classifier(): CoreMatchReasonClassifier
    {
        return app(CoreMatchReasonClassifier::class);
    }

    public function test_a_property_created_after_the_last_share_is_classified_new(): void
    {
        $match = $this->match();
        $match->mintShareLink($this->agent->id)->confirmSent();
        $newProperty = $this->property(['title' => 'Fresh']);

        $result = $this->classifier()->classify($match, collect([$newProperty]));

        $this->assertSame(CoreMatchReasonClassifier::REASON_NEW, $result->first()['reason']);
    }

    public function test_never_shared_before_classifies_everything_new_regardless_of_age(): void
    {
        $match = $this->match();
        $old = $this->property(['created_at' => now()->subYear()]);

        $result = $this->classifier()->classify($match, collect([$old]));

        $this->assertSame(CoreMatchReasonClassifier::REASON_NEW, $result->first()['reason']);
    }

    public function test_a_price_drop_that_crosses_into_the_buyers_range_is_reduced(): void
    {
        $match = $this->match(['price_max' => 2_000_000]);
        $property = $this->property(['created_at' => now()->subMonths(3), 'price' => 1_900_000]);
        $share = $match->mintShareLink($this->agent->id)->confirmSent();

        // Was 2.3m (above the 2m ceiling) three weeks ago, now 1.9m — a real cut, crosses in.
        $this->audit($property, ['price' => 2_300_000], ['price' => 1_900_000], now());

        $result = $this->classifier()->classify($match, collect([$property]));

        $this->assertSame(CoreMatchReasonClassifier::REASON_REDUCED, $result->first()['reason']);
        $this->assertSame(2_300_000.0, $result->first()['meta']['old_price']);
        $this->assertSame(1_900_000.0, $result->first()['meta']['new_price']);
    }

    public function test_a_drop_below_the_agency_threshold_does_not_count_as_reduced(): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update(['core_matches_price_drop_threshold_pct' => 10]);
        $match = $this->match(['price_max' => 2_000_000]);
        $property = $this->property(['created_at' => now()->subMonths(3), 'price' => 1_999_000]);
        $match->mintShareLink($this->agent->id)->confirmSent();

        // Crosses the ceiling (2,001,000 -> 1,999,000) but the cut itself is ~0.1%, well under the 10% bar.
        $this->audit($property, ['price' => 2_001_000], ['price' => 1_999_000]);

        $result = $this->classifier()->classify($match, collect([$property]));

        $this->assertNotSame(CoreMatchReasonClassifier::REASON_REDUCED, $result->first()['reason'], 'a rounding-error drop must not read as newsworthy');
    }

    public function test_a_status_transition_back_into_matchable_after_the_last_share_is_back_on_market(): void
    {
        $match = $this->match();
        $property = $this->property(['created_at' => now()->subMonths(2), 'status' => 'active']);
        $share = $match->mintShareLink($this->agent->id)->confirmSent();

        PropertyAuditLog::create([
            'property_id' => $property->id, 'agency_id' => $this->agency->id,
            'event_category' => 'property', 'event_type' => 'property_updated',
            'old_values' => ['status' => 'withdrawn'], 'new_values' => ['status' => 'active'],
            'created_at' => now(),
        ]);

        $result = $this->classifier()->classify($match, collect([$property]));

        $this->assertSame(CoreMatchReasonClassifier::REASON_BACK_ON_MARKET, $result->first()['reason']);
    }

    public function test_unexplained_by_the_first_three_falls_back_to_criteria_widened(): void
    {
        $match = $this->match();
        $property = $this->property(['created_at' => now()->subMonths(6)]);
        $match->mintShareLink($this->agent->id)->confirmSent();

        $result = $this->classifier()->classify($match, collect([$property]));

        $this->assertSame(CoreMatchReasonClassifier::REASON_CRITERIA_WIDENED, $result->first()['reason']);
    }

    public function test_criteria_widened_is_excluded_from_the_buyer_visible_set(): void
    {
        $this->assertNotContains(CoreMatchReasonClassifier::REASON_CRITERIA_WIDENED, CoreMatchReasonClassifier::BUYER_VISIBLE_REASONS);
        $this->assertContains(CoreMatchReasonClassifier::REASON_NEW, CoreMatchReasonClassifier::BUYER_VISIBLE_REASONS);
        $this->assertContains(CoreMatchReasonClassifier::REASON_REDUCED, CoreMatchReasonClassifier::BUYER_VISIBLE_REASONS);
        $this->assertContains(CoreMatchReasonClassifier::REASON_BACK_ON_MARKET, CoreMatchReasonClassifier::BUYER_VISIBLE_REASONS);
    }
}
