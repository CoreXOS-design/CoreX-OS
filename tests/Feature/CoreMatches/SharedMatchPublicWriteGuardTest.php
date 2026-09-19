<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchFeedback;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prod-promotion audit 2026-09-16, M9 — the public shared-match link.
 *
 *  - Opening the page (an unauthenticated GET) must not INSERT the
 *    agency's settings row (it did, twice over: the reason classifier's
 *    threshold lookup and ContactMatch::isCountable()'s min-criteria
 *    lookup both went through forAgency()'s firstOrCreate).
 *  - The two public MUTATING endpoints (record-view, feedback) must refuse
 *    a property that is not this share's to put in front of the buyer
 *    (another agency's stock, a hidden listing, a made-up id) and must
 *    refuse to mutate a Won/Lost buyer at all — the same isBuyerActive()
 *    rule the page itself applies, which these endpoints used to skip.
 */
final class SharedMatchPublicWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $otherAgency;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency      = Agency::create(['name' => 'Public Guard Co', 'slug' => 'pg-' . uniqid()]);
        $this->otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'og-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent',
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Guard', 'last_name' => 'Test',
            'created_by_user_id' => $this->agent->id, 'is_buyer' => true, 'buyer_state' => 'warm',
        ]);
    }

    private function match(): ContactMatch
    {
        return ContactMatch::create([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'name'               => 'Test wishlist',
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1_500_000,
            'price_max'          => 2_500_000,
            'beds_min'           => 3,
        ]);
    }

    private function property(int $agencyId, array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $agencyId,
            'agent_id'      => $this->agent->id,
            'title'         => 'Test listing',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'price'         => 2_000_000,
            'beds'          => 3,
            'garages'       => 1,
            'property_type' => 'House',
        ], $overrides));
    }

    private function feedback(ContactMatch $match, int $propertyId)
    {
        return $this->postJson(
            route('shared.match.feedback', ['token' => $match->share_token, 'property' => $propertyId]),
            ['reaction' => 'interested'],
        );
    }

    private function recordView(ContactMatch $match, int $propertyId)
    {
        return $this->getJson(route('shared.match.view', ['token' => $match->share_token, 'property' => $propertyId]));
    }

    public function test_opening_the_public_page_does_not_insert_an_agency_settings_row(): void
    {
        $match = $this->match();
        $this->property($this->agency->id);
        // Fixture creation itself (the contact duplicate check on Contact::create)
        // legitimately resolves the row through forAgency(); clear it so the
        // assertion below is about the public GET alone.
        AgencyContactSettings::withoutGlobalScopes()->where('agency_id', $this->agency->id)->forceDelete();
        $this->assertSame(0, AgencyContactSettings::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());

        $this->get(route('shared.match', ['token' => $match->share_token]))->assertOk();

        $this->assertSame(
            0,
            AgencyContactSettings::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count(),
            'an unauthenticated GET must never create the agency settings row'
        );
    }

    public function test_feedback_on_a_property_of_the_matchs_own_agency_is_accepted(): void
    {
        $match    = $this->match();
        $property = $this->property($this->agency->id);

        $this->feedback($match, $property->id)->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, ContactMatchFeedback::withoutGlobalScopes()->where('contact_match_id', $match->id)->count());
    }

    public function test_feedback_on_another_agencys_property_is_404_and_writes_nothing(): void
    {
        $match   = $this->match();
        $foreign = $this->property($this->otherAgency->id);

        $this->feedback($match, $foreign->id)->assertNotFound();

        $this->assertSame(0, ContactMatchFeedback::withoutGlobalScopes()->count());
        $this->assertNull($match->fresh()->last_engaged_at);
    }

    public function test_feedback_on_a_nonexistent_property_id_is_404(): void
    {
        $match = $this->match();

        $this->feedback($match, 999_999)->assertNotFound();

        $this->assertSame(0, ContactMatchFeedback::withoutGlobalScopes()->count());
    }

    public function test_feedback_on_a_property_the_agent_hid_from_this_match_is_404(): void
    {
        $match    = $this->match();
        $property = $this->property($this->agency->id);
        $match->update(['hidden_property_ids' => [$property->id]]);

        $this->feedback($match, $property->id)->assertNotFound();

        $this->assertSame(0, ContactMatchFeedback::withoutGlobalScopes()->count());
    }

    public function test_record_view_on_a_foreign_property_is_404_and_counts_nothing(): void
    {
        $match   = $this->match();
        $foreign = $this->property($this->otherAgency->id);

        $this->recordView($match, $foreign->id)->assertNotFound();

        $this->assertSame(0, $match->fresh()->propertyViewCount($foreign->id));
        $this->assertNull($match->fresh()->last_engaged_at);
    }

    public function test_record_view_on_own_agency_stock_still_counts(): void
    {
        $match    = $this->match();
        $property = $this->property($this->agency->id);

        $this->recordView($match, $property->id)->assertOk()->assertJson(['ok' => true, 'count' => 1]);
    }

    public function test_a_won_buyers_link_cannot_record_feedback_or_views(): void
    {
        $match    = $this->match();
        $property = $this->property($this->agency->id);
        $this->contact->update(['buyer_state' => 'won']);

        $this->feedback($match, $property->id)->assertNotFound();
        $this->recordView($match, $property->id)->assertNotFound();

        $this->assertSame(0, ContactMatchFeedback::withoutGlobalScopes()->count());
        $this->assertSame(0, $match->fresh()->propertyViewCount($property->id));
    }

    public function test_a_set_aside_lost_buyers_link_cannot_record_feedback_or_views(): void
    {
        $match    = $this->match();
        $property = $this->property($this->agency->id);
        $match->setAside();

        $this->feedback($match, $property->id)->assertNotFound();
        $this->recordView($match, $property->id)->assertNotFound();

        $this->assertSame(0, ContactMatchFeedback::withoutGlobalScopes()->count());
    }

    public function test_a_property_snapshotted_into_a_confirmed_share_stays_reactable_after_it_is_withdrawn(): void
    {
        $match    = $this->match();
        $property = $this->property($this->agency->id);
        $match->mintShareLink($this->agent->id)->confirmSent('whatsapp');
        $property->delete(); // withdrawn since the send — no longer live stock

        $this->feedback($match, $property->id)->assertOk();
    }
}
