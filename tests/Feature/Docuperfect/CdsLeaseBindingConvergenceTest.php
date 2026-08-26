<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Agency;
use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;
use App\Models\User;
use App\Services\Docuperfect\CdsBindingSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-177 (lease context) — the "{Party} - {Attribute}" importer must bind a LEASE's Lessor/Lessee
 * tokens to the LESSOR / LESSEE named fields (contact rows carrying source_contact_type Lessor /
 * Lessee), NOT collapse them onto a document/computed field (e.g. an amount-in-words) or a generic
 * contact field with no role. CdsImportBindingConvergenceTest already pins the SALE (Seller) side;
 * this file pins the rental side and the Lessor/Lessee party-key mapping (owner vs acquiring).
 *
 *   - Lessor identity token        → the Lessor field group (name + surname + ID), party owner_party
 *   - Lessor address / ID / phone  → the Lessor-typed named field for that column, party owner_party
 *   - Lessee tokens                → the Lessee-typed named field, party acquiring_party
 *   - landlord_/tenant_ aliases    → resolve to Lessor / Lessee just like the canonical prefixes
 */
final class CdsLeaseBindingConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private int $lessorFgId;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'trading_name' => 'Home Finders Coastal']);
        $this->agencyId = $agency->id;
        $creator = User::factory()->create(['agency_id' => $agency->id, 'role' => 'super_admin']);

        // Lessor identity members (drive the field-group resolution) + attribute fields.
        $lFirst = NamedField::create(['name' => 'Lessor First Name', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'first_name', 'source_contact_type' => 'Lessor']);
        $lLast  = NamedField::create(['name' => 'Lessor Last Name', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'last_name', 'source_contact_type' => 'Lessor']);
        $lId    = NamedField::create(['name' => 'Lessor Id Number', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'id_number', 'source_contact_type' => 'Lessor']);
        NamedField::create(['name' => 'Lessor Address', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'address', 'source_contact_type' => 'Lessor']);
        NamedField::create(['name' => 'Lessor Phone', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'phone', 'source_contact_type' => 'Lessor']);

        // Lessee attribute fields.
        NamedField::create(['name' => 'Lessee First Name', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'first_name', 'source_contact_type' => 'Lessee']);
        NamedField::create(['name' => 'Lessee Last Name', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'last_name', 'source_contact_type' => 'Lessee']);
        NamedField::create(['name' => 'Lessee Id Number', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'id_number', 'source_contact_type' => 'Lessee']);
        NamedField::create(['name' => 'Lessee Address', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'address', 'source_contact_type' => 'Lessee']);

        // A decoy that MUST NOT win a Lessor token: a computed amount-in-words document field.
        NamedField::create(['name' => 'Rent[words]', 'field_type' => 'text', 'source_type' => 'computed', 'source_column' => 'price_in_words']);

        $fg = FieldGroup::create([
            'agency_id' => $this->agencyId,
            'created_by' => $creator->id,
            'name' => 'Lessor full',
            'fields' => [
                ['named_field_id' => $lFirst->id],
                ['named_field_id' => $lLast->id],
                ['named_field_id' => $lId->id],
            ],
            'layout' => 'vertical',
            'is_global' => true,
        ]);
        $this->lessorFgId = $fg->id;
    }

    private function ph(string $blockId, string $label): array
    {
        return ['type' => 'insertable_block_placeholder', 'purpose' => 'custom_named', 'block_id' => $blockId, 'raw_token' => $label, 'custom_label' => $label];
    }

    private function para(array $content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }

    public function test_lessor_identity_token_binds_to_lessor_field_group_owner_party(): void
    {
        $cds = ['sections' => [$this->para([$this->ph('lessor_full_name_and_surname', 'Lessor - Full name and surname')])]];
        $b = (new CdsBindingSuggester($this->agencyId))->suggest($cds)['bindings'][0];

        $this->assertSame('field_group', $b['mappingType']);
        $this->assertSame($this->lessorFgId, $b['fieldGroupId']);
        $this->assertSame('fg:' . $this->lessorFgId, $b['typeKey']);
        $this->assertSame('owner_party', $b['party'], 'the Lessor is the owner-side party');
        $this->assertSame('Lessor', $b['sourceContactType']);
    }

    public function test_lessor_attribute_tokens_bind_to_lessor_columns_not_generic_or_document(): void
    {
        $cds = ['sections' => [$this->para([
            $this->ph('lessor_physical_address', 'Lessor - Physical address'),
            $this->ph('lessor_id_number', 'Lessor - ID number'),
        ])]];
        $b = (new CdsBindingSuggester($this->agencyId))->suggest($cds)['bindings'];

        // address
        $this->assertSame('sf:contact_lessor', $b[0]['typeKey']);
        $this->assertSame('owner_party', $b[0]['party']);
        $addr = NamedField::find($b[0]['namedFieldId']);
        $this->assertSame('address', $addr->source_column);
        $this->assertSame('Lessor', $addr->source_contact_type, 'address must bind to the LESSOR contact field, not a generic/roleless one');
        $this->assertNotSame('computed', $addr->source_type, 'must never collapse to the amount-in-words computed decoy');

        // id number
        $this->assertSame('sf:contact_lessor', $b[1]['typeKey']);
        $idnf = NamedField::find($b[1]['namedFieldId']);
        $this->assertSame('id_number', $idnf->source_column);
        $this->assertSame('Lessor', $idnf->source_contact_type);
    }

    public function test_lessee_tokens_bind_to_lessee_columns_acquiring_party(): void
    {
        $cds = ['sections' => [$this->para([
            $this->ph('lessee_physical_address', 'Lessee - Physical address'),
            $this->ph('lessee_id_number', 'Lessee - ID number'),
        ])]];
        $b = (new CdsBindingSuggester($this->agencyId))->suggest($cds)['bindings'];

        $this->assertSame('sf:contact_lessee', $b[0]['typeKey']);
        $this->assertSame('acquiring_party', $b[0]['party'], 'the Lessee is the acquiring-side party');
        $this->assertSame('Lessee', NamedField::find($b[0]['namedFieldId'])->source_contact_type);

        $this->assertSame('sf:contact_lessee', $b[1]['typeKey']);
        $this->assertSame('id_number', NamedField::find($b[1]['namedFieldId'])->source_column);
        $this->assertSame('Lessee', NamedField::find($b[1]['namedFieldId'])->source_contact_type);
    }

    /** landlord_/tenant_ are honoured as aliases for Lessor/Lessee (splitPartyAttribute map). */
    public function test_landlord_and_tenant_aliases_resolve_to_lessor_and_lessee(): void
    {
        $cds = ['sections' => [$this->para([
            $this->ph('landlord_physical_address', 'Landlord - Physical address'),
            $this->ph('tenant_physical_address', 'Tenant - Physical address'),
        ])]];
        $b = (new CdsBindingSuggester($this->agencyId))->suggest($cds)['bindings'];

        $this->assertSame('sf:contact_lessor', $b[0]['typeKey']);
        $this->assertSame('owner_party', $b[0]['party']);
        $this->assertSame('sf:contact_lessee', $b[1]['typeKey']);
        $this->assertSame('acquiring_party', $b[1]['party']);
    }

    /** With Lessor/Lessee tokens present, the owner-side Lessor wins the primary role. */
    public function test_primary_role_is_lessor_for_a_lease(): void
    {
        $cds = ['sections' => [$this->para([
            $this->ph('lessor_physical_address', 'Lessor - Physical address'),
            $this->ph('lessee_physical_address', 'Lessee - Physical address'),
        ])]];
        $this->assertSame('Lessor', (new CdsBindingSuggester($this->agencyId))->suggest($cds)['primary_role']);
    }
}
