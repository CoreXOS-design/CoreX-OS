<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Document as EsignDocument;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RenewalDraftEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-renewals.md §21 — RenewalDraftEligibilityService::decide()
 * is still live: the Command Centre's needs-action row uses it to tell an
 * agent what's missing before they click "Renew lease", and the renewal
 * screen's own preview uses the same GATE 1 check. No automatic process
 * calls this to CREATE a draft any more (rentals:prepare-renewal-drafts was
 * retired, Johan's ruling 2026-10-05) — these tests cover the decision
 * logic itself, independent of any caller.
 */
final class RenewalDraftEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefers_copy_forward_over_a_matching_template(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $esignTemplate = Template::create(['name' => 'Lease agreement', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        $document = EsignDocument::create(['name' => 'Signed lease', 'template_id' => $esignTemplate->id, 'owner_id' => $agent->id, 'agency_id' => $agency->id]);
        RentalLeaseTemplate::create([
            'agency_id' => $agency->id, 'name' => 'Residential lease', 'docuperfect_template_id' => $esignTemplate->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true,
        ]);
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
        ]));

        $decision = app(RenewalDraftEligibilityService::class)->decide($lease->fresh(), $agent);

        self::assertSame('copy_forward', $decision['outcome']);
    }

    public function test_drafts_from_template_when_no_esign_source_but_data_is_complete(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $docTemplate = Template::create(['name' => 'Agency lease template', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        RentalLeaseTemplate::create([
            'agency_id' => $agency->id, 'name' => 'Residential lease', 'docuperfect_template_id' => $docTemplate->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true,
        ]);
        $landlord = $this->makeContact($agency, $branch, 'Owner', 'Landlord');
        ContactPropertyLinker::link($landlord->id, $property->id, 'landlord');
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'manual',
        ]));
        $tenant = $this->makeContact($agency, $branch, 'Tenant', 'Renewing');
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);

        $decision = app(RenewalDraftEligibilityService::class)->decide($lease->fresh(), $agent);

        self::assertSame('draft_from_template', $decision['outcome']);
    }

    public function test_reports_insufficient_info_when_neither_path_is_eligible(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'manual',
        ]));

        $decision = app(RenewalDraftEligibilityService::class)->decide($lease->fresh(), $agent);

        self::assertSame('insufficient_info', $decision['outcome']);
        self::assertContains('No agency lease template configured', $decision['missing']);
    }

    /** @return array{0: Agency, 1: Branch, 2: Property} */
    private function makeAgencyBranchProperty(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'rental',
        ]);

        return [$agency, $branch, $property];
    }

    private function baseLeaseAttributes(Agency $agency, Branch $branch, Property $property, array $overrides = []): array
    {
        return array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000,
            'start_date' => now()->toDateString(),
            'source' => 'manual',
        ], $overrides);
    }

    private function makeContact(Agency $agency, Branch $branch, string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first . '.' . $last) . '-' . uniqid() . '@example.test',
        ]);
    }

    private function makeUser(Agency $agency, Branch $branch): User
    {
        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }
}
