<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\RentalApplicationApprovedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\RentalApplications\RentalApplicationPropertyMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * QA1, 2026-10-07 — Johan's test of application 438. The approval email listed
 * the suggested properties as plain text with no link, gave no suburb, and
 * suggested a "1 Bedroom Commercial Property" to a residential tenant.
 *
 *  - every suggested property is a link to its public listing page, built from
 *    the application's OWN agency slug;
 *  - every line names the suburb (never the street address);
 *  - the "View all properties that match" button appears only when the tenant
 *    has a shareable rental wishlist (none here, so it must be absent);
 *  - commercial / vacant-land stock is never suggested to a residential tenant,
 *    including the 33 QA1 rows filed category "Residential" with property_type
 *    "Commercial Property", and the approved amount stays a hard ceiling.
 */
final class RentalApplicationApprovedMailLinksTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private int $branchId;
    private int $agentId;
    private RentalApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'second-agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->branchId = $branch->id;
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->agentId = $agent->id;
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Ayanda', 'last_name' => 'T', 'email' => 'ayanda@example.test',
        ]);
        $this->application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'approved', 'submitted_at' => now()->subDay(),
            'approved_rental_amount' => 10000,
        ]);
    }

    private function property(array $attrs = []): Property
    {
        $p = Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchId, 'agent_id' => $this->agentId,
            'title' => 'Test property ' . uniqid(), 'status' => 'to_let', 'listing_type' => 'rental',
            'rental_amount' => 7500, 'beds' => 2, 'property_type' => 'Apartment / Flat', 'category' => 'Residential',
            'suburb' => 'Uvongo', 'town' => 'Margate', 'city' => 'Margate',
            'street_number' => '12', 'street_name' => 'Hidden Road',
        ], $attrs));
        // Marketability is a separate compliance concern; a snapshot marks it ready.
        $p->forceFill(['compliance_snapshot_at' => now()])->save();

        return $p->fresh();
    }

    public function test_every_suggested_property_is_a_link_to_its_public_page_with_the_suburb_and_no_street(): void
    {
        $a = $this->property(['suburb' => 'Uvongo']);
        $b = $this->property(['suburb' => 'Shelly Beach', 'beds' => 1, 'rental_amount' => 6500]);

        $html = (new RentalApplicationApprovedMail($this->application->fresh(), new Collection([$a, $b]), false, null))->render();

        foreach ([$a, $b] as $p) {
            $url = route('public.agency.properties.show', ['agencySlug' => $this->agency->slug, 'property' => $p->id]);
            self::assertStringContainsString('href="' . $url . '"', $html, 'each suggested property must be a clickable link to its public listing page');
            self::assertStringContainsString($p->suburb, $html, 'each line must say where the property is');
            self::assertStringContainsString($p->addressFreeDescriptor(), $html);
        }
        self::assertStringNotContainsString('Hidden Road', $html, 'the street address must still never appear in the email');
        self::assertStringNotContainsString('View all properties that match', $html, 'no rental wishlist, so no shared-wishlist button');
    }

    public function test_commercial_and_vacant_land_stock_is_never_suggested_to_a_residential_tenant(): void
    {
        $residential = $this->property(['rental_amount' => 9000]);
        $commercialCategory = $this->property(['category' => 'Commercial', 'property_type' => 'Commercial', 'rental_amount' => 9500]);
        // The real data quirk: filed Residential but typed Commercial Property.
        $commercialType = $this->property(['category' => 'Residential', 'property_type' => 'Commercial Property', 'beds' => 1, 'rental_amount' => 9400]);
        $land = $this->property(['category' => 'Residential', 'property_type' => 'Vacant Land / Plot', 'beds' => 0, 'rental_amount' => 9300]);

        $ids = app(RentalApplicationPropertyMatcher::class)->forApproval($this->application->fresh())->pluck('id')->all();

        self::assertContains($residential->id, $ids);
        foreach ([$commercialCategory, $commercialType, $land] as $p) {
            self::assertNotContains($p->id, $ids, "{$p->property_type} ({$p->category}) must not be offered to a residential tenant");
        }
    }

    public function test_only_open_rentals_within_the_approved_amount_are_suggested(): void
    {
        $ok = $this->property(['rental_amount' => 9999]);
        $tooDear = $this->property(['rental_amount' => 10001]);
        $letOut = $this->property(['status' => 'let_out', 'rental_amount' => 5000]);
        $forSale = $this->property(['listing_type' => 'sale', 'price' => 900000, 'rental_amount' => 5000]);

        $ids = app(RentalApplicationPropertyMatcher::class)->forApproval($this->application->fresh())->pluck('id')->all();

        self::assertSame([$ok->id], $ids);
        foreach ([$tooDear, $letOut, $forSale] as $p) {
            self::assertNotContains($p->id, $ids);
        }
    }

    public function test_a_property_whose_public_page_would_be_unavailable_is_not_suggested(): void
    {
        $open = $this->property(['rental_amount' => 9000]);
        $notReady = $this->property(['rental_amount' => 9500]);
        $notReady->forceFill(['compliance_snapshot_at' => null])->save();

        $ids = app(RentalApplicationPropertyMatcher::class)->forApproval($this->application->fresh())->pluck('id')->all();

        self::assertContains($open->id, $ids);
        self::assertNotContains($notReady->id, $ids, 'the email links to the public page, so it must not suggest a listing that page would refuse to show');
    }

    public function test_residential_check_reads_both_category_and_type(): void
    {
        self::assertTrue(RentalApplicationPropertyMatcher::isResidential(new Property(['category' => 'Residential', 'property_type' => 'House'])));
        self::assertTrue(RentalApplicationPropertyMatcher::isResidential(new Property(['category' => null, 'property_type' => 'house'])));
        self::assertFalse(RentalApplicationPropertyMatcher::isResidential(new Property(['category' => 'Commercial', 'property_type' => 'Apartment / Flat'])));
        self::assertFalse(RentalApplicationPropertyMatcher::isResidential(new Property(['category' => 'Residential', 'property_type' => 'Commercial Property'])));
        self::assertFalse(RentalApplicationPropertyMatcher::isResidential(new Property(['category' => 'Residential', 'property_type' => 'VacantLand'])));
    }
}
