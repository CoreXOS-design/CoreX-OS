<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\ContactType;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan's contact-type ruling, 2026-09-11, verbatim: "contact type can be
 * added, not changed. the scenario exists where a seller or any contact
 * type can become a tenant. the scenario exists that the seller of unit a
 * decides to rent but their property has not sold yet. so that contact
 * will be dealt with as a seller on their property but also as a tenant
 * inside rentals."
 *
 * Approving a rental application ADDS "Tenant" (the one existing
 * ContactType row already used by portal lead capture — never a second,
 * duplicate row) to the linked contact's types via the SAME mechanism
 * PromoteOwnerToSellerOnPropertyLink already uses for auto-tag-on-link,
 * used here as a pure add rather than a swap. Decline/withdrawal never
 * tag anything. Whether approval tags at all is agency-configurable
 * (default on).
 */
final class RentalApplicationTenantTypeOnApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $co;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->co = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agency->update(['rental_application_co_user_ids' => [$this->co->id]]);
    }

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho-' . uniqid() . '@example.co.za',
        ]);
    }

    private function pendingApplication(Contact $contact): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->co->id, 'status' => 'under_assessment', 'submitted_for_approval_at' => now(),
            'full_name' => $contact->full_name, 'email' => 'applicant-' . uniqid() . '@example.co.za',
        ]);
    }

    private function approve(RentalApplication $app, float $amount = 15000): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->co)->post(
            route('corex.rental-applications.authorisation.approve', $app),
            ['approved_rental_amount' => $amount],
        );
    }

    private function decline(RentalApplication $app): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->co)->post(
            route('corex.rental-applications.authorisation.decline', $app),
            ['reason' => 'Does not qualify.'],
        );
    }

    private function tenantTypeId(): int
    {
        return (int) ContactType::where('name', 'Tenant')->firstOrFail()->id;
    }

    /**
     * The Seller ContactType row is seeded by a March-2026 data migration
     * (2026_03_07_200002_seed_contact_types_seller_buyer_witness) whose
     * effect predates this test suite's own schema snapshot — its data
     * never landed in the snapshot even though the migration itself is
     * recorded as already run (a pre-existing test-infra gap, unrelated to
     * this feature — reported separately, not fixed here). firstOrCreate
     * with the migration's own values keeps this test self-sufficient
     * regardless of that gap.
     */
    private function sellerType(): ContactType
    {
        return ContactType::firstOrCreate(
            ['name' => 'Seller', 'esign_role' => 'seller'],
            ['color' => '#e67e22', 'sort_order' => 3, 'is_active' => true],
        );
    }

    private function effectiveTypeIds(Contact $contact): array
    {
        $contact->refresh();
        $ids = $contact->parentTypes()->pluck('contact_types.id')->map(fn ($i) => (int) $i)->all();
        if ($contact->contact_type_id) {
            $ids[] = (int) $contact->contact_type_id;
        }

        return array_values(array_unique($ids));
    }

    // ── Core rule ──────────────────────────────────────────────────────

    public function test_approval_adds_tenant_to_a_contact_with_no_existing_type(): void
    {
        $contact = $this->contact();
        $this->assertEmpty($this->effectiveTypeIds($contact));
        $app = $this->pendingApplication($contact);

        $this->approve($app)->assertRedirect();

        $this->assertContains($this->tenantTypeId(), $this->effectiveTypeIds($contact));
    }

    public function test_approval_adds_tenant_to_a_seller_without_removing_seller(): void
    {
        $contact = $this->contact();
        $seller = $this->sellerType();
        $contact->syncTypeAssignments([$seller->id], []);
        $this->assertSame([(int) $seller->id], $this->effectiveTypeIds($contact));

        $app = $this->pendingApplication($contact);
        $this->approve($app)->assertRedirect();

        $types = $this->effectiveTypeIds($contact);
        $this->assertContains((int) $seller->id, $types, 'Seller must still be there — approval adds, never replaces');
        $this->assertContains($this->tenantTypeId(), $types, 'Tenant must now also be there');
        $this->assertCount(2, $types, 'exactly Seller + Tenant, nothing lost, nothing extra');
    }

    /**
     * Johan's own named scenario: the seller of unit A rents elsewhere
     * while unit A hasn't sold. The property-side relationship (who is the
     * seller ON THAT PROPERTY, via the contact_property pivot's own `role`
     * column — a completely separate system from contact_type_id/
     * parentTypes) must still work after the contact also becomes a
     * Tenant via the rental path.
     */
    public function test_the_sellers_own_property_relationship_still_works_after_they_also_become_a_tenant(): void
    {
        $contact = $this->contact();
        $seller = $this->sellerType();
        $contact->syncTypeAssignments([$seller->id], []);

        $property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->co->id,
            'title' => 'Unit A', 'status' => 'active', 'property_type' => 'flat', 'listing_type' => 'sale',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => 'Unit A, 1 Beach Road',
        ]);
        $property->contacts()->attach($contact->id, ['role' => 'seller']);

        $app = $this->pendingApplication($contact);
        $this->approve($app)->assertRedirect();

        $this->assertContains($this->tenantTypeId(), $this->effectiveTypeIds($contact));
        $this->assertTrue(
            $property->contacts()->wherePivot('contact_id', $contact->id)->wherePivot('role', 'seller')->exists(),
            'the seller-on-their-own-property pivot relationship must be completely unaffected by the contact also becoming a Tenant elsewhere'
        );
    }

    public function test_approving_the_same_application_twice_does_not_duplicate_the_tenant_type(): void
    {
        $contact = $this->contact();
        $app = $this->pendingApplication($contact);

        $this->approve($app)->assertRedirect();
        $types1 = $this->effectiveTypeIds($contact);

        // Re-fire the same domain event directly (an authoriser cannot
        // literally re-approve an already-approved application through the
        // real endpoint, but the event/listener contract itself — the thing
        // actually responsible for idempotency — must tolerate being
        // invoked twice for the same contact without ever duplicating).
        event(new \App\Events\RentalApplication\RentalApplicationApproved($app->fresh(), $this->co->id));
        $types2 = $this->effectiveTypeIds($contact);

        $this->assertSame($types1, $types2, 'a second tag attempt must change nothing');
        $this->assertSame(
            1,
            $contact->parentTypes()->where('contact_types.id', $this->tenantTypeId())->count(),
            'the pivot row itself must never be duplicated'
        );
    }

    public function test_existing_sub_tags_are_preserved_when_tenant_is_added(): void
    {
        $contact = $this->contact();
        $seller = $this->sellerType();
        $tag = ContactTag::create([
            'contact_type_id' => $seller->id, 'name' => 'VIP Seller ' . uniqid(),
            'color' => '#6366f1', 'sort_order' => 0, 'is_active' => true,
        ]);
        $contact->syncTypeAssignments([$seller->id], [$tag->id]);

        $app = $this->pendingApplication($contact);
        $this->approve($app)->assertRedirect();

        $contact->refresh();
        $this->assertTrue(
            $contact->tags()->where('contact_tags.id', $tag->id)->exists(),
            'an existing sub-tag must survive the contact also becoming a Tenant'
        );
    }

    // ── Decline/withdrawal never tag ──────────────────────────────────────

    public function test_decline_never_tags_the_contact(): void
    {
        $contact = $this->contact();
        $app = $this->pendingApplication($contact);

        $this->decline($app)->assertRedirect();

        $this->assertNotContains($this->tenantTypeId(), $this->effectiveTypeIds($contact));
    }

    // ── Agency setting ─────────────────────────────────────────────────

    public function test_default_is_on_when_the_agency_has_never_configured_this(): void
    {
        $this->assertNull(RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->first());
        $this->assertTrue(RentalApplicationQualifyingSetting::tagContactAsTenantOnApprovalFor($this->agency->id));
    }

    public function test_turning_the_setting_off_stops_approval_from_tagging_anything(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['tag_contact_as_tenant_on_approval' => false],
        );

        $contact = $this->contact();
        $app = $this->pendingApplication($contact);
        $this->approve($app)->assertRedirect();

        $this->assertNotContains($this->tenantTypeId(), $this->effectiveTypeIds($contact));
    }

    public function test_the_settings_screen_saves_the_toggle(): void
    {
        $this->actingAs($this->co)->post(route('corex.settings.rental-applications.tenant-tagging'), [
            'tag_contact_as_tenant_on_approval' => '0',
        ])->assertRedirect();

        $this->assertFalse(RentalApplicationQualifyingSetting::tagContactAsTenantOnApprovalFor($this->agency->id));
    }
}
