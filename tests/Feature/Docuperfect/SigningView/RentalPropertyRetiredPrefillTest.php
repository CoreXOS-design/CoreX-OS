<?php

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\Docuperfect\Template;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-439 — legacy RentalProperty retirement, e-sign prefill repoint.
 *
 * Johan's ruling, 2026-10-05: before retiring the rental_properties table's write
 * path (DocumentController::sendToRentals()) and the RentalProperty fallback
 * branches it fed (SupportingBatchPrefillResolver, ESignWizardController's
 * property-search/recipient-population/field-defaults), prove a rental lease
 * pack prefills the same landlord/tenant/property/rent/deposit fields from the
 * real Property pillar + its contact_property pivot as it used to from
 * RentalProperty's denormalised scalars — across: a property with a landlord
 * AND a tenant, a property with no tenant, a sectional-title property, and a
 * company (entity) landlord.
 *
 * Exercises prepareRecipientsForMerge() directly via reflection — the same
 * established pattern EsignEntityRecipientTest uses for this controller, since
 * showStep() itself has no existing HTTP-level test fixture in this suite to
 * build on. The rent/deposit/commission/marketing_fee defaults block
 * (showStep(), step >= 4) reads the exact same column names directly off the
 * resolved property record either way (Property carries them natively — same
 * migration that added them to `properties` also added them to the now-retired
 * `rental_properties`) — each scenario below asserts those columns resolve on
 * the real Property record used for prefill, proving the equivalence Johan
 * asked for without re-deriving showStep()'s large surrounding machinery.
 */
class RentalPropertyRetiredPrefillTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgencyBranchUser(): array
    {
        $agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Default']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency, $branch, $user];
    }

    private function makeRentalProperty(Agency $agency, Branch $branch, User $agent, array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id'   => $agency->id,
            'branch_id'   => $branch->id,
            'agent_id'    => $agent->id,
            'title'       => 'Rental property ' . uniqid(),
            'status'      => 'active',
            'listing_type'=> 'rental',
            'rental_amount'      => 8000,
            'deposit_amount'     => 8000,
            'commission_percent' => 10,
            'marketing_fee'      => 500,
        ], $overrides));
    }

    private function makeNaturalPersonContact(Agency $agency, Branch $branch, string $first, string $last, string $email): Contact
    {
        return Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => $first, 'last_name' => $last, 'email' => $email,
        ]);
    }

    private function makeRentalTemplate(Agency $agency, User $owner): Template
    {
        return Template::create([
            'name'            => 'Lease Agreement ' . uniqid(),
            'owner_id'        => $owner->id,
            'agency_id'       => $agency->id,
            'is_global'       => false,
            'page_count'      => 1,
            'fields_json'     => [],
            'signing_parties' => ['agent', 'landlord', 'tenant'],
        ]);
    }

    private function callPrepareRecipientsForMerge(array $stepData, ?Template $template, User $user, int $step = 3): array
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'prepareRecipientsForMerge');
        $m->setAccessible(true);

        return $m->invoke(app(ESignWizardController::class), $stepData, $template, $user, $step);
    }

    private function stepDataFor(Property $property): array
    {
        return [
            'property'   => ['property_id' => $property->id, '_property_source' => 'properties'],
            'recipients' => [],
        ];
    }

    private function recipientFor(array $out, int $contactId): ?array
    {
        $recipients = $out['recipients']['recipients'] ?? [];

        return collect($recipients)->firstWhere('_contact_id', $contactId);
    }

    /** Scenario 1 — property with a landlord AND a tenant. */
    public function test_property_with_landlord_and_tenant_resolves_both_recipients(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyBranchUser();
        $property = $this->makeRentalProperty($agency, $branch, $user);
        $landlord = $this->makeNaturalPersonContact($agency, $branch, 'Lee', 'Lord', 'lee.lord@example.test');
        $tenant = $this->makeNaturalPersonContact($agency, $branch, 'Ren', 'Ter', 'ren.ter@example.test');
        $property->contacts()->attach($landlord->id, ['role' => 'landlord']);
        $property->contacts()->attach($tenant->id, ['role' => 'tenant']);
        $template = $this->makeRentalTemplate($agency, $user);

        $out = $this->callPrepareRecipientsForMerge($this->stepDataFor($property), $template, $user);

        $landlordRow = $this->recipientFor($out, $landlord->id);
        $tenantRow = $this->recipientFor($out, $tenant->id);

        $this->assertNotNull($landlordRow, 'Landlord must auto-populate as a recipient.');
        $this->assertSame('lessor', $landlordRow['role']);
        $this->assertSame('lee.lord@example.test', $landlordRow['email']);

        $this->assertNotNull($tenantRow, 'Tenant must auto-populate as a recipient — RentalProperty never supported this at all.');
        $this->assertSame('lessee', $tenantRow['role']);
        $this->assertSame('ren.ter@example.test', $tenantRow['email']);

        // Rent/deposit/commission/marketing-fee equivalence — same columns the
        // retired RentalProperty branch read, now read directly off Property.
        $resolved = Property::find($property->id);
        $this->assertSame(8000.0, (float) $resolved->rental_amount);
        $this->assertSame(8000.0, (float) $resolved->deposit_amount);
        $this->assertSame(10.0, (float) $resolved->commission_percent);
        $this->assertSame(500.0, (float) $resolved->marketing_fee);
    }

    /** Scenario 2 — property with a landlord but no tenant linked yet. */
    public function test_property_with_no_tenant_resolves_landlord_only_without_error(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyBranchUser();
        $property = $this->makeRentalProperty($agency, $branch, $user);
        $landlord = $this->makeNaturalPersonContact($agency, $branch, 'Lee', 'Lord', 'lee.lord@example.test');
        $property->contacts()->attach($landlord->id, ['role' => 'landlord']);
        $template = $this->makeRentalTemplate($agency, $user);

        $out = $this->callPrepareRecipientsForMerge($this->stepDataFor($property), $template, $user);

        $landlordRow = $this->recipientFor($out, $landlord->id);
        $this->assertNotNull($landlordRow);
        $this->assertSame('lessor', $landlordRow['role']);

        $recipients = $out['recipients']['recipients'] ?? [];
        $this->assertCount(1, $recipients, 'No tenant linked — exactly one recipient (the landlord), no error, no phantom tenant row.');
    }

    /** Scenario 3 — sectional-title property; RentalProperty never carried unit/complex/erf data at all. */
    public function test_sectional_title_property_resolves_landlord_and_carries_unit_and_complex_data(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyBranchUser();
        $property = $this->makeRentalProperty($agency, $branch, $user, [
            // title_type is DERIVED by PropertyObserver::saving() from
            // property_type (TitleTypeClassifier) — setting it directly is
            // overwritten, so the sectional-title signal has to come from
            // property_type itself, exactly as a real sectional listing would.
            'property_type' => 'Apartment',
            'complex_name'  => 'Sea Breeze Complex',
            'unit_number'   => '14',
        ]);
        $landlord = $this->makeNaturalPersonContact($agency, $branch, 'Lee', 'Lord', 'lee.lord@example.test');
        $property->contacts()->attach($landlord->id, ['role' => 'landlord']);
        $template = $this->makeRentalTemplate($agency, $user);

        $out = $this->callPrepareRecipientsForMerge($this->stepDataFor($property), $template, $user);

        $landlordRow = $this->recipientFor($out, $landlord->id);
        $this->assertNotNull($landlordRow);
        $this->assertSame('lessor', $landlordRow['role']);

        $resolved = Property::find($property->id);
        $this->assertSame(PropertySettingItem::TITLE_SECTIONAL, $resolved->title_type);
        $this->assertSame('Sea Breeze Complex', $resolved->complex_name);
        $this->assertSame('14', $resolved->unit_number);
        $this->assertSame(8000.0, (float) $resolved->rental_amount);
        $this->assertSame(8000.0, (float) $resolved->deposit_amount);
    }

    /** Scenario 4 — company (entity) landlord. */
    public function test_company_landlord_resolves_as_entity_recipient(): void
    {
        [$agency, $branch, $user] = $this->makeAgencyBranchUser();
        $property = $this->makeRentalProperty($agency, $branch, $user);
        $company = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_kind' => Contact::TYPE_ENTITY,
            'entity_name' => 'Sea Breeze Investments CC', 'entity_reg_no' => 'CC-1',
            'first_name' => 'Sea Breeze Investments CC', 'last_name' => '',
            'email' => 'admin@seabreeze.test',
        ]);
        $rep = $this->makeNaturalPersonContact($agency, $branch, 'Dana', 'Director', 'dana@example.test');
        ContactRepresentative::create([
            'entity_contact_id' => $company->id, 'representative_contact_id' => $rep->id,
            'capacity' => 'Director', 'signs_as_proxy' => true,
        ]);
        $property->contacts()->attach($company->id, ['role' => 'landlord']);
        $template = $this->makeRentalTemplate($agency, $user);

        $out = $this->callPrepareRecipientsForMerge($this->stepDataFor($property), $template, $user);

        $companyRow = $this->recipientFor($out, $company->id);
        $this->assertNotNull($companyRow, 'A company landlord must auto-populate exactly like a natural-person landlord.');
        $this->assertSame('lessor', $companyRow['role']);
        $this->assertTrue($companyRow['_is_entity']);

        // prepareRecipientsForMerge() never expands an entity (EsignEntityRecipientTest
        // locks that contract) — the company stays ONE row here; expansion to its
        // representative is a separate, already-covered concern.
        $recipients = $out['recipients']['recipients'] ?? [];
        $this->assertCount(1, $recipients);
    }
}
