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
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AT-444 follow-up 3 (2026-10-05) — Johan's ruling: CoreX drafts a renewal
 * itself once a lease enters the agency's reminder window, when it has
 * enough information to; the Command Centre's needs-action row either
 * says the draft is ready or names what's missing. §5/§9 of
 * .ai/specs/rental-renewals.md.
 */
final class LeaseRenewalDraftAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_copy_forwards_a_renewal_for_a_lease_esigned_through_corex(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $template = Template::create(['name' => 'Lease agreement', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        $document = EsignDocument::create(['name' => 'Signed lease', 'template_id' => $template->id, 'owner_id' => $agent->id, 'agency_id' => $agency->id]);
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
            'end_date' => now()->addDays(10)->toDateString(), 'created_by_user_id' => $agent->id,
        ]));

        Artisan::call('rentals:prepare-renewal-drafts');

        $draft = Lease::where('previous_lease_id', $lease->id)->first();
        self::assertNotNull($draft);
        self::assertSame(Lease::STATUS_DRAFT, $draft->status);
        self::assertNotNull($draft->renewal_draft_flow_id);
        self::assertTrue($lease->fresh()->hasPendingRenewalDraft());
    }

    public function test_command_drafts_from_template_when_no_esign_source_but_data_is_complete(): void
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
            'end_date' => now()->addDays(10)->toDateString(), 'created_by_user_id' => $agent->id,
        ]));
        $tenant = $this->makeContact($agency, $branch, 'Tenant', 'Renewing');
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);

        Artisan::call('rentals:prepare-renewal-drafts');

        $draft = Lease::where('previous_lease_id', $lease->id)->first();
        self::assertNotNull($draft);
        self::assertNotNull($draft->renewal_draft_flow_id);
    }

    public function test_command_reports_insufficient_info_and_creates_nothing_when_neither_path_is_eligible(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'manual',
            'end_date' => now()->addDays(10)->toDateString(), 'created_by_user_id' => $agent->id,
        ]));
        $before = Lease::count();

        Artisan::call('rentals:prepare-renewal-drafts');

        self::assertSame($before, Lease::count());
        self::assertFalse($lease->fresh()->hasPendingRenewalDraft());
        self::assertStringContainsString('NOT ENOUGH INFO', Artisan::output());
    }

    public function test_command_is_idempotent_a_second_run_does_not_double_draft(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $template = Template::create(['name' => 'Lease agreement', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        $document = EsignDocument::create(['name' => 'Signed lease', 'template_id' => $template->id, 'owner_id' => $agent->id, 'agency_id' => $agency->id]);
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
            'end_date' => now()->addDays(10)->toDateString(), 'created_by_user_id' => $agent->id,
        ]));

        Artisan::call('rentals:prepare-renewal-drafts');
        Artisan::call('rentals:prepare-renewal-drafts');

        self::assertSame(1, Lease::where('previous_lease_id', $lease->id)->count());
    }

    public function test_command_skips_a_lease_with_notice_already_given(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $template = Template::create(['name' => 'Lease agreement', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        $document = EsignDocument::create(['name' => 'Signed lease', 'template_id' => $template->id, 'owner_id' => $agent->id, 'agency_id' => $agency->id]);
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
            'end_date' => now()->addDays(10)->toDateString(), 'created_by_user_id' => $agent->id,
            'notice_date' => now()->toDateString(), 'notice_given_by' => Lease::NOTICE_BY_TENANT,
            'move_out_date' => now()->addDays(20)->toDateString(),
        ]));

        Artisan::call('rentals:prepare-renewal-drafts');

        self::assertFalse($lease->fresh()->hasPendingRenewalDraft());
    }

    public function test_command_skips_a_month_to_month_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $template = Template::create(['name' => 'Lease agreement', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        $document = EsignDocument::create(['name' => 'Signed lease', 'template_id' => $template->id, 'owner_id' => $agent->id, 'agency_id' => $agency->id]);
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
            'end_date' => null, 'is_month_to_month' => true, 'created_by_user_id' => $agent->id,
        ]));

        Artisan::call('rentals:prepare-renewal-drafts');

        self::assertFalse($lease->fresh()->hasPendingRenewalDraft());
    }

    public function test_command_skips_a_lease_outside_the_reminder_window(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $template = Template::create(['name' => 'Lease agreement', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        $document = EsignDocument::create(['name' => 'Signed lease', 'template_id' => $template->id, 'owner_id' => $agent->id, 'agency_id' => $agency->id]);
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
            'end_date' => now()->addYear()->toDateString(), 'created_by_user_id' => $agent->id,
        ]));

        Artisan::call('rentals:prepare-renewal-drafts');

        self::assertFalse($lease->fresh()->hasPendingRenewalDraft());
    }

    public function test_lease_option_restricts_the_run_to_one_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $agent = $this->makeUser($agency, $branch);
        $template = Template::create(['name' => 'Lease agreement', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        $document = EsignDocument::create(['name' => 'Signed lease', 'template_id' => $template->id, 'owner_id' => $agent->id, 'agency_id' => $agency->id]);

        $leaseA = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
            'end_date' => now()->addDays(10)->toDateString(), 'created_by_user_id' => $agent->id,
        ]));
        $leaseB = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
            'end_date' => now()->addDays(12)->toDateString(), 'created_by_user_id' => $agent->id,
        ]));

        Artisan::call('rentals:prepare-renewal-drafts', ['--lease' => $leaseA->id]);

        self::assertTrue($leaseA->fresh()->hasPendingRenewalDraft());
        self::assertFalse($leaseB->fresh()->hasPendingRenewalDraft());
    }

    public function test_eligibility_service_prefers_copy_forward_over_a_matching_template(): void
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
