<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-30 (Johan, urgent) — the per-property Share button vanished from
 * Core Matches results cards on QA1 for any property whose status the
 * status-allow-list feature (c54eb654f, 2026-09-29) newly made matchable but
 * that this partial's own $shareableStatuses list never tracked (expired,
 * Other Agency Stock). Not a deliberate OAS gate — Johan's ruling: "the whole
 * point of importing Other Agency Stock is to share it with clients... every
 * listing that appears in the results" gets a working Share button.
 *
 * Proves: a property reaching the results page via ClientMatchResolver gets
 * its Share button regardless of status (active, expired, other_agency_stock
 * all covered) as long as the viewer holds properties.share; the property
 * show page's OWN Share button (a different call site, no fromCoreMatches)
 * keeps its narrower, pre-existing behaviour untouched.
 */
final class ContactMatchResultsShareButtonTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Share Button Co', 'slug' => 'sb-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'first_name' => 'Share', 'last_name' => 'Button',
        ]);

        // The routes under test sit behind access_contacts (contact matches group) and
        // access_properties (property show) — without them every request is a 403
        // before the Share button is ever reached.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_contacts', 'agency_id' => null]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_properties', 'agency_id' => null]);
        // The property show page then applies the within-agency data scope; once any grant row
        // exists a role with no stored 'properties.view' scope sees nothing (fail-closed), so
        // the agent is given 'own' — the listings below are all theirs.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'scope' => 'own', 'agency_id' => null]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.share', 'agency_id' => null]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_core_matches', 'agency_id' => null]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'core_matches.view', 'agency_id' => null]);
    }

    private function property(array $overrides = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id'     => $this->agency->id,
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

    private function matchFor(array $overrides = []): ContactMatch
    {
        return ContactMatch::create(array_merge([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $this->contact->id,
            'created_by_user_id' => $this->agent->id,
            'agent_id'           => $this->agent->id,
            'name'               => 'Test wishlist',
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
            'price_min'          => 1,
            'price_max'          => 999_999_999,
        ], $overrides));
    }

    public function test_share_button_renders_for_an_active_property_in_core_matches_results(): void
    {
        $this->property(['status' => 'active', 'price' => 2_000_000]);
        $match = $this->matchFor();

        $this->actingAs($this->agent)
            ->get(route('corex.contacts.matches.results', [$this->contact, $match]))
            ->assertOk()
            ->assertSee('Share a link to this listing', false);
    }

    public function test_share_button_renders_for_an_other_agency_stock_property_in_core_matches_results(): void
    {
        $this->property(['status' => Property::STATUS_OTHER_AGENCY_STOCK, 'price' => 2_000_000]);
        $match = $this->matchFor();

        $this->actingAs($this->agent)
            ->get(route('corex.contacts.matches.results', [$this->contact, $match]))
            ->assertOk()
            ->assertSee('Share a link to this listing', false);
    }

    public function test_share_button_renders_for_an_expired_property_in_core_matches_results(): void
    {
        $this->property(['status' => 'expired', 'price' => 2_000_000]);
        $match = $this->matchFor();

        $this->actingAs($this->agent)
            ->get(route('corex.contacts.matches.results', [$this->contact, $match]))
            ->assertOk()
            ->assertSee('Share a link to this listing', false);
    }

    public function test_property_show_page_still_hides_share_for_a_non_shareable_status(): void
    {
        // Different call site (no fromCoreMatches) — its own, pre-existing,
        // narrower status gate is untouched by this fix.
        $property = $this->property(['status' => 'expired']);

        $this->actingAs($this->agent)
            ->get(route('corex.properties.show', $property))
            ->assertOk()
            ->assertDontSee('Share a link to this listing', false);
    }
}
