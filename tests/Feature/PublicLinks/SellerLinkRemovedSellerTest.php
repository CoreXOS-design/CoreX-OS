<?php

namespace Tests\Feature\PublicLinks;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec .ai/specs/seller-live-link.md, "When the seller is removed" (audit 2026-09-13):
 * a seller taken off a property must no longer be able to open the seller live link;
 * re-linking them switches the same link back on; a manual Revoke is permanent.
 */
class SellerLinkRemovedSellerTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin',
        ]);
    }

    private function makeProperty(): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Secret Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000, 'published_at' => now(),
        ]);
    }

    private function makeContact(): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Seller', 'last_name' => Str::random(4),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'seller-' . Str::random(5) . '@example.co.za',
        ]);
    }

    private function linkedSeller(Property $property, Contact $contact, string $role = 'seller'): PropertySellerLink
    {
        ContactPropertyLinker::link($contact->id, $property->id, $role);

        return PropertySellerLink::ensureExists($property->id, $contact->id, $this->user->id);
    }

    public function test_link_works_while_the_seller_is_on_the_property(): void
    {
        $property = $this->makeProperty();
        $link = $this->linkedSeller($property, $this->makeContact());

        $this->get('/property/live/' . $link->token)->assertStatus(200);
    }

    public function test_removing_the_seller_switches_the_link_off_without_deleting_anything(): void
    {
        $property = $this->makeProperty();
        $contact = $this->makeContact();
        $link = $this->linkedSeller($property, $contact);

        ContactPropertyLinker::unlink($contact->id, $property->id);

        $response = $this->get('/property/live/' . $link->token);
        $response->assertStatus(410);
        $response->assertDontSee($property->title);
        // Switched off, not shredded: the link row is still there, and nothing was recorded as a visit.
        $this->assertSame(0, (int) PropertySellerLink::withoutGlobalScopes()->find($link->id)->access_count);
    }

    public function test_relinking_the_seller_switches_the_same_link_back_on(): void
    {
        $property = $this->makeProperty();
        $contact = $this->makeContact();
        $link = $this->linkedSeller($property, $contact);

        ContactPropertyLinker::unlink($contact->id, $property->id);
        $this->get('/property/live/' . $link->token)->assertStatus(410);

        ContactPropertyLinker::link($contact->id, $property->id, 'seller');
        PropertySellerLink::ensureExists($property->id, $contact->id, $this->user->id);

        $this->get('/property/live/' . $link->token)->assertStatus(200);
        $this->assertSame(1, PropertySellerLink::withoutGlobalScopes()->where('property_id', $property->id)->count(),
            'Re-linking must reuse the same link, not issue a second one.');
    }

    public function test_a_manual_revoke_is_never_undone_by_relinking(): void
    {
        $property = $this->makeProperty();
        $contact = $this->makeContact();
        $link = $this->linkedSeller($property, $contact);
        $link->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->user->id]);

        ContactPropertyLinker::unlink($contact->id, $property->id);
        ContactPropertyLinker::link($contact->id, $property->id, 'seller');

        $this->get('/property/live/' . $link->token)->assertStatus(410);
    }

    public function test_changing_the_contact_to_a_non_seller_role_switches_the_link_off(): void
    {
        $property = $this->makeProperty();
        $contact = $this->makeContact();
        $link = $this->linkedSeller($property, $contact);

        ContactPropertyLinker::link($contact->id, $property->id, 'buyer');

        $this->get('/property/live/' . $link->token)->assertStatus(410);
    }

    public function test_only_the_removed_sellers_link_is_switched_off(): void
    {
        $property = $this->makeProperty();
        $stays = $this->makeContact();
        $goes = $this->makeContact();
        $staysLink = $this->linkedSeller($property, $stays);
        $goesLink = $this->linkedSeller($property, $goes);

        ContactPropertyLinker::unlink($goes->id, $property->id);

        $this->get('/property/live/' . $goesLink->token)->assertStatus(410);
        $this->get('/property/live/' . $staysLink->token)->assertStatus(200);

        $held = PropertySellerLink::withoutGlobalScopes()->where('property_id', $property->id)->stillHeld()->pluck('id')->all();
        $this->assertSame([$staysLink->id], $held, 'The property page lists only links still in force.');
    }

    public function test_a_legacy_link_with_no_contact_property_row_is_not_treated_as_removed(): void
    {
        $property = $this->makeProperty();
        $contact = $this->makeContact();
        $link = PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id, 'contact_id' => $contact->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $this->user->id,
            'generated_at' => now(),
        ]);

        $this->get('/property/live/' . $link->token)->assertStatus(200);
    }
}
