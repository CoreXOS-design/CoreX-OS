<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DERIVED "company properties" (property → company → director). A director sees
 * properties owned by companies they direct, flagged "via {Company}", DISTINCT
 * from properties they own personally. Data-relationship only (no ownership on
 * the director).
 */
final class CompanyPropertiesViaDirectorshipTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->user = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->actingAs($this->user);
    }

    private function property(string $title): Property
    {
        return Property::create([
            'external_id' => (string) Str::uuid(), 'title' => $title,
            'agent_id' => $this->user->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]);
    }

    public function test_director_sees_company_properties_flagged_via_company(): void
    {
        $company = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => '1502 BEAUMONT PROP CC',
            'entity_reg_no' => '201001792823', 'first_name' => '1502 BEAUMONT PROP CC', 'last_name' => '', 'phone' => '',
        ]);
        $director = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Hendrik', 'last_name' => 'Pretorius',
            'phone' => '', 'id_number' => '7004065141082',
        ]);
        ContactRepresentative::create(['entity_contact_id' => $company->id, 'representative_contact_id' => $director->id, 'is_primary' => true]);

        // Company owns a promoted Property (contact_property role=owner).
        $companyProp = $this->property('Company Promoted Property');
        $company->properties()->attach($companyProp->id, ['role' => 'owner']);

        // Company owns an un-promoted tracked property.
        $tpId = (int) DB::table('tracked_properties')->insertGetId([
            'agency_id' => $this->agencyId, 'external_id' => (string) Str::uuid(),
            'street_number' => '1502', 'street_name' => 'Beaumont Drive', 'capture_kind' => 'deeds_capture',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tracked_property_owners')->insert([
            'tracked_property_id' => $tpId, 'contact_id' => $company->id, 'name' => '1502 BEAUMONT PROP CC',
            'id_number' => '201001792823', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The director ALSO owns a property PERSONALLY — must NOT be flagged as a company property.
        $ownProp = $this->property('Personal Property');
        $director->properties()->attach($ownProp->id, ['role' => 'owner']);

        $derived = $director->companyPropertiesViaDirectorship();

        // Both company-owned properties present, flagged via the company.
        $this->assertCount(2, $derived);
        $flags = $derived->pluck('flag')->unique()->values();
        $this->assertSame(['Company property · via 1502 BEAUMONT PROP CC'], $flags->all());
        $kinds = $derived->pluck('kind')->sort()->values()->all();
        $this->assertSame(['property', 'tracked_property'], $kinds);

        // The personally-owned property is NOT in the derived company list.
        $derivedPropertyIds = $derived->where('kind', 'property')->pluck('property.id')->all();
        $this->assertContains($companyProp->id, $derivedPropertyIds);
        $this->assertNotContains($ownProp->id, $derivedPropertyIds, 'personal ownership is not a company property');
    }

    public function test_no_directorship_returns_empty(): void
    {
        $person = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Solo', 'last_name' => 'Owner', 'phone' => '',
        ]);
        $this->assertCount(0, $person->companyPropertiesViaDirectorship());
    }
}
